<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * The narrow seam between the sync logic and WordPress.
 */
interface PostRepository {

	/**
	 * Locate the WordPress post that already represents this beehiiv post.
	 *
	 * Matches on the `_beehiiv_post_id` meta first; failing that, falls back
	 * to a same-slug post in the target post type that isn't already claimed
	 * by a different beehiiv id (so posts created before/outside this plugin
	 * get adopted instead of duplicated).
	 *
	 * @return array{id:int, content_hash:?string}|null
	 */
	public function find_existing( string $beehiiv_id, string $slug, string $post_type ): ?array;

	/**
	 * @param array<string, mixed> $post_args
	 */
	public function insert( array $post_args ): int;

	/**
	 * @param array<string, mixed> $post_args
	 */
	public function update( int $post_id, array $post_args ): void;

	/**
	 * @param array<string, mixed> $meta
	 */
	public function set_meta( int $post_id, array $meta ): void;

	/**
	 * Apply one taxonomy assignment. `term_names` are resolved/created;
	 * `term_ids` are used as-is. The combined set replaces existing terms
	 * in that taxonomy on the post.
	 *
	 * @param string[] $term_names
	 * @param int[]    $term_ids
	 */
	public function set_terms( int $post_id, string $taxonomy, array $term_names, array $term_ids ): void;

	public function sideload_featured_image( int $post_id, string $image_url ): void;
}
