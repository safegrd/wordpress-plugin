<?php
/**
 * Dumps the site's tables in the shape mysqldump writes, so the safegrd CLI
 * restores them with the mysql client and counts their rows the same way:
 * CREATE TABLE on a line of its own, rows as INSERT INTO `t` VALUES (...),(...);
 * binary values as hex, and "-- Dump completed" as the last line.
 *
 * The dump is files of the run: mysql/dump.sql holds the header, each table
 * is a part of its own (mysql/dump.sql.1, .2, ...), or several when it is
 * read across requests, and the last part holds the footer. Readers join the
 * parts in order. A table whose rows did not change is the same bytes as last
 * time, so it uploads nothing.
 *
 * Every table is read inside one consistent snapshot (InnoDB), with an
 * unbuffered query, so memory does not grow with the table. When the host
 * stops a request before the whole dump is read, run_split() reads it table
 * by table across requests instead: each request in a consistent snapshot of
 * its own, a large table in pages by its primary key, and each part ending at
 * the end of a statement, so every part is whole when its request ends.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Dumper {
	/** One INSERT statement grows to about this many bytes. */
	const STATEMENT_BYTES = 1048576;
	/** Rows in one page of a table read across requests, at first. */
	const PAGE_ROWS = 2000;
	/** @var mysqli */
	private $db;
	/** @var SafeGrd_Backup */
	private $out;
	private $prefix;
	private $part = 0;
	private $tables = array();
	private $skipped = array();
	private $database = '';
	private $server_version = '';
	private $charset = 'utf8mb4';
	private $attachments = 0;

	public function __construct( SafeGrd_Backup $out ) {
		global $wpdb;
		$this->out    = $out;
		$this->prefix = $wpdb->prefix;
		$this->db     = self::connect();
	}

	/**
	 * A connection of the dump's own. WordPress's connection stays free for
	 * the uploads that run while a table is still being read, and the
	 * snapshot's transaction and time zone never touch it.
	 */
	public static function connect() {
		global $wpdb;
		if ( ! class_exists( 'mysqli' ) ) {
			throw new SafeGrd_Exception( esc_html( 'PHP on this host has no mysqli extension, so the plugin cannot read the database.' ), 'source' );
		}
		$host   = DB_HOST;
		$port   = null;
		$socket = null;
		if ( method_exists( $wpdb, 'parse_db_host' ) ) {
			$parsed = $wpdb->parse_db_host( DB_HOST );
			if ( $parsed ) {
				list( $host, $port, $socket, $is_ipv6 ) = $parsed;
				if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
					$host = "[$host]";
				}
			}
		}
		// A connection of its own, not $wpdb: the dump reads every table in one
		// consistent snapshot and streams large tables row by row
		// (MYSQLI_USE_RESULT), and $wpdb buffers each result in memory.
		$db = mysqli_init(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init
		$db->options( MYSQLI_OPT_CONNECT_TIMEOUT, 30 );
		$flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;
		mysqli_report( MYSQLI_REPORT_OFF ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- errors are read from the connection.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the error is read from connect_error below.
		$ok = @$db->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : null, $socket, $flags );
		if ( ! $ok ) {
			throw new SafeGrd_Exception( esc_html( 'Could not open a connection to the database for the dump: ' . $db->connect_error ), 'source' );
		}
		$charset = $wpdb->charset ? $wpdb->charset : 'utf8mb4';
		if ( ! $db->set_charset( $charset ) ) {
			$db->set_charset( 'utf8' );
		}
		return $db;
	}

	/**
	 * Writes the dump into the archive.
	 *
	 * @return array{database:string,server_version:string,tables:array,skipped:array,attachments:int}
	 */
	public function run() {
		$this->database       = (string) $this->scalar( 'SELECT DATABASE()' );
		$this->server_version = (string) $this->scalar( 'SELECT VERSION()' );
		$cs                   = $this->db->character_set_name();
		if ( $cs ) {
			$this->charset = $cs;
		}

		$this->exec( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->exec( "SET SESSION time_zone = '+00:00'" );
		$this->exec( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		try {
			$names = $this->table_names();
			$sizes = $this->table_sizes();
			$this->next_part();
			$this->header();
			foreach ( $names as $name ) {
				$this->next_part();
				$rows                  = $this->dump_table( $name );
				$this->tables[ $name ] = array(
					'rows' => $rows,
					'size' => isset( $sizes[ $name ] ) ? $sizes[ $name ] : 0,
				);
			}
			$this->attachments = $this->attachments();
			$this->next_part();
			$this->footer();
			$this->out->end_file();
		} finally {
			$this->db->query( 'COMMIT' );
			$this->db->close();
		}
		return array(
			'database'       => $this->database,
			'server_version' => $this->server_version,
			'tables'         => $this->tables,
			'skipped'        => $this->skipped,
			'attachments'    => $this->attachments,
		);
	}

	/**
	 * A dump to be read table by table across requests, from the start.
	 *
	 * @return array The state run_split() takes and returns.
	 */
	public static function split_state() {
		return array(
			'mode'    => 'split',
			'part'    => 0,
			'names'   => null,
			'sizes'   => array(),
			'i'       => 0,
			'table'   => null,
			'batch'   => self::PAGE_ROWS,
			'tables'  => array(),
			'skipped' => array(),
			'reads'   => array(),
			'charset' => '',
			'done'    => false,
		);
	}

	/**
	 * Reads on from where $s says, in one consistent snapshot of this
	 * request, until $out_of_time() says the slice is up. A table is read in
	 * pages of $s['batch'] rows by its primary key (WHERE key > last ORDER BY
	 * key), never by offset, which skips or repeats a row that moves between
	 * pages. A table with no primary key is read whole, at the start of a
	 * request. Each part ends at the end of a statement, and $save receives
	 * the state as of the last part ended, so a request the host stops is
	 * read again from there.
	 *
	 * @param array    $s           The state from split_state() or the last request.
	 * @param callable $out_of_time Whether this request's slice is up.
	 * @param callable $save        Receives the state after each part.
	 * @return array The state; done once the footer is written.
	 */
	public function run_split( array $s, callable $out_of_time, callable $save ) {
		$this->exec( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->exec( "SET SESSION time_zone = '+00:00'" );
		$this->exec( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		$n            = count( $s['reads'] );
		$s['reads'][] = array(
			'request' => $n + 1,
			'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'tables'  => array(),
		);
		try {
			if ( null === $s['names'] ) {
				$this->database       = (string) $this->scalar( 'SELECT DATABASE()' );
				$this->server_version = (string) $this->scalar( 'SELECT VERSION()' );
				$s['database']        = $this->database;
				$s['server_version']  = $this->server_version;
				$cs                  = $this->db->character_set_name();
				$this->charset       = $cs ? $cs : $this->charset;
				$s['charset']        = $this->charset;
				$s['names']          = $this->table_names();
				$s['sizes']          = $this->table_sizes();
				$s['skipped']        = $this->skipped;
				$this->part          = $s['part'];
				$this->next_part();
				$this->header();
				$this->out->end_file();
				$s['part'] = $this->part;
				$save( $s );
			}
			$this->charset = '' !== $s['charset'] ? $s['charset'] : $this->charset;
			$first         = true;
			while ( $s['i'] < count( $s['names'] ) ) {
				$name = $s['names'][ $s['i'] ];
				if ( null === $s['table'] ) {
					$s['table'] = array(
						'started' => false,
						'key'     => $this->primary_key( $name ),
						'after'   => null,
						'rows'    => 0,
					);
				}
				$t = $s['table'];
				if ( ! $t['key'] && ! $first ) {
					break; // read whole, so at the start of a request of its own
				}
				$first = false;
				$this->out->begin_file( self::part_name( $s['part'] ) );
				if ( ! $t['started'] ) {
					$this->structure( $name );
					$t['started'] = true;
				}
				$from = $t['after'];
				$had  = $t['rows'];
				if ( $t['key'] ) {
					$done = $this->dump_pages( $name, $t, max( 1, (int) $s['batch'] ), $out_of_time );
				} else {
					$t['rows'] = $this->dump_rows( $name );
					$done      = true;
				}
				if ( $done ) {
					$this->emit( "\n" );
				}
				$this->out->end_file();
				++$s['part'];
				$s['reads'][ $n ]['tables'][] = array(
					'table' => $name,
					'from'  => null === $from ? null : self::shorten( implode( ', ', $from ) ),
					'to'    => $done || null === $t['after'] ? null : self::shorten( implode( ', ', $t['after'] ) ),
					'rows'  => $t['rows'] - $had,
				);
				if ( $done ) {
					$s['tables'][ $name ] = array(
						'rows' => $t['rows'],
						'size' => isset( $s['sizes'][ $name ] ) ? $s['sizes'][ $name ] : 0,
					);
					++$s['i'];
					$s['table'] = null;
				} else {
					$s['table'] = $t;
				}
				$save( $s );
				if ( $out_of_time() ) {
					break;
				}
			}
			if ( $s['i'] >= count( $s['names'] ) ) {
				$s['attachments'] = $this->attachments();
				$this->out->begin_file( self::part_name( $s['part'] ) );
				$this->footer();
				$this->out->end_file();
				++$s['part'];
				$s['done'] = true;
				$save( $s );
			}
		} finally {
			$this->db->query( 'COMMIT' );
			$this->db->close();
		}
		return $s;
	}

	/** A key in the manifest's account of what each request read, cut short. */
	private static function shorten( $s ) {
		return strlen( $s ) > 120 ? substr( $s, 0, 117 ) . '...' : $s;
	}

	/** The name of the dump's part $n. Parts are numbered without a gap. */
	private static function part_name( $n ) {
		return 0 === $n ? 'mysql/dump.sql' : 'mysql/dump.sql.' . $n;
	}

	/**
	 * The columns of $name's primary key, in key order, or an empty list
	 * when it has none, or one the dump cannot read back (a generated column).
	 */
	private function primary_key( $name ) {
		$res  = $this->query( 'SHOW KEYS FROM ' . $this->quote_name( $name ) . " WHERE Key_name = 'PRIMARY'" );
		$cols = array();
		while ( $row = $res->fetch_assoc() ) {
			$cols[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		$res->free();
		ksort( $cols );
		$select = $this->select_of( $name );
		foreach ( $cols as $c ) {
			if ( ! in_array( $c, $select['columns'], true ) ) {
				return array();
			}
		}
		return array_values( $cols );
	}

	private function header() {
		$this->emit( "-- SafeGrd WordPress dump\n" );
		$this->emit( '-- Database: ' . $this->database . "\n" );
		$this->emit( '-- Server version: ' . $this->server_version . "\n\n" );
		$this->emit( '/*!40101 SET NAMES ' . $this->charset . " */;\n" );
		$this->emit( "/*!40103 SET TIME_ZONE='+00:00' */;\n" );
		$this->emit( "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n" );
		$this->emit( "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n" );
		$this->emit( "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n" );
	}

	private function footer() {
		$this->emit( "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" );
		$this->emit( "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n" );
		$this->emit( "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n\n" );
		$this->emit( "-- Dump completed\n" );
	}

	private function attachments() {
		return (int) $this->scalar(
			'SELECT COUNT(*) FROM ' . $this->quote_name( $this->prefix . 'postmeta' ) . " WHERE meta_key = '_wp_attached_file'"
		);
	}

	/**
	 * The site's base tables: those whose name starts with $table_prefix.
	 * Views are listed as skipped.
	 */
	private function table_names() {
		$like = $this->db->real_escape_string( $this->like_escape( $this->prefix ) ) . '%';
		$res  = $this->query( "SHOW FULL TABLES LIKE '" . $like . "'" );
		$out  = array();
		while ( $row = $res->fetch_row() ) {
			if ( 0 === strpos( $row[0], $this->prefix . 'safegrd_' ) ) {
				continue; // this plugin's own record of what is stored
			}
			if ( 'BASE TABLE' === $row[1] ) {
				$out[] = $row[0];
			} else {
				$this->skipped[] = $row[0] . ' (a view: its definition is not backed up)';
			}
		}
		$res->free();
		sort( $out, SORT_STRING );
		// Another install whose prefix starts with this site's (wp_shop_ beside
		// wp_) matches the LIKE above. Its tables are not this site's, and a
		// restore of this site would replace them.
		$others = self::other_installs( $out, $this->prefix );
		if ( $others ) {
			$mine = array();
			$left = array_fill_keys( $others, 0 );
			foreach ( $out as $name ) {
				$owner = '';
				foreach ( $others as $p ) {
					if ( 0 === strpos( $name, $p ) && strlen( $p ) > strlen( $owner ) ) {
						$owner = $p;
					}
				}
				if ( '' === $owner ) {
					$mine[] = $name;
				} else {
					$left[ $owner ]++;
				}
			}
			foreach ( $left as $p => $n ) {
				$this->skipped[] = sprintf( '%s* (%d tables of another WordPress install in this database)', $p, $n );
			}
			$out = $mine;
		}
		$res = $this->db->query( "SHOW TRIGGERS LIKE '" . $like . "'" );
		if ( $res instanceof mysqli_result ) {
			$tables = array_flip( $out );
			while ( $row = $res->fetch_assoc() ) {
				if ( isset( $tables[ $row['Table'] ] ) ) {
					$this->skipped[] = sprintf( 'trigger %s on %s (triggers are not backed up)', $row['Trigger'], $row['Table'] );
				}
			}
			$res->free();
		}
		return $out;
	}

	/**
	 * The table prefixes of other WordPress installs in this database whose
	 * prefix starts with $prefix: each has its own options, posts, users and
	 * usermeta tables. A plugin's table that happens to end in "options" has
	 * no users table beside it.
	 *
	 * @param array  $names  Base tables whose name starts with $prefix.
	 * @param string $prefix This site's table prefix.
	 * @return array
	 */
	public static function other_installs( array $names, $prefix ) {
		$have = array_flip( $names );
		$out  = array();
		foreach ( $names as $name ) {
			if ( 'options' !== substr( $name, -7 ) ) {
				continue;
			}
			$p = substr( $name, 0, -7 );
			if ( $p !== $prefix && 0 === strpos( $p, $prefix ) && isset( $have[ $p . 'posts' ], $have[ $p . 'users' ], $have[ $p . 'usermeta' ] ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private function table_sizes() {
		$out = array();
		$res = $this->db->query(
			"SELECT table_name, COALESCE(data_length, 0) + COALESCE(index_length, 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
		);
		if ( $res instanceof mysqli_result ) {
			while ( $row = $res->fetch_row() ) {
				$out[ $row[0] ] = (int) $row[1];
			}
			$res->free();
		}
		return $out;
	}

	private function dump_table( $name ) {
		$this->structure( $name );
		$rows = $this->dump_rows( $name );
		$this->emit( "\n" );
		return $rows;
	}

	/** The table's DROP and CREATE statements. */
	private function structure( $name ) {
		$qname  = $this->quote_name( $name );
		$create = $this->query( 'SHOW CREATE TABLE ' . $qname )->fetch_row();
		if ( empty( $create[1] ) ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'Could not read the definition of %s.', $name ) ), 'source' );
		}
		$this->emit( "--\n-- Table structure for table " . $qname . "\n--\n\n" );
		$this->emit( 'DROP TABLE IF EXISTS ' . $qname . ";\n" );
		$this->emit( $create[1] . ";\n\n" );
	}

	/**
	 * What the dump reads of a table: the select list, the INSERT that
	 * writes its rows, the condition on them, and the columns read.
	 *
	 * @return array{select:string,head:string,where:string,columns:array}
	 */
	private function select_of( $name ) {
		$qname = $this->quote_name( $name );
		// Generated columns cannot be inserted into; they are listed out and
		// recomputed by the server on restore.
		$columns   = array();
		$generated = false;
		$res       = $this->query( 'SHOW COLUMNS FROM ' . $qname );
		while ( $col = $res->fetch_assoc() ) {
			if ( false !== stripos( (string) $col['Extra'], 'GENERATED' ) ) {
				$generated = true;
				continue;
			}
			$columns[] = $col['Field'];
		}
		$res->free();
		$select = $generated ? implode( ', ', array_map( array( $this, 'quote_name' ), $columns ) ) : '*';
		$where  = '';
		if ( $name === $this->prefix . 'options' ) {
			// This plugin's own rows stay out: a restored site must not come
			// back holding this site's node token.
			$where = "option_name NOT LIKE 'safegrd\\_%' AND option_name NOT LIKE '\\_transient\\_safegrd\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_safegrd\\_%'";
		}
		return array(
			'select'  => $select,
			'head'    => 'INSERT INTO ' . $qname . ( $generated ? ' (' . $select . ')' : '' ) . ' VALUES ',
			'where'   => $where,
			'columns' => $columns,
		);
	}

	/** Writes every row of a table, as one stream. */
	private function dump_rows( $name ) {
		$q = $this->select_of( $name );
		return $this->stream( $name, 'SELECT ' . $q['select'] . ' FROM ' . $this->quote_name( $name ) . ( '' === $q['where'] ? '' : ' WHERE ' . $q['where'] ), $q['head'] );
	}

	/**
	 * Writes a table's rows a page at a time, by its primary key, from the
	 * key after $t['after'], until the table ends or the slice is up. A page
	 * is read whole, so the time it takes is what $batch bounds.
	 *
	 * @param string   $name        The table.
	 * @param array    $t           Its state: key, after and rows, updated.
	 * @param int      $batch       Rows a page holds.
	 * @param callable $out_of_time Whether the slice is up.
	 * @return bool Whether the table is done.
	 */
	private function dump_pages( $name, array &$t, $batch, callable $out_of_time ) {
		$q     = $this->select_of( $name );
		$keys  = array_map( array( $this, 'quote_name' ), $t['key'] );
		$tuple = count( $keys ) > 1 ? '(' . implode( ', ', $keys ) . ')' : $keys[0];
		$at    = array();
		foreach ( $t['key'] as $k ) {
			$at[] = array_search( $k, $q['columns'], true );
		}
		while ( true ) {
			$where = array();
			if ( '' !== $q['where'] ) {
				$where[] = $q['where'];
			}
			if ( null !== $t['after'] ) {
				$where[] = $tuple . ' > ' . ( count( $keys ) > 1 ? '(' . implode( ', ', $t['after'] ) . ')' : $t['after'][0] );
			}
			$sql   = 'SELECT ' . $q['select'] . ' FROM ' . $this->quote_name( $name ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) .
				' ORDER BY ' . implode( ', ', $keys ) . ' LIMIT ' . (int) $batch;
			$last  = null;
			$rows  = $this->stream( $name, $sql, $q['head'], $at, $last );
			$t['rows'] += $rows;
			if ( null !== $last ) {
				$t['after'] = $last;
			}
			if ( $rows < $batch ) {
				return true;
			}
			if ( $out_of_time() ) {
				return false;
			}
		}
	}

	/**
	 * Runs $sql unbuffered and writes its rows as INSERT statements of about
	 * STATEMENT_BYTES each. $at names the key's columns by position, and
	 * $last receives the last row's key as SQL literals.
	 */
	private function stream( $name, $sql, $head, array $at = array(), &$last = null ) {
		$res = $this->db->query( $sql, MYSQLI_USE_RESULT );
		if ( false === $res ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'Could not read %s: %s', $name, $this->db->error ) ), 'source' );
		}
		$kinds = array();
		foreach ( $res->fetch_fields() as $f ) {
			$kinds[] = $this->kind( $f );
		}
		$rows = 0;
		$stmt = '';
		while ( $row = $res->fetch_row() ) {
			$values = array();
			foreach ( $row as $i => $v ) {
				$values[] = $this->value( $v, $kinds[ $i ] );
			}
			if ( $at ) {
				$last = array();
				foreach ( $at as $i ) {
					$last[] = $values[ $i ];
				}
			}
			$tuple = '(' . implode( ',', $values ) . ')';
			if ( '' === $stmt ) {
				$stmt = $head . $tuple;
			} else {
				$stmt .= ',' . $tuple;
			}
			$rows++;
			if ( strlen( $stmt ) >= self::STATEMENT_BYTES ) {
				$this->emit( $stmt . ";\n" );
				$stmt = '';
			}
		}
		$err = $this->db->errno ? $this->db->error : '';
		$res->free();
		if ( '' !== $err ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'Reading %s stopped part way: %s', $name, $err ) ), 'source' );
		}
		if ( '' !== $stmt ) {
			$this->emit( $stmt . ";\n" );
		}
		return $rows;
	}

	/**
	 * How a column's values are written: as a number, as hex, or quoted.
	 */
	private function kind( $field ) {
		$numeric = array( MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_YEAR );
		if ( in_array( $field->type, $numeric, true ) ) {
			return 'number';
		}
		if ( MYSQLI_TYPE_BIT === $field->type || MYSQLI_TYPE_GEOMETRY === $field->type ) {
			return 'hex';
		}
		$stringish = array( MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB );
		if ( in_array( $field->type, $stringish, true ) && 63 === (int) $field->charsetnr ) {
			return 'hex'; // the binary character set: BINARY, VARBINARY, BLOB
		}
		return 'string';
	}

	private function value( $v, $kind ) {
		if ( null === $v ) {
			return 'NULL';
		}
		switch ( $kind ) {
			case 'number':
				return '' === $v ? "''" : $v;
			case 'hex':
				return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . $this->db->real_escape_string( $v ) . "'";
	}

	/**
	 * Ends the part being written and starts the next. Parts are numbered
	 * without a gap, as the reader requires.
	 */
	private function next_part() {
		if ( $this->part > 0 ) {
			$this->out->end_file();
		}
		$this->out->begin_file( self::part_name( $this->part ) );
		$this->part++;
	}

	private function emit( $sql ) {
		$this->out->write( $sql );
	}

	private function quote_name( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private function like_escape( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	private function exec( $sql ) {
		if ( false === $this->db->query( $sql ) ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'The database refused %s: %s', $sql, $this->db->error ) ), 'source' );
		}
	}

	private function query( $sql ) {
		$res = $this->db->query( $sql );
		if ( ! ( $res instanceof mysqli_result ) ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'The database refused %s: %s', $sql, $this->db->error ) ), 'source' );
		}
		return $res;
	}

	private function scalar( $sql ) {
		$row = $this->query( $sql )->fetch_row();
		return $row ? $row[0] : null;
	}
}
