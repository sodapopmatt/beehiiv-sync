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

	public function publication_name(): ?string {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['publication_name'] ) ) {
			return null;
		}
		return (string) $stored['publication_name'];
	}

	/**
	 * Update just the cached publication name without touching the API key.
	 */
	public function set_publication_name( string $name ): void {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$stored['publication_name'] = $name;
		update_option( self::OPTION, $stored, false );
	}

	public function api_key(): ?string {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['api_key_encrypted'] ) ) {
			return null;
		}
		return $this->crypto->decrypt( (string) $stored['api_key_encrypted'] );
	}

	public function store( string $api_key, string $publication_id, string $publication_name = '' ): void {
		$payload = [
			'api_key_encrypted' => $this->crypto->encrypt( $api_key ),
			'publication_id'    => $publication_id,
			'publication_name'  => $publication_name,
			'updated_at'        => time(),
		];
		update_option( self::OPTION, $payload, false );
	}

	public function forget(): void {
		delete_option( self::OPTION );
	}
}
