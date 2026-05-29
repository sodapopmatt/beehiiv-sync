<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use BeehiivSync\Api\Client;

/**
 * Synchronous dry-run of an import.
 *
 * Walks every page of the selected beehiiv statuses, runs each post through
 * the same PostMapper the real importer uses, and reports what *would* happen
 * (new / update / unchanged / skip) without writing anything to WordPress.
 *
 * This drives the preview screen so the user can pick exactly which posts to
 * import. It is intentionally synchronous — the caller is a REST request, not
 * an Action Scheduler worker — so a hard cap keeps very large publications
 * from blowing the request timeout.
 */
final class ImportPreview {

	public const ACTION_NEW       = 'new';
	public const ACTION_UPDATE    = 'update';
	public const ACTION_UNCHANGED = 'unchanged';
	public const ACTION_SKIP      = 'skip';

	private const MAX_ITEMS = 1000;

	public function __construct(
		private readonly Client $client,
		private readonly PostMapper $mapper,
		private readonly PostRepository $repository,
	) {}

	/**
	 * @return array{
	 *   items: array<int, array<string, mixed>>,
	 *   summary: array<string, int>,
	 *   truncated: bool
	 * }
	 */
	public function build( ImportParams $params, int $max_items = self::MAX_ITEMS ): array {
		$items     = [];
		$summary   = [
			self::ACTION_NEW       => 0,
			self::ACTION_UPDATE    => 0,
			self::ACTION_UNCHANGED => 0,
			self::ACTION_SKIP      => 0,
			'total'                => 0,
		];
		$scanned   = 0;
		$truncated = false;

		foreach ( $params->beehiiv_statuses as $beehiiv_status ) {
			$page = 1;
			do {
				$result = $this->client->list_posts( $params->api_query( $beehiiv_status, $page ) );

				foreach ( $result->posts as $post ) {
					if ( $scanned >= $max_items ) {
						$truncated = true;
						break 3;
					}
					$scanned++;

					$existing = $this->repository->find_existing(
						$post->id,
						$post->slug,
						(string) ( $params->defaults['post_type'] ?? 'post' )
					);
					$plan     = $this->mapper->plan( $post, $params->defaults, $existing );
					$action   = $this->classify( $plan );

					$summary[ $action ]++;
					$summary['total']++;

					// Only surface posts that would actually be imported (new or
					// update). Unchanged/skipped posts are reflected in the summary
					// counts but kept out of the selectable list.
					if ( $action !== self::ACTION_NEW && $action !== self::ACTION_UPDATE ) {
						continue;
					}

					$items[] = [
						'beehiiv_id'       => $post->id,
						'title'            => $post->title,
						'beehiiv_status'   => $post->status,
						'publish_date'     => $post->publish_date,
						'web_url'          => $post->web_url,
						'action'           => $action,
						'skip_reason'      => $plan->skip_reason,
						'existing_post_id' => $plan->existing_post_id,
						'target_status'    => $plan->post_args['post_status'] ?? null,
						'selectable'       => true,
					];
				}

				$page++;
			} while ( $page <= $result->total_pages );
		}

		// Newest first for display. Posts without a publish date sort last.
		usort(
			$items,
			static fn( array $a, array $b ): int => ( $b['publish_date'] ?? 0 ) <=> ( $a['publish_date'] ?? 0 )
		);

		return [
			'items'     => $items,
			'summary'   => $summary,
			'truncated' => $truncated,
		];
	}

	private function classify( ImportPlan $plan ): string {
		if ( $plan->action === ImportPlan::ACTION_INSERT ) {
			return self::ACTION_NEW;
		}
		if ( $plan->action === ImportPlan::ACTION_UPDATE ) {
			return self::ACTION_UPDATE;
		}
		if ( $plan->skip_reason === 'unchanged' ) {
			return self::ACTION_UNCHANGED;
		}
		return self::ACTION_SKIP;
	}
}
