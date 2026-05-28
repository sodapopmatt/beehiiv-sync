<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Sync;

use BeehiivSync\Api\Dto\BeehiivPost;
use BeehiivSync\Settings\Schema;
use BeehiivSync\Sync\ContentSanitizer;
use BeehiivSync\Sync\ImportPlan;
use BeehiivSync\Sync\PostMapper;
use PHPUnit\Framework\TestCase;

final class PostMapperTest extends TestCase {

	private function mapper(): PostMapper {
		return new PostMapper( new ContentSanitizer() );
	}

	private function defaults( array $overrides = [] ): array {
		return array_replace_recursive( Schema::defaults()['defaults'], $overrides );
	}

	private function beehiiv( array $overrides = [] ): BeehiivPost {
		return BeehiivPost::from_array(
			array_replace_recursive(
				[
					'id'           => 'post_1',
					'title'        => 'Hello world',
					'subtitle'     => 'A subtitle',
					'slug'         => 'hello-world',
					'status'       => 'confirmed',
					'audience'     => 'free',
					'publish_date' => 1700000000,
					'web_url'      => 'https://example.beehiiv.com/p/hello-world',
					'thumbnail'    => [ 'url' => 'https://cdn/x.jpg' ],
					'content'      => [ 'free' => [ 'web' => '<p>Hi</p>' ] ],
					'content_tags' => [ 'news' ],
				],
				$overrides
			)
		);
	}

	public function test_new_post_produces_insert_plan(): void {
		$plan = $this->mapper()->plan( $this->beehiiv(), $this->defaults() );

		self::assertSame( ImportPlan::ACTION_INSERT, $plan->action );
		self::assertNull( $plan->existing_post_id );
		self::assertSame( 'Hello world', $plan->post_args['post_title'] );
		self::assertSame( 'publish', $plan->post_args['post_status'] );
		self::assertSame( 'post_1', $plan->meta['_beehiiv_post_id'] );
		self::assertSame( 'https://cdn/x.jpg', $plan->featured_image_url );
		self::assertCount( 1, $plan->term_assignments );
		self::assertSame( 'category', $plan->term_assignments[0]['taxonomy'] );
		self::assertSame( [ 'news' ], $plan->term_assignments[0]['term_names'] );
	}

	public function test_fixed_term_assignment_is_included(): void {
		$plan = $this->mapper()->plan(
			$this->beehiiv(),
			$this->defaults(
				[
					'tag_target'     => 'post_tag',
					'fixed_taxonomy' => 'category',
					'fixed_term_id'  => 99,
				]
			)
		);

		$by_tax = array_column( $plan->term_assignments, null, 'taxonomy' );
		self::assertArrayHasKey( 'post_tag', $by_tax );
		self::assertArrayHasKey( 'category', $by_tax );
		self::assertSame( [ 99 ], $by_tax['category']['term_ids'] );
		self::assertSame( [ 'news' ], $by_tax['post_tag']['term_names'] );
	}

	public function test_fixed_term_merges_when_same_taxonomy_as_tag_target(): void {
		$plan = $this->mapper()->plan(
			$this->beehiiv(),
			$this->defaults(
				[
					'tag_target'     => 'category',
					'fixed_taxonomy' => 'category',
					'fixed_term_id'  => 99,
				]
			)
		);

		self::assertCount( 1, $plan->term_assignments );
		self::assertSame( 'category', $plan->term_assignments[0]['taxonomy'] );
		self::assertSame( [ 'news' ], $plan->term_assignments[0]['term_names'] );
		self::assertSame( [ 99 ], $plan->term_assignments[0]['term_ids'] );
	}

	public function test_existing_post_with_same_hash_is_skipped(): void {
		$mapper = $this->mapper();
		$first  = $mapper->plan( $this->beehiiv(), $this->defaults() );

		$plan = $mapper->plan(
			$this->beehiiv(),
			$this->defaults(),
			[ 'id' => 42, 'content_hash' => $first->content_hash ]
		);

		self::assertSame( ImportPlan::ACTION_SKIP, $plan->action );
		self::assertSame( 'unchanged', $plan->skip_reason );
		self::assertSame( 42, $plan->existing_post_id );
	}

	public function test_existing_post_with_different_hash_produces_update(): void {
		$plan = $this->mapper()->plan(
			$this->beehiiv(),
			$this->defaults(),
			[ 'id' => 42, 'content_hash' => 'stale-hash' ]
		);

		self::assertSame( ImportPlan::ACTION_UPDATE, $plan->action );
		self::assertSame( 42, $plan->existing_post_id );
	}

	public function test_import_mode_new_skips_existing(): void {
		$plan = $this->mapper()->plan(
			$this->beehiiv(),
			$this->defaults( [ 'import_mode' => 'new' ] ),
			[ 'id' => 42, 'content_hash' => 'anything' ]
		);

		self::assertSame( ImportPlan::ACTION_SKIP, $plan->action );
		self::assertSame( 'new_only_mode', $plan->skip_reason );
	}

	public function test_import_mode_update_skips_new(): void {
		$plan = $this->mapper()->plan(
			$this->beehiiv(),
			$this->defaults( [ 'import_mode' => 'update' ] ),
		);

		self::assertSame( ImportPlan::ACTION_SKIP, $plan->action );
		self::assertSame( 'existing_only_mode', $plan->skip_reason );
	}

	public function test_status_map_is_applied(): void {
		$plan = $this->mapper()->plan(
			$this->beehiiv( [ 'status' => 'archived' ] ),
			$this->defaults(),
		);
		self::assertSame( 'draft', $plan->post_args['post_status'] );
	}

	public function test_premium_only_content_falls_back_to_premium(): void {
		$plan = $this->mapper()->plan(
			$this->beehiiv(
				[
					'audience' => 'premium',
					'content'  => [ 'premium' => [ 'web' => '<p>Paid</p>' ] ],
				]
			),
			$this->defaults(),
		);
		self::assertStringContainsString( 'Paid', $plan->post_args['post_content'] );
		self::assertSame( 'premium', $plan->meta['_beehiiv_audience'] );
	}

	public function test_publish_date_is_set_in_gmt(): void {
		$plan = $this->mapper()->plan( $this->beehiiv(), $this->defaults() );
		self::assertSame( gmdate( 'Y-m-d H:i:s', 1700000000 ), $plan->post_args['post_date_gmt'] );
	}
}
