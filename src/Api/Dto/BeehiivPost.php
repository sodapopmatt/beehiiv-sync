<?php
declare(strict_types=1);

namespace BeehiivSync\Api\Dto;

/**
 * Typed projection of a beehiiv "post" (newsletter) resource.
 *
 * Beehiiv's API returns more fields than we use; this DTO is the
 * curated subset the sync layer cares about. Unknown fields are
 * kept in `raw` for diagnostic logging.
 */
final class BeehiivPost {

	/**
	 * @param array<string, string> $content_html  Keys: 'free', 'premium' → HTML
	 * @param string[]              $content_tags
	 * @param array<string, mixed>  $raw
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly ?string $subtitle,
		public readonly string $slug,
		public readonly string $status,
		public readonly string $audience,
		public readonly ?int $publish_date,
		public readonly ?string $web_url,
		public readonly ?string $thumbnail_url,
		public readonly array $content_html,
		public readonly array $content_tags,
		public readonly array $raw,
	) {}

	/**
	 * @param array<string, mixed> $data Raw decoded JSON from beehiiv.
	 */
	public static function from_array( array $data ): self {
		$content = $data['content']['free']['web'] ?? null;
		$premium = $data['content']['premium']['web'] ?? null;

		$content_html = [];
		if ( is_string( $content ) ) {
			$content_html['free'] = $content;
		}
		if ( is_string( $premium ) ) {
			$content_html['premium'] = $premium;
		}

		$tags = [];
		if ( isset( $data['content_tags'] ) && is_array( $data['content_tags'] ) ) {
			$tags = array_values(
				array_filter(
					array_map( 'strval', $data['content_tags'] ),
					static fn( string $t ): bool => $t !== ''
				)
			);
		}

		return new self(
			id: (string) ( $data['id'] ?? '' ),
			title: (string) ( $data['title'] ?? '' ),
			subtitle: isset( $data['subtitle'] ) ? (string) $data['subtitle'] : null,
			slug: (string) ( $data['slug'] ?? '' ),
			status: (string) ( $data['status'] ?? '' ),
			audience: (string) ( $data['audience'] ?? '' ),
			publish_date: isset( $data['publish_date'] ) ? (int) $data['publish_date'] : null,
			web_url: isset( $data['web_url'] ) ? (string) $data['web_url'] : null,
			thumbnail_url: isset( $data['thumbnail']['url'] ) ? (string) $data['thumbnail']['url']
				: ( isset( $data['thumbnail_url'] ) ? (string) $data['thumbnail_url'] : null ),
			content_html: $content_html,
			content_tags: $tags,
			raw: $data,
		);
	}
}
