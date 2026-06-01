<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Sync;

use BeehiivSync\Api\Dto\BeehiivPost;
use BeehiivSync\Settings\Schema;
use BeehiivSync\Sync\ContentSanitizer;
use BeehiivSync\Sync\ImportPlan;
use BeehiivSync\Sync\ItemProcessor;
use BeehiivSync\Sync\PostMapper;
use BeehiivSync\Tests\Support\FakePostRepository;
use PHPUnit\Framework\TestCase;

final class ItemProcessorTest extends TestCase {

	private function processor( FakePostRepository $repo ): ItemProcessor {
		return new ItemProcessor( new PostMapper( new ContentSanitizer() ), $repo );
	}

	private function beehiiv(): BeehiivPost {
		return BeehiivPost::from_array(
			[
				'id'           => 'post_1',
				'title'        => 'Hi',
				'slug'         => 'hi',
				'status'       => 'confirmed',
				'audience'     => 'free',
				'publish_date' => 1700000000,
				'thumbnail'    => [ 'url' => 'https://cdn/x.jpg' ],
				'content'      => [ 'free' => [ 'web' => '<p>hi</p>' ] ],
				'content_tags' => [ 'news' ],
			]
		);
	}

	public function test_insert_path_writes_all_artifacts(): void {
		$repo   = new FakePostRepository();
		$result = $this->processor( $repo )->process( $this->beehiiv(), Schema::defaults()['defaults'] );

		self::assertSame( ImportPlan::ACTION_INSERT, $result->action );
		self::assertNotNull( $result->post_id );
		self::assertCount( 1, $repo->inserted );
		self::assertSame( 'post_1', $repo->meta[ $result->post_id ]['_beehiiv_post_id'] );
		self::assertSame( 'post_tag', $repo->terms[ $result->post_id ][0]['taxonomy'] );
		self::assertSame( 'https://cdn/x.jpg', $repo->featured[ $result->post_id ] );
	}

	public function test_update_path_calls_update_not_insert(): void {
		$repo = new FakePostRepository();
		$repo->seed( 'post_1', 99, 'stale' );

		$result = $this->processor( $repo )->process( $this->beehiiv(), Schema::defaults()['defaults'] );

		self::assertSame( ImportPlan::ACTION_UPDATE, $result->action );
		self::assertSame( 99, $result->post_id );
		self::assertCount( 0, $repo->inserted );
		self::assertArrayHasKey( 99, $repo->updated );
	}

	public function test_skip_path_makes_no_writes(): void {
		$repo  = new FakePostRepository();
		$plan  = ( new PostMapper( new ContentSanitizer() ) )->plan( $this->beehiiv(), Schema::defaults()['defaults'] );
		$repo->seed( 'post_1', 99, $plan->content_hash );

		$result = $this->processor( $repo )->process( $this->beehiiv(), Schema::defaults()['defaults'] );

		self::assertSame( ImportPlan::ACTION_SKIP, $result->action );
		self::assertSame( 99, $result->post_id );
		self::assertSame( 'unchanged', $result->skip_reason );
		self::assertCount( 0, $repo->inserted );
		self::assertCount( 0, $repo->updated );
		self::assertCount( 0, $repo->meta );
	}
}
