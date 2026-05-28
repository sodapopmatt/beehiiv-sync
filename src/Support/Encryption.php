<?php
declare(strict_types=1);

namespace BeehiivSync\Support;

use RuntimeException;

/**
 * Authenticated symmetric encryption using libsodium's secretbox.
 *
 * Ciphertext layout: base64( nonce || secretbox(plain, nonce, key) ).
 *
 * The key is derived once per install via HKDF-like construction over
 * AUTH_KEY + a per-install salt persisted in wp_options. Rotating
 * AUTH_KEY invalidates stored ciphertexts by design.
 */
final class Encryption {

	private const KEY_OPTION = 'beehiiv_sync_encryption_salt';
	private const KEY_INFO   = 'beehiiv-sync.v1.credentials';

	public function __construct( private readonly string $key ) {
		if ( strlen( $this->key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			throw new RuntimeException( 'Encryption key must be 32 bytes.' );
		}
	}

	public static function from_wp_install(): self {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new RuntimeException( 'libsodium is required.' );
		}

		$salt = get_option( self::KEY_OPTION );
		if ( ! is_string( $salt ) || $salt === '' ) {
			$salt = base64_encode( random_bytes( 32 ) );
			add_option( self::KEY_OPTION, $salt, '', false );
		}

		$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		if ( $secret === '' ) {
			throw new RuntimeException( 'AUTH_KEY must be defined in wp-config.php.' );
		}

		$key = hash_hkdf( 'sha256', $secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KEY_INFO, $salt );
		return new self( $key );
	}

	public function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );
		return base64_encode( $nonce . $ciphertext );
	}

	public function decrypt( string $payload ): string {
		$decoded = base64_decode( $payload, true );
		if ( $decoded === false || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new RuntimeException( 'Malformed ciphertext.' );
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );
		if ( $plaintext === false ) {
			throw new RuntimeException( 'Decryption failed.' );
		}
		return $plaintext;
	}
}
