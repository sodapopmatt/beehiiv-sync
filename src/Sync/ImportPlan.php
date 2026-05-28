<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * The fully-resolved plan for syncing one beehiiv post into WordPress.
 *
 * Built by PostMapper from a BeehiivPost + settings + (optionally) the
 * existing WP post ID. Consumed by ItemProcessor, which is the only
 * thing that touches WordPress.
 */
final class ImportPlan {

	public const ACTION_INSERT = 'insert';
	public const ACTION_UPDATE = 'update';
	public const ACTION_SKIP   = 'skip';

	/**
	 * @param array<string, mixed>                                                $post_args    Args for wp_insert_post / wp_update_post.
	 * @param array<string, mixed>                                                $meta         Post meta to write.
	 * @param array<int, array{taxonomy:string, term_names:string[], term_ids:int[]}> $term_assignments  One entry per taxonomy.
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $beehiiv_id,
		public readonly ?int $existing_post_id,
		public readonly array $post_args,
		public readonly array $meta,
		public readonly array $term_assignments,
		public readonly ?string $featured_image_url,
		public readonly string $content_hash,
		public readonly ?string $skip_reason = null,
	) {}

	public static function skip( string $beehiiv_id, ?int $existing_post_id, string $reason, string $content_hash ): self {
		return new self(
			action: self::ACTION_SKIP,
			beehiiv_id: $beehiiv_id,
			existing_post_id: $existing_post_id,
			post_args: [],
			meta: [],
			term_assignments: [],
			featured_image_url: null,
			content_hash: $content_hash,
			skip_reason: $reason,
		);
	}
}
