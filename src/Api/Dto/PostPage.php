<?php
declare(strict_types=1);

namespace BeehiivSync\Api\Dto;

final class PostPage {

	/**
	 * @param BeehiivPost[] $posts
	 */
	public function __construct(
		public readonly array $posts,
		public readonly int $page,
		public readonly int $total_pages,
		public readonly int $total_results,
	) {}
}
