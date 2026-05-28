<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Support;

use BeehiivSync\Support\Encryption;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionTest extends TestCase {

	private function crypto(): Encryption {
		return new Encryption( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
	}

	public function test_round_trip(): void {
		$crypto = $this->crypto();
		$plain  = 'beehiiv-api-key-' . bin2hex( random_bytes( 8 ) );

		$cipher = $crypto->encrypt( $plain );

		self::assertNotSame( $plain, $cipher );
		self::assertSame( $plain, $crypto->decrypt( $cipher ) );
	}

	public function test_two_encryptions_of_same_plaintext_differ(): void {
		$crypto = $this->crypto();
		self::assertNotSame( $crypto->encrypt( 'abc' ), $crypto->encrypt( 'abc' ) );
	}

	public function test_decrypt_rejects_tampered_ciphertext(): void {
		$crypto = $this->crypto();
		$cipher = $crypto->encrypt( 'secret' );

		$decoded   = base64_decode( $cipher, true );
		$decoded[strlen( $decoded ) - 1] = $decoded[strlen( $decoded ) - 1] === 'A' ? 'B' : 'A';
		$tampered  = base64_encode( $decoded );

		$this->expectException( RuntimeException::class );
		$crypto->decrypt( $tampered );
	}

	public function test_decrypt_rejects_malformed_payload(): void {
		$this->expectException( RuntimeException::class );
		$this->crypto()->decrypt( 'not-base64!!!' );
	}

	public function test_constructor_rejects_short_key(): void {
		$this->expectException( RuntimeException::class );
		new Encryption( 'too-short' );
	}
}
