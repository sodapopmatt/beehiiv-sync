<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * The narrow seam between the sync logic and WordPress.
 *
 * Everything the processor needs to read or write on the WP side
 * goes through this interface. Lets us unit-test ItemProcessor
 * with a pure in-memory fake.
 */
interface PostRepository {

	/**
	 * @return array{id:int, content_hash:?string}|null
	 */
	public function find_by_beehiiv_id( string $beehiiv_id ): ?array;

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
	 * @param string[] $term_names
	 */
	public function set_terms( int $post_id, string $taxonomy, array $term_names ): void;

	public function sideload_featured_image( int $post_id, string $image_url ): void;
}
