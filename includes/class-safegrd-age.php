<?php
/**
 * age v1 encryption to one X25519 recipient (https://age-encryption.org/v1),
 * written as a stream, and age key pairs.
 *
 * Only what the plugin needs: it encrypts to the site's recipient and never
 * decrypts. Restores read these files with the safegrd CLI or with `age`.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bech32 (BIP 173), as age encodes its keys. age keys are longer than the
 * 90 characters BIP 173 allows, and age does not enforce that limit, so
 * neither does this.
 */
final class SafeGrd_Bech32 {
	const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

	private static function polymod( array $values ) {
		$gen = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );
		$chk = 1;
		foreach ( $values as $v ) {
			$top = $chk >> 25;
			$chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $v;
			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $top >> $i ) & 1 ) {
					$chk ^= $gen[ $i ];
				}
			}
		}
		return $chk;
	}

	private static function hrp_expand( $hrp ) {
		$out = array();
		$len = strlen( $hrp );
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) >> 5;
		}
		$out[] = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) & 31;
		}
		return $out;
	}

	private static function convert_bits( array $data, $from, $to, $pad ) {
		$acc    = 0;
		$bits   = 0;
		$out    = array();
		$maxv   = ( 1 << $to ) - 1;
		foreach ( $data as $value ) {
			if ( $value < 0 || ( $value >> $from ) !== 0 ) {
				throw new InvalidArgumentException( 'bech32: value out of range' );
			}
			$acc   = ( $acc << $from ) | $value;
			$bits += $from;
			while ( $bits >= $to ) {
				$bits -= $to;
				$out[] = ( $acc >> $bits ) & $maxv;
			}
			$acc &= ( 1 << $bits ) - 1;
		}
		if ( $pad ) {
			if ( $bits > 0 ) {
				$out[] = ( $acc << ( $to - $bits ) ) & $maxv;
			}
		} elseif ( $bits >= $from || ( ( $acc << ( $to - $bits ) ) & $maxv ) ) {
			throw new InvalidArgumentException( 'bech32: invalid padding' );
		}
		return $out;
	}

	/**
	 * Encodes bytes under a human-readable part, in lower case.
	 *
	 * @param string $hrp  Human-readable part.
	 * @param string $data Bytes.
	 * @return string
	 */
	public static function encode( $hrp, $data ) {
		$hrp    = strtolower( $hrp );
		$values = self::convert_bits( array_values( unpack( 'C*', $data ) ), 8, 5, true );
		$poly   = self::polymod( array_merge( self::hrp_expand( $hrp ), $values, array( 0, 0, 0, 0, 0, 0 ) ) ) ^ 1;
		for ( $i = 0; $i < 6; $i++ ) {
			$values[] = ( $poly >> ( 5 * ( 5 - $i ) ) ) & 31;
		}
		$out = $hrp . '1';
		foreach ( $values as $v ) {
			$out .= self::CHARSET[ $v ];
		}
		return $out;
	}

	/**
	 * Decodes a bech32 string.
	 *
	 * @param string $s Encoded string, all upper or all lower case.
	 * @return array{0:string,1:string} The human-readable part and the bytes.
	 */
	public static function decode( $s ) {
		if ( strtolower( $s ) !== $s && strtoupper( $s ) !== $s ) {
			throw new InvalidArgumentException( 'bech32: mixed case' );
		}
		$s   = strtolower( $s );
		$pos = strrpos( $s, '1' );
		if ( false === $pos || $pos < 1 || $pos + 7 > strlen( $s ) ) {
			throw new InvalidArgumentException( 'bech32: no separator' );
		}
		$hrp    = substr( $s, 0, $pos );
		$values = array();
		$len    = strlen( $s );
		for ( $i = $pos + 1; $i < $len; $i++ ) {
			$v = strpos( self::CHARSET, $s[ $i ] );
			if ( false === $v ) {
				throw new InvalidArgumentException( 'bech32: invalid character' );
			}
			$values[] = $v;
		}
		if ( 1 !== self::polymod( array_merge( self::hrp_expand( $hrp ), $values ) ) ) {
			throw new InvalidArgumentException( 'bech32: bad checksum' );
		}
		$bytes = self::convert_bits( array_slice( $values, 0, -6 ), 5, 8, false );
		return array( $hrp, $bytes ? pack( 'C*', ...$bytes ) : '' );
	}
}

/**
 * age key pairs.
 */
final class SafeGrd_Age_Keys {
	/**
	 * A new X25519 identity and its recipient.
	 *
	 * @return array{identity:string,recipient:string}
	 */
	public static function generate() {
		$secret = random_bytes( 32 );
		$public = sodium_crypto_scalarmult_base( $secret );
		return array(
			'identity'  => strtoupper( SafeGrd_Bech32::encode( 'AGE-SECRET-KEY-', $secret ) ),
			'recipient' => SafeGrd_Bech32::encode( 'age', $public ),
		);
	}

