<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * Sanitize HTML from beehiiv before storing it as post_content.
 *
 * Beehiiv returns a full HTML document: <!DOCTYPE html><html><head>
 * <style>...themed CSS...</style></head><body>...newsletter...</body>
 * </html>. We:
 *
 * 1. Extract only the <body> contents.
 * 2. Remove <style>, <script>, <link>, <meta>, <head>, <html>, <body> blocks
 *    entirely (including text). wp_kses_post would otherwise strip just
 *    the tags and leave CSS/JS text as raw output.
 * 3. Strip iframes whose src host is not on our allowlist.
 * 4. Run wp_kses with the WP `post` allowed-html set plus iframe.
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

		$html = $this->extract_body( $html );
		$html = $this->remove_blocks( $html, [ 'style', 'script', 'noscript', 'head', 'link', 'meta', 'template' ] );
		$html = $this->strip_doctype_and_html_wrappers( $html );
		$html = $this->strip_disallowed_iframes( $html );

		if ( ! function_exists( 'wp_kses' ) ) {
			return $html;
		}

		return wp_kses( $html, $this->allowed_html() );
	}

	private function extract_body( string $html ): string {
		if ( preg_match( '#<body\b[^>]*>(.*?)</body>#is', $html, $m ) === 1 ) {
			return $m[1];
		}
		return $html;
	}

	/**
	 * @param string[] $tags
	 */
	private function remove_blocks( string $html, array $tags ): string {
		foreach ( $tags as $tag ) {
			$tag = preg_quote( $tag, '#' );
			// Paired tags (and any contents)
			$html = (string) preg_replace( "#<{$tag}\b[^>]*>.*?</{$tag}>#is", '', $html );
			// Self-closing / void variants
			$html = (string) preg_replace( "#<{$tag}\b[^>]*/?>#is", '', $html );
		}
		return $html;
	}

	private function strip_doctype_and_html_wrappers( string $html ): string {
		$html = (string) preg_replace( '#<!doctype[^>]*>#i', '', $html );
		$html = (string) preg_replace( '#</?html\b[^>]*>#i', '', $html );
		$html = (string) preg_replace( '#</?body\b[^>]*>#i', '', $html );
		return $html;
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

	/**
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_html(): array {
		if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
			return [];
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

		return $allowed;
	}
}
