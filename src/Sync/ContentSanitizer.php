<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * Sanitize HTML from beehiiv before storing it as post_content.
 *
 * Beehiiv returns a full HTML document whose visual design lives in a
 * <style> block in the <head>: CSS custom properties (`:root { --wt-* }`)
 * plus utility classes (`.bg-wt-*`, `.text-wt-*`). The body markup then
 * references those via inline `style="...var(--wt-*)..."` and class names.
 *
 * If we drop the stylesheet, every colour/spacing reference resolves to a
 * default and the post looks wrong (e.g. buttons lose their colour). So we:
 *
 * 1. Extract the <style> CSS up front.
 * 2. Extract the <body> contents and remove style/script/head/etc. blocks.
 * 3. Strip iframes whose src host is not on our allowlist.
 * 4. wp_kses the body with the WP `post` set + inline style/presentational attrs.
 * 5. Re-attach the stylesheet, but *scoped*: every selector is prefixed with a
 *    wrapper class (and `:root`/`html`/`body` are mapped to it) so the rules
 *    only affect the imported post, never the surrounding theme. The body is
 *    wrapped in that class. The scoped CSS is appended after wp_kses so its
 *    text isn't mangled.
 */
final class ContentSanitizer {

	private const WRAP_CLASS = 'bs-beehiiv-post';

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

		$css  = $this->extract_css( $html );
		$body = $this->extract_body( $html );
		$body = $this->remove_blocks( $body, [ 'style', 'script', 'noscript', 'head', 'link', 'meta', 'template' ] );
		$body = $this->strip_doctype_and_html_wrappers( $body );
		$body = $this->strip_disallowed_iframes( $body );

		// We deliberately do NOT run wp_kses/safecss_filter_attr on the body:
		// that strips inline style properties and is what was greying out
		// beehiiv's buttons. beehiiv content is the site owner's own
		// newsletter (a trusted source), so we preserve its markup verbatim —
		// the same fidelity the prior ITFB importer relied on — and only strip
		// the genuinely dangerous bits below.
		$body = $this->strip_unsafe( $body );

		$scoped_css = $this->scope_css( $css, '.' . self::WRAP_CLASS );
		$style_tag  = $scoped_css !== '' ? '<style>' . $scoped_css . '</style>' : '';

		return $style_tag . '<div class="' . self::WRAP_CLASS . '">' . $body . '</div>';
	}

	/**
	 * Concatenate the contents of every <style> block in the document.
	 */
	private function extract_css( string $html ): string {
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $m ) === false ) {
			return '';
		}
		return isset( $m[1] ) ? implode( "\n", $m[1] ) : '';
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
	 * Rewrite a stylesheet so every rule only applies inside `$scope`.
	 *
	 * - Top-level selectors are prefixed with the scope; `:root`/`html`/`body`
	 *   are replaced by it so CSS variables land on the wrapper element.
	 * - @media / @supports blocks have their inner selectors scoped.
	 * - @font-face / @keyframes are kept as-is (safe to leave global).
	 * - @import and CSS that could break out of <style> are removed.
	 */
	private function scope_css( string $css, string $scope ): string {
		if ( trim( $css ) === '' ) {
			return '';
		}

		// Strip comments, @import, and anything that could escape the <style>.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = (string) preg_replace( '#@import[^;]+;#i', '', $css );
		$css = (string) preg_replace( '#expression\s*\(#i', '(', $css );
		$css = str_ireplace( [ '</style', 'javascript:' ], '', $css );

		$out = '';
		$i   = 0;
		$n   = strlen( $css );

		while ( $i < $n ) {
			$brace = strpos( $css, '{', $i );
			if ( $brace === false ) {
				break;
			}

			$prelude = trim( substr( $css, $i, $brace - $i ) );

			// Find the matching closing brace (handles one level of nesting for at-rules).
			$depth = 0;
			$j     = $brace;
			for ( ; $j < $n; $j++ ) {
				if ( $css[ $j ] === '{' ) {
					$depth++;
				} elseif ( $css[ $j ] === '}' ) {
					$depth--;
					if ( $depth === 0 ) {
						break;
					}
				}
			}

			$inner = substr( $css, $brace + 1, $j - $brace - 1 );
			$i     = $j + 1;

			if ( $prelude === '' ) {
				continue;
			}

			if ( $prelude[0] === '@' ) {
				$lower = strtolower( $prelude );
				if ( strpos( $lower, '@media' ) === 0 || strpos( $lower, '@supports' ) === 0 ) {
					$out .= $prelude . '{' . $this->scope_css( $inner, $scope ) . '}';
				} else {
					// @font-face, @keyframes, @page, etc. — leave untouched.
					$out .= $prelude . '{' . $inner . '}';
				}
				continue;
			}

			$scoped = [];
			foreach ( explode( ',', $prelude ) as $selector ) {
				$selector = trim( $selector );
				if ( $selector === '' ) {
					continue;
				}
				$low = strtolower( $selector );
				if ( $low === ':root' || $low === 'html' || $low === 'body' ) {
					$scoped[] = $scope;
				} else {
					$scoped[] = $scope . ' ' . $selector;
				}
			}

			if ( $scoped !== [] ) {
				$out .= implode( ',', $scoped ) . '{' . trim( $inner ) . '}';
			}
		}

		return $out;
	}

	/**
	 * Light-touch security pass that preserves styling.
	 *
	 * Removes inline event handlers and javascript: URLs (the main XSS vectors)
	 * while leaving inline `style` attributes and presentational markup intact,
	 * so beehiiv's button/colour styling survives. <script> and disallowed
	 * iframes are already stripped before this runs.
	 */
	private function strip_unsafe( string $html ): string {
		// Drop on*="..." / on*='...' / on*=unquoted event-handler attributes.
		$html = (string) preg_replace( '#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html );
		$html = (string) preg_replace( "#\son[a-z]+\s*=\s*'[^']*'#i", '', $html );
		$html = (string) preg_replace( '#\son[a-z]+\s*=\s*[^\s>]+#i', '', $html );

		// Neutralise javascript: in href/src.
		$html = (string) preg_replace( '#(href|src)\s*=\s*"\s*javascript:[^"]*"#i', '$1="#"', $html );
		$html = (string) preg_replace( "#(href|src)\s*=\s*'\s*javascript:[^']*'#i", "$1='#'", $html );

		return $html;
	}
}
