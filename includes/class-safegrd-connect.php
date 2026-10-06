<?php
/**
 * Connects the site to a SafeGrd account: a device sign-in in the browser
 * (or a personal access token from WP-CLI), then registration of the site
 * as a node with its own token. The personal token is used once and never
 * stored.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Connect {
	const SESSION = 'safegrd_connect_session';

	/**
	 * Starts a device sign-in.
	 *
	 * @param string $custody 'safegrd' (SafeGrd-managed key) or 'local' (customer-managed key).
	 * @return array|WP_Error {session_id, user_code, approve_url}
	 */
	public static function start( $custody ) {
		$base = SafeGrd_Settings::server_url();
		$ok   = SafeGrd_Client::check_url( $base );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$client = new SafeGrd_Client( $base );
		$s      = $client->call( 'POST', '/api/v1/auth/cli/session', null, 15 );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		if ( empty( $s['session_id'] ) || empty( $s['user_code'] ) ) {
			return new WP_Error( 'safegrd_session', 'The server did not start a sign-in.' );
		}
		$session = array(
			'session_id' => $s['session_id'],
			'user_code'  => $s['user_code'],
			'custody'    => 'local' === $custody ? 'local' : 'safegrd',
		);
		set_transient( self::SESSION, $session, 10 * MINUTE_IN_SECONDS );
		$session['approve_url'] = self::approve_url( $base, $session );
		return $session;
	}

	public static function approve_url( $base, array $session ) {
		return $base . '/auth/cli?' . http_build_query(
			array(
				'session' => $session['session_id'],
				'code'    => $session['user_code'],
				'client'  => 'wordpress',
				'site'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Asks whether the sign-in was approved, and once it was, registers the
	 * site.
	 *
	 * @return array|WP_Error {status: pending|connected, ...}
	 */
	public static function poll() {
		$session = get_transient( self::SESSION );
		if ( ! is_array( $session ) ) {
			return new WP_Error( 'safegrd_expired', 'The sign-in expired after 10 minutes. Press Connect again.' );
		}
		$client = new SafeGrd_Client( SafeGrd_Settings::server_url() );
		$s      = $client->call( 'GET', '/api/v1/auth/cli/session?session_id=' . rawurlencode( $session['session_id'] ), null, 15 );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		if ( empty( $s['token'] ) || ( isset( $s['status'] ) && 'authorized' !== $s['status'] ) ) {
			return array(
				'status'    => 'pending',
				'user_code' => $session['user_code'],
			);
		}
		delete_transient( self::SESSION );
		$done = self::register( $s['token'], $session['custody'], '' );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$done['status'] = 'connected';
		if ( ! empty( $s['user_email'] ) ) {
			$done['user_email'] = $s['user_email'];
		}
		return $done;
	}

	/**
	 * Registers this site as a node with a personal access token, and keeps
	 * the node token it gets back. The personal token is not kept.
	 *
	 * @param string $pat     sg_pat_... token.
	 * @param string $custody 'safegrd' or 'local'.
	 * @param string $org_id  The organization, when the account has several.
	 * @return array|WP_Error {node_id, key_custody, identity (customer-managed only)}
	 */
	public static function register( $pat, $custody, $org_id ) {
		$refusal = SafeGrd_Site::refusal();
		if ( '' !== $refusal ) {
			return new WP_Error( 'safegrd_refused', $refusal );
		}
		$base   = SafeGrd_Settings::server_url();
		$client = new SafeGrd_Client( $base, $pat );
		if ( '' === $org_id ) {
			$orgs = $client->call( 'GET', '/api/v1/orgs', null, 15 );
			if ( is_wp_error( $orgs ) ) {
				return $orgs;
			}
			if ( 0 === count( $orgs ) ) {
				return new WP_Error( 'safegrd_no_org', 'This account belongs to no organization yet. Open the SafeGrd console once to create one, then connect again.' );
			}
			if ( count( $orgs ) > 1 ) {
				$names = array();
				foreach ( $orgs as $o ) {
					$names[] = $o['name'] . ' (' . $o['id'] . ')';
				}
				return new WP_Error(
					'safegrd_many_orgs',
					'This account belongs to several organizations: ' . implode( ', ', $names ) . '. Connect with WP-CLI and name one: wp safegrd connect --org=<id>',
					array( 'orgs' => $orgs )
				);
			}
			$org_id = $orgs[0]['id'];
		}

		$keys    = SafeGrd_Age_Keys::generate();
		$managed = 'local' !== $custody;
		$req     = array(
			'org_id'          => $org_id,
			'name'            => SafeGrd_Site::name(),
			'surface_type'    => 'wordpress',
			'database_name'   => DB_NAME,
			'surface_ref'     => home_url(),
			'storage_bucket'  => '',
			'local_storage'   => 'hosted',
			'public_key'      => $keys['recipient'],
			'schedule'        => SafeGrd_Scheduler::SCHEDULE,
			'retention_days'  => 0,
			'os'              => 'wordpress',
			'arch'            => 'php-' . PHP_VERSION,
			'cli_version'     => 'wordpress-plugin ' . SAFEGRD_VERSION,
		);
		if ( $managed ) {
			$req['managed_identity'] = $keys['identity'];
		}
		$resp = $client->call( 'POST', '/api/v1/nodes/register', $req, 30 );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( empty( $resp['node_id'] ) || empty( $resp['token'] ) ) {
			return new WP_Error( 'safegrd_register', 'The server registered the site but sent back no node token.' );
		}
		if ( $managed && empty( $resp['key_escrowed'] ) ) {
			// Enrolled, but the server did not keep the key: the identity
			// here is the only copy, so it goes back to the person now.
			$managed = false;
		}
		SafeGrd_Settings::update(
			array(
				'server_url'   => $base,
				'node_id'      => $resp['node_id'],
				'node_token'   => $resp['token'],
				'org_id'       => $org_id,
				'project_id'   => isset( $resp['project_id'] ) ? $resp['project_id'] : '',
				'recipient'    => $keys['recipient'],
				'fingerprint'  => isset( $resp['key_fingerprint'] ) ? $resp['key_fingerprint'] : '',
				'key_custody'  => $managed ? 'safegrd' : 'local',
				'connected_at' => gmdate( 'c' ),
			)
		);
		SafeGrd_Scheduler::schedule();
		$out = array(
			'node_id'     => $resp['node_id'],
			'key_custody' => $managed ? 'safegrd' : 'local',
		);
		if ( ! $managed ) {
			// Shown once, to be saved. The site keeps only the recipient.
			$out['identity'] = $keys['identity'];
			if ( 'local' !== $custody ) {
				$out['escrow_failed'] = true;
			}
		}
		return $out;
	}

	/**
	 * Forgets the connection on this site. The node and its backups stay in
	 * the console.
	 */
	public static function disconnect() {
		SafeGrd_Scheduler::unschedule();
		SafeGrd_Settings::forget();
		delete_transient( self::SESSION );
	}
}
