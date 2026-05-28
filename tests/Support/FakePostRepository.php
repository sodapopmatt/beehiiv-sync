<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Support;

use BeehiivSync\Sync\PostRepository;

final class FakePostRepository implements PostRepository {

	/** @var array<string, array{id:int, content_hash:?string}> */
	private array $by_beehiiv_id = [];

	/** @var array<int, array<string, mixed>> */
	public array $inserted = [];

	/** @var array<int, array<string, mixed>> */
	public array $updated = [];

	/** @var array<int, array<string, mixed>> */
	public array $meta = [];

	/** @var array<int, array<int, array{taxonomy:string, term_names:string[], term_ids:int[]}>> */
	public array $terms = [];

	/** @var array<int, string> */
	public array $featured = [];

	private int $next_id = 1000;

	public function seed( string $beehiiv_id, int $post_id, ?string $content_hash ): void {
		$this->by_beehiiv_id[ $beehiiv_id ] = [ 'id' => $post_id, 'content_hash' => $content_hash ];
	}

	public function find_by_beehiiv_id( string $beehiiv_id ): ?array {
		return $this->by_beehiiv_id[ $beehiiv_id ] ?? null;
	}

	public function insert( array $post_args ): int {
		$id                    = $this->next_id++;
		$this->inserted[ $id ] = $post_args;
		return $id;
	}

	public function update( int $post_id, array $post_args ): void {
		$this->updated[ $post_id ] = $post_args;
	}

	public function set_meta( int $post_id, array $meta ): void {
		$this->meta[ $post_id ] = $meta;
	}

	public function set_terms( int $post_id, string $taxonomy, array $term_names, array $term_ids ): void {
		$this->terms[ $post_id ][] = [
			'taxonomy'   => $taxonomy,
			'term_names' => $term_names,
			'term_ids'   => $term_ids,
		];
	}

	public function sideload_featured_image( int $post_id, string $image_url ): void {
		$this->featured[ $post_id ] = $image_url;
	}
}
