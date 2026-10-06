<?php
/**
 * Talks to the SafeGrd server's API, and to the presigned bucket URLs it
 * hands out.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Client {
	/** @var string */
	private $base;
	/** @var string */
	private $token;

	/**
	 * @param string $base  Server URL.
	 * @param string $token Bearer token: a node token, or for connecting a
	 *                      personal access token.
	 */
	public function __construct( $base, $token = '' ) {
		$this->base  = rtrim( $base, '/' );
		$this->token = $token;
	}

	public static function for_site() {
		return new self( SafeGrd_Settings::server_url(), SafeGrd_Settings::get( 'node_token' ) );
	}

	/**
	 * Refuses a server reached over plain HTTP, except on this machine:
	 * tokens and keys cross this connection.
	 *
	 * @return true|WP_Error
	 */
	public static function check_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'safegrd_url', sprintf( 'The server URL %s is not a URL.', $url ) );
		}
		if ( 'https' === $parts['scheme'] ) {
			return true;
		}
		if ( 'http' === $parts['scheme'] && in_array( $parts['host'], array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return true;
		}
		return new WP_Error( 'safegrd_url', sprintf( 'The server URL %s is not HTTPS. Tokens and keys would cross the network in the clear, so the plugin refuses it.', $url ) );
	}

	/**
	 * Arguments every request shares: a CA bundle when SAFEGRD_CA_FILE names
	 * one (a self-hosted server with a private CA), and the plugin's name.
	 */
	public static function request_args( array $args ) {
		$args['user-agent'] = 'safegrd-wordpress/' . SAFEGRD_VERSION . ' (WordPress ' . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . ')';
		if ( defined( 'SAFEGRD_CA_FILE' ) && SAFEGRD_CA_FILE ) {
			$args['sslcertificates'] = SAFEGRD_CA_FILE;
		}
		return $args;
	}

	/**
	 * Calls the API and decodes its JSON answer.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path under the server, starting with /.
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Seconds.
	 * @return array|WP_Error The decoded answer, or the server's refusal in its own words.
	 */
	public function call( $method, $path, $body = null, $timeout = 60 ) {
		$ok = self::check_url( $this->base );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array( 'Accept' => 'application/json' ),
		);
		if ( '' !== $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$resp = wp_remote_request( $this->base . $path, self::request_args( $args ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'safegrd_unreachable', sprintf( 'Could not reach %s: %s', $this->base, $resp->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code > 299 ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : sprintf( 'HTTP %d from %s%s', $code, $this->base, $path );
			return new WP_Error( 'safegrd_http_' . $code, $msg, array( 'status' => $code ) );
		}
		if ( '' === trim( $raw ) ) {
			return array();
		}
		if ( null === $data && 'null' !== trim( $raw ) ) {
			return new WP_Error( 'safegrd_json', sprintf( 'The server answered %s%s with something that is not JSON.', $this->base, $path ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * PUTs bytes to a presigned URL.
	 *
	 * @return true|WP_Error
	 */
	public static function put( $url, $bytes ) {
		$resp = wp_remote_request(
			$url,
			self::request_args(
				array(
					'method'  => 'PUT',
					'timeout' => 300,
					'body'    => $bytes,
				)
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		return new WP_Error( 'safegrd_bucket_' . $code, self::bucket_error( $code, wp_remote_retrieve_body( $resp ) ), array( 'status' => $code ) );
	}

	/**
	 * What the bucket answered, in one line: the S3 error's code and message
	 * when the body is S3's XML, else its first line.
	 */
	private static function bucket_error( $code, $body ) {
		if ( preg_match( '#<Code>([^<]*)</Code>#', $body, $c ) ) {
			$msg = $c[1];
			if ( preg_match( '#<Message>([^<]*)</Message>#', $body, $m ) ) {
				$msg .= ': ' . $m[1];
			}
			return sprintf( 'the bucket answered %d: %s', $code, $msg );
		}
		$line = strtok( trim( (string) $body ), "\n" );
		return $line ? sprintf( 'the bucket answered %d: %s', $code, substr( $line, 0, 200 ) ) : sprintf( 'the bucket answered %d', $code );
	}
}
