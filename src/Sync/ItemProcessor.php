<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use BeehiivSync\Api\Dto\BeehiivPost;

/**
 * Applies an ImportPlan to WordPress via the PostRepository.
 *
 * Pure orchestration; everything that touches WP goes through
 * the injected repository so this class is fully unit-testable.
 */
final class ItemProcessor {

	public function __construct(
		private readonly PostMapper $mapper,
		private readonly PostRepository $repository,
	) {}

	/**
	 * @param array<string, mixed> $settings_defaults
	 */
	public function process( BeehiivPost $beehiiv, array $settings_defaults ): ItemResult {
		$existing = $this->repository->find_by_beehiiv_id( $beehiiv->id );
		$plan     = $this->mapper->plan( $beehiiv, $settings_defaults, $existing );

		if ( $plan->action === ImportPlan::ACTION_SKIP ) {
			return new ItemResult(
				action: ImportPlan::ACTION_SKIP,
				beehiiv_id: $plan->beehiiv_id,
				post_id: $plan->existing_post_id,
				skip_reason: $plan->skip_reason,
			);
		}

		$post_id = $plan->action === ImportPlan::ACTION_INSERT
			? $this->repository->insert( $plan->post_args )
			: (int) $plan->existing_post_id;

		if ( $plan->action === ImportPlan::ACTION_UPDATE ) {
			$this->repository->update( $post_id, $plan->post_args );
		}

		$this->repository->set_meta( $post_id, $plan->meta );

		if ( $plan->taxonomy !== '' && $plan->term_names !== [] ) {
			$this->repository->set_terms( $post_id, $plan->taxonomy, $plan->term_names );
		}

		if ( $plan->featured_image_url !== null && $plan->featured_image_url !== '' ) {
			$this->repository->sideload_featured_image( $post_id, $plan->featured_image_url );
		}

		return new ItemResult(
			action: $plan->action,
			beehiiv_id: $plan->beehiiv_id,
			post_id: $post_id,
		);
	}
}