	/**
	 * The 32-byte public key of an age1... recipient.
	 *
	 * @param string $recipient age1... string.
	 * @return string
	 */
	public static function parse_recipient( $recipient ) {
		list( $hrp, $key ) = SafeGrd_Bech32::decode( trim( $recipient ) );
		if ( 'age' !== $hrp || 32 !== strlen( $key ) ) {
			throw new InvalidArgumentException( 'not an age X25519 recipient' );
		}
		return $key;
	}
}

/**
 * An age v1 encrypter that writes as it is given plaintext. Call write() any
 * number of times, then finish() once. Output goes to the sink callable.
 */
final class SafeGrd_Age_Writer {
	const CHUNK = 65536;

	/** @var callable */
	private $sink;
	/** @var string */
	private $payload_key;
	/** @var string */
	private $buffer = '';
	/** @var int */
	private $counter = 0;
	/** @var bool */
	private $finished = false;

	/**
	 * Writes the header at once.
	 *
	 * @param string   $recipient age1... recipient.
	 * @param callable $sink      Receives ciphertext bytes.
	 */
	public function __construct( $recipient, callable $sink ) {
		$this->sink = $sink;
		$public     = SafeGrd_Age_Keys::parse_recipient( $recipient );
		$file_key   = random_bytes( 16 );

		// The X25519 stanza: an ephemeral share, and the file key wrapped
		// under a key derived from the shared secret.
		$ephemeral = random_bytes( 32 );
		$share     = sodium_crypto_scalarmult_base( $ephemeral );
		$shared    = sodium_crypto_scalarmult( $ephemeral, $public );
		if ( str_repeat( "\0", 32 ) === $shared ) {
			throw new RuntimeException( 'age: the recipient is a low-order point' );
		}
		$wrap_key = hash_hkdf( 'sha256', $shared, 32, 'age-encryption.org/v1/X25519', $share . $public );
		$body     = sodium_crypto_aead_chacha20poly1305_ietf_encrypt( $file_key, '', str_repeat( "\0", 12 ), $wrap_key );

		$header  = "age-encryption.org/v1\n";
		$header .= '-> X25519 ' . self::b64( $share ) . "\n";
		$header .= self::wrap( self::b64( $body ) );
		$header .= '---';
		$mac_key = hash_hkdf( 'sha256', $file_key, 32, 'header', '' );
		$mac     = hash_hmac( 'sha256', $header, $mac_key, true );
		$header .= ' ' . self::b64( $mac ) . "\n";

		$nonce             = random_bytes( 16 );
		$this->payload_key = hash_hkdf( 'sha256', $file_key, 32, 'payload', $nonce );
		call_user_func( $this->sink, $header . $nonce );
	}

	/**
	 * Encrypts plaintext. Whole chunks go out as soon as a later byte proves
	 * they are not the last.
	 *
	 * @param string $data Plaintext.
	 */
	public function write( $data ) {
		if ( $this->finished ) {
			throw new LogicException( 'age: write after finish' );
		}
		$this->buffer .= $data;
		if ( strlen( $this->buffer ) <= self::CHUNK ) {
			return;
		}
		$out    = '';
		$offset = 0;
		$len    = strlen( $this->buffer );
		while ( $len - $offset > self::CHUNK ) {
			$out    .= $this->seal( substr( $this->buffer, $offset, self::CHUNK ), false );
			$offset += self::CHUNK;
		}
		$this->buffer = (string) substr( $this->buffer, $offset );
		call_user_func( $this->sink, $out );
	}

	/**
	 * Seals the last chunk. A payload of no bytes is one empty last chunk.
	 */
	public function finish() {
		if ( $this->finished ) {
			return;
		}
		$this->finished = true;
		call_user_func( $this->sink, $this->seal( $this->buffer, true ) );
		$this->buffer = '';
	}

	private function seal( $chunk, $last ) {
		// The STREAM nonce: an 11-byte big-endian counter and a last-chunk flag.
		$nonce = "\0\0\0" . pack( 'J', $this->counter ) . ( $last ? "\x01" : "\x00" );
		$this->counter++;
		return sodium_crypto_aead_chacha20poly1305_ietf_encrypt( $chunk, '', $nonce, $this->payload_key );
	}

	private static function b64( $bytes ) {
		return rtrim( base64_encode( $bytes ), '=' );
	}

	/**
	 * A stanza body in lines of 64 columns. The last line is always shorter
	 * than 64, so a body of a multiple of 64 ends with an empty line.
	 */
	private static function wrap( $b64 ) {
		$out = '';
		foreach ( str_split( $b64, 64 ) as $line ) {
			$out .= $line . "\n";
		}
		if ( '' === $b64 || 0 === strlen( $b64 ) % 64 ) {
			$out .= "\n";
		}
		return $out;
	}
}
