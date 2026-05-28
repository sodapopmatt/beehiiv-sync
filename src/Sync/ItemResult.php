<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

final class ItemResult {

	public function __construct(
		public readonly string $action,
		public readonly string $beehiiv_id,
		public readonly ?int $post_id,
		public readonly ?string $skip_reason = null,
	) {}
}
