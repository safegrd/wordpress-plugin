<?php
/**
 * A backup failure, with the reason code the server records.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Exception extends RuntimeException {
	/** @var string One of the server's failure reasons: config, source, storage, quota, other. */
	public $reason;

	public function __construct( $message, $reason = '' ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}

	/**
	 * A failure's message as plain text, for WP-CLI and the server's record.
	 * SafeGrd_Exception messages are escaped for HTML where they are thrown,
	 * because the Tools page shows them; this undoes that for the other places.
	 */
	public static function text( Throwable $e ) {
		if ( $e instanceof self ) {
			return html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $e->getMessage();
	}
}
