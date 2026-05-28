<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use BeehiivSync\Api\Dto\BeehiivPost;
use BeehiivSync\Support\Logger;

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

		if ( class_exists( Logger::class ) ) {
			Logger::info(
				'item.plan',
				[
					'beehiiv_id'     => $beehiiv->id,
					'title'          => $beehiiv->title,
					'has_free'       => isset( $beehiiv->content_html['free'] ),
					'has_premium'    => isset( $beehiiv->content_html['premium'] ),
					'free_length'    => isset( $beehiiv->content_html['free'] ) ? strlen( $beehiiv->content_html['free'] ) : 0,
					'premium_length' => isset( $beehiiv->content_html['premium'] ) ? strlen( $beehiiv->content_html['premium'] ) : 0,
					'final_content_length' => isset( $plan->post_args['post_content'] ) ? strlen( (string) $plan->post_args['post_content'] ) : 0,
					'action'         => $plan->action,
					'skip_reason'    => $plan->skip_reason,
					'existing_id'    => $plan->existing_post_id,
				]
			);
		}

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

		foreach ( $plan->term_assignments as $assignment ) {
			$this->repository->set_terms(
				$post_id,
				$assignment['taxonomy'],
				$assignment['term_names'],
				$assignment['term_ids'],
			);
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
