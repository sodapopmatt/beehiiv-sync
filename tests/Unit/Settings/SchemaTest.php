<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Settings;

use BeehiivSync\Settings\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	public function test_defaults_have_expected_shape(): void {
		$d = Schema::defaults();
		self::assertSame( 'post', $d['defaults']['post_type'] );
		self::assertSame( 'all', $d['defaults']['audience'] );
		self::assertFalse( $d['schedule']['enabled'] );
	}

	public function test_sanitize_rejects_unknown_audience(): void {
		$out = Schema::sanitize( Schema::defaults(), [ 'defaults' => [ 'audience' => 'evil' ] ] );
		self::assertSame( 'all', $out['defaults']['audience'] );
	}

	public function test_sanitize_clamps_hour_and_minute(): void {
		$out = Schema::sanitize( Schema::defaults(), [ 'schedule' => [ 'hour' => 99, 'minute' => -5 ] ] );
		self::assertSame( 23, $out['schedule']['hour'] );
		self::assertSame( 0, $out['schedule']['minute'] );
	}

	public function test_sanitize_partial_input_preserves_other_fields(): void {
		$out = Schema::sanitize( Schema::defaults(), [ 'defaults' => [ 'audience' => 'premium' ] ] );
		self::assertSame( 'premium', $out['defaults']['audience'] );
		self::assertSame( 'post', $out['defaults']['post_type'] );
		self::assertSame( 'post_tag', $out['defaults']['tag_target'] );
	}

	public function test_sanitize_accepts_valid_status_map(): void {
		$out = Schema::sanitize(
			Schema::defaults(),
			[ 'defaults' => [ 'post_status_map' => [ 'confirmed' => 'private', 'draft' => 'pending' ] ] ]
		);
		self::assertSame( 'private', $out['defaults']['post_status_map']['confirmed'] );
		self::assertSame( 'pending', $out['defaults']['post_status_map']['draft'] );
		self::assertSame( 'draft', $out['defaults']['post_status_map']['archived'] );
	}

	public function test_sanitize_rejects_invalid_status_map_value(): void {
		$out = Schema::sanitize(
			Schema::defaults(),
			[ 'defaults' => [ 'post_status_map' => [ 'confirmed' => 'trash' ] ] ]
		);
		// 'trash' is not a valid WP status, so the default ('draft') is preserved.
		self::assertSame( 'draft', $out['defaults']['post_status_map']['confirmed'] );
	}
}
