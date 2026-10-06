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
}
