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

	public function test_extracts_only_body_contents(): void {
		$html = '<!DOCTYPE html><html><head><title>X</title></head><body><p>Hello</p></body></html>';
		$out  = ( new ContentSanitizer() )->sanitize( $html );
		self::assertStringContainsString( 'Hello', $out );
		self::assertStringNotContainsString( '<title>', $out );
		self::assertStringNotContainsString( '<body', $out );
	}

	public function test_preserves_style_scoped_to_wrapper(): void {
		$html = '<html><head><style>:root { --color: red; } .btn { color: var(--color); }</style></head><body><p class="btn">Real content</p></body></html>';
		$out  = ( new ContentSanitizer() )->sanitize( $html );

		self::assertStringContainsString( 'Real content', $out );
		// The stylesheet is preserved...
		self::assertStringContainsString( '--color', $out );
		self::assertStringContainsString( '.btn', $out );
		// ...but scoped to the wrapper, with :root mapped onto it (no bare :root).
		self::assertStringContainsString( 'class="bs-beehiiv-post"', $out );
		self::assertStringNotContainsString( ':root', $out );
	}

	public function test_strips_script_block_and_contents(): void {
		$html = '<body><p>hi</p><script>alert(1)</script></body>';
		$out  = ( new ContentSanitizer() )->sanitize( $html );
		self::assertStringContainsString( 'hi', $out );
		self::assertStringNotContainsString( 'alert', $out );
		self::assertStringNotContainsString( '<script', $out );
	}

	public function test_strips_link_and_meta(): void {
		$html = '<body><link rel="stylesheet" href="x"><meta name="x" content="y"><p>keep</p></body>';
		$out  = ( new ContentSanitizer() )->sanitize( $html );
		self::assertStringContainsString( 'keep', $out );
		self::assertStringNotContainsString( '<link', $out );
		self::assertStringNotContainsString( '<meta', $out );
	}

	public function test_strips_doctype_when_no_body_wrapper(): void {
		$html = '<!DOCTYPE html><p>orphan</p>';
		$out  = ( new ContentSanitizer() )->sanitize( $html );
		self::assertStringContainsString( 'orphan', $out );
		self::assertStringNotContainsString( '<!DOCTYPE', $out );
		self::assertStringNotContainsString( '<!doctype', $out );
	}
}
