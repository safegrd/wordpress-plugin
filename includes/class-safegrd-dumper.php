<?php
/**
 * Dumps the site's tables in the shape mysqldump writes, so the safegrd CLI
 * restores them with the mysql client and counts their rows the same way:
 * CREATE TABLE on a line of its own, rows as INSERT INTO `t` VALUES (...),(...);
 * binary values as hex, and "-- Dump completed" as the last line.
 *
 * The dump is files of the run: mysql/dump.sql holds the header, each table
 * is a part of its own (mysql/dump.sql.1, .2, ...), and the last part holds
 * the footer. A table whose rows did not change is the same bytes as last
 * time, so it uploads nothing.
 *
 * Every table is read inside one consistent snapshot (InnoDB), with an
 * unbuffered query, so memory does not grow with the table.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Dumper {
	/** One INSERT statement grows to about this many bytes. */
	const STATEMENT_BYTES = 1048576;
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
			$this->emit( "-- SafeGrd WordPress dump\n" );
			$this->emit( '-- Database: ' . $this->database . "\n" );
			$this->emit( '-- Server version: ' . $this->server_version . "\n\n" );
			$this->emit( '/*!40101 SET NAMES ' . $this->charset . " */;\n" );
			$this->emit( "/*!40103 SET TIME_ZONE='+00:00' */;\n" );
			$this->emit( "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n" );
			$this->emit( "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n" );
			$this->emit( "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n" );
			foreach ( $names as $name ) {
				$this->next_part();
				$rows                  = $this->dump_table( $name );
				$this->tables[ $name ] = array(
					'rows' => $rows,
					'size' => isset( $sizes[ $name ] ) ? $sizes[ $name ] : 0,
				);
			}
			$this->attachments = (int) $this->scalar(
				'SELECT COUNT(*) FROM ' . $this->quote_name( $this->prefix . 'postmeta' ) . " WHERE meta_key = '_wp_attached_file'"
			);
			$this->next_part();
			$this->emit( "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" );
			$this->emit( "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n" );
			$this->emit( "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n\n" );
			$this->emit( "-- Dump completed\n" );
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
		$qname  = $this->quote_name( $name );
		$create = $this->query( 'SHOW CREATE TABLE ' . $qname )->fetch_row();
		if ( empty( $create[1] ) ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'Could not read the definition of %s.', $name ) ), 'source' );
		}
		$this->emit( "--\n-- Table structure for table " . $qname . "\n--\n\n" );
		$this->emit( 'DROP TABLE IF EXISTS ' . $qname . ";\n" );
		$this->emit( $create[1] . ";\n\n" );

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
		$head   = 'INSERT INTO ' . $qname . ( $generated ? ' (' . $select . ')' : '' ) . ' VALUES ';

		$where = '';
		if ( $name === $this->prefix . 'options' ) {
			// This plugin's own rows stay out: a restored site must not come
			// back holding this site's node token.
			$where = " WHERE option_name NOT LIKE 'safegrd\\_%' AND option_name NOT LIKE '\\_transient\\_safegrd\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_safegrd\\_%'";
		}

		$res = $this->db->query( 'SELECT ' . $select . ' FROM ' . $qname . $where, MYSQLI_USE_RESULT );
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
		$this->emit( "\n" );
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
		$this->out->begin_file( 0 === $this->part ? 'mysql/dump.sql' : 'mysql/dump.sql.' . $this->part );
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
