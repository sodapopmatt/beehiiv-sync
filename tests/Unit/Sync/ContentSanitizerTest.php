<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Sync;

use BeehiivSync\Sync\ContentSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the iframe-host allowlist behavior. The wp_kses pass is
 * not exercised here (no WP); WpPostRepository tests would cover that.
 */
final class ContentSanitizerTest extends TestCase {

	public function test_allowed_iframe_host_is_kept(): void {
		$html = '<p>hi</p><iframe src="https://embed.beehiiv.com/abc"></iframe>';
		self::assertStringContainsString( 'embed.beehiiv.com', ( new ContentSanitizer() )->sanitize( $html ) );
	}

	public function test_disallowed_iframe_host_is_stripped(): void {
		$html = '<p>hi</p><iframe src="https://evil.example.com/x"></iframe>';
		$out  = ( new ContentSanitizer() )->sanitize( $html );
		self::assertStringNotContainsString( 'evil.example.com', $out );
		self::assertStringNotContainsString( '<iframe', $out );
	}

	public function test_youtube_iframe_is_kept(): void {
		$html = '<iframe src="https://www.youtube.com/embed/abc"></iframe>';
		self::assertStringContainsString( 'youtube.com', ( new ContentSanitizer() )->sanitize( $html ) );
	}

	public function test_empty_input_returns_empty(): void {
		self::assertSame( '', ( new ContentSanitizer() )->sanitize( '' ) );
	}
}
