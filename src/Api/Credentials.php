<?php
declare(strict_types=1);

namespace BeehiivSync\Api;

use BeehiivSync\Support\Encryption;

/**
 * Persists beehiiv credentials in a single autoloaded option.
 *
 * The API key is stored encrypted via libsodium; the publication
 * ID is stored in cleartext (it's a non-secret identifier).
 */
final class Credentials {

	public const OPTION = 'beehiiv_sync_credentials';

	public function __construct( private readonly Encryption $crypto ) {}

	public function exists(): bool {
		$stored = get_option( self::OPTION );
		return is_array( $stored )
			&& ! empty( $stored['api_key_encrypted'] )
			&& ! empty( $stored['publication_id'] );
	}

	public function publication_id(): ?string {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['publication_id'] ) ) {
			return null;
		}
		return (string) $stored['publication_id'];
	}

	public function api_key(): ?string {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['api_key_encrypted'] ) ) {
			return null;
		}
		return $this->crypto->decrypt( (string) $stored['api_key_encrypted'] );
	}

	public function store( string $api_key, string $publication_id ): void {
		$payload = [
			'api_key_encrypted' => $this->crypto->encrypt( $api_key ),
			'publication_id'    => $publication_id,
			'updated_at'        => time(),
		];
		update_option( self::OPTION, $payload, false );
	}

	public function forget(): void {
		delete_option( self::OPTION );
	}
}
