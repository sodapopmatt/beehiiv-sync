<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use BeehiivSync\Api\Dto\BeehiivPost;

/**
 * Pure transform: BeehiivPost + settings + (optional) existing post → ImportPlan.
 *
 * No WordPress calls. The processor is responsible for actually
 * touching the database based on the plan this returns.
 */
final class PostMapper {

	public function __construct( private readonly ContentSanitizer $sanitizer ) {}

	/**
	 * @param array<string, mixed> $settings_defaults  The `defaults` block from Schema.
	 * @param array{id:int, content_hash:?string}|null $existing
	 */
	public function plan( BeehiivPost $beehiiv, array $settings_defaults, ?array $existing = null ): ImportPlan {
		$content_raw   = $this->select_content( $beehiiv );
		$content_clean = $this->sanitizer->sanitize( $content_raw );

		$content_hash = hash(
			'sha256',
			implode(
				"\0",
				[
					$beehiiv->title,
					$beehiiv->subtitle ?? '',
					$content_clean,
					(string) ( $beehiiv->publish_date ?? '' ),
					$beehiiv->status,
					implode( ',', $beehiiv->content_tags ),
				]
			)
		);

		$existing_id   = $existing['id'] ?? null;
		$import_mode   = (string) ( $settings_defaults['import_mode'] ?? 'both' );
		$action        = $this->decide_action( $existing_id, $import_mode );

		if ( $action === ImportPlan::ACTION_SKIP ) {
			return ImportPlan::skip(
				beehiiv_id: $beehiiv->id,
				existing_post_id: $existing_id,
				reason: $existing_id === null ? 'existing_only_mode' : 'new_only_mode',
				content_hash: $content_hash
			);
		}

		if ( $existing_id !== null && ( $existing['content_hash'] ?? null ) === $content_hash ) {
			return ImportPlan::skip(
				beehiiv_id: $beehiiv->id,
				existing_post_id: $existing_id,
				reason: 'unchanged',
				content_hash: $content_hash
			);
		}

		$post_status = $this->resolve_status( $beehiiv->status, $settings_defaults );
		$publish_at  = $beehiiv->publish_date ? gmdate( 'Y-m-d H:i:s', $beehiiv->publish_date ) : null;

		$post_args = [
			'post_type'    => (string) ( $settings_defaults['post_type'] ?? 'post' ),
			'post_title'   => $beehiiv->title,
			'post_name'    => $beehiiv->slug,
			'post_content' => $content_clean,
			'post_excerpt' => $beehiiv->subtitle ?? '',
			'post_status'  => $post_status,
			'post_author'  => (int) ( $settings_defaults['author_id'] ?? 0 ),
		];

		if ( $publish_at !== null ) {
			$post_args['post_date_gmt'] = $publish_at;
		}

		$tags     = TagMapper::resolve( $beehiiv->content_tags, (string) ( $settings_defaults['tag_target'] ?? 'category' ) );
		$audience = $beehiiv->audience !== '' ? $beehiiv->audience : ( isset( $beehiiv->content_html['premium'] ) && ! isset( $beehiiv->content_html['free'] ) ? 'premium' : 'free' );

		$meta = [
			'_beehiiv_post_id'      => $beehiiv->id,
			'_beehiiv_web_url'      => $beehiiv->web_url,
			'_beehiiv_audience'     => $audience,
			'_beehiiv_content_hash' => $content_hash,
			'_beehiiv_imported_at'  => time(),
		];

		return new ImportPlan(
			action: $action,
			beehiiv_id: $beehiiv->id,
			existing_post_id: $existing_id,
			post_args: $post_args,
			meta: $meta,
			taxonomy: $tags['taxonomy'],
			term_names: $tags['term_names'],
			featured_image_url: $beehiiv->thumbnail_url,
			content_hash: $content_hash,
		);
	}

	private function select_content( BeehiivPost $beehiiv ): string {
		if ( isset( $beehiiv->content_html['free'] ) ) {
			return $beehiiv->content_html['free'];
		}
		return $beehiiv->content_html['premium'] ?? '';
	}

	private function decide_action( ?int $existing_id, string $import_mode ): string {
		if ( $existing_id === null ) {
			return $import_mode === 'update'
				? ImportPlan::ACTION_SKIP
				: ImportPlan::ACTION_INSERT;
		}
		return $import_mode === 'new'
			? ImportPlan::ACTION_SKIP
			: ImportPlan::ACTION_UPDATE;
	}

	/**
	 * @param array<string, mixed> $settings_defaults
	 */
	private function resolve_status( string $beehiiv_status, array $settings_defaults ): string {
		$map      = is_array( $settings_defaults['post_status_map'] ?? null ) ? $settings_defaults['post_status_map'] : [];
		$fallback = 'draft';
		return (string) ( $map[ $beehiiv_status ] ?? $fallback );
	}
}
