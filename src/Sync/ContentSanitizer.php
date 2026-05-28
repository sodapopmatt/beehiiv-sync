<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * Sanitize HTML from beehiiv before storing it as post_content.
 *
 * beehiiv emits a fairly standard subset of HTML plus iframe embeds
 * for media. We start from wp_kses_post and add an allowlisted iframe
 * tag restricted to embed.beehiiv.com / www.youtube.com / player.vimeo.com.
 */
final class ContentSanitizer {

	private const IFRAME_HOST_ALLOWLIST = [
		'embed.beehiiv.com',
		'www.youtube.com',
		'youtube.com',
		'player.vimeo.com',
	];

	public function sanitize( string $html ): string {
		if ( $html === '' ) {
			return '';
		}

		$html = $this->strip_disallowed_iframes( $html );

		if ( ! function_exists( 'wp_kses' ) ) {
			return $html;
		}

		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['iframe'] = [
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'loading'         => true,
			'title'           => true,
			'referrerpolicy'  => true,
		];

		return wp_kses( $html, $allowed );
	}

	private function strip_disallowed_iframes( string $html ): string {
		return (string) preg_replace_callback(
			'#<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>.*?</iframe>#is',
			function ( array $m ): string {
				$src  = $m[1];
				$host = strtolower( (string) parse_url( $src, PHP_URL_HOST ) );
				return in_array( $host, self::IFRAME_HOST_ALLOWLIST, true ) ? $m[0] : '';
			},
			$html
		);
	}
}
