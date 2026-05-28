<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Api;

use BeehiivSync\Api\Dto\BeehiivPost;
use PHPUnit\Framework\TestCase;

final class BeehiivPostTest extends TestCase {

	public function test_from_array_extracts_known_fields(): void {
		$post = BeehiivPost::from_array(
			[
				'id'           => 'post_42',
				'title'        => 'Greetings',
				'subtitle'     => 'A subtitle',
				'slug'         => 'greetings',
				'status'       => 'confirmed',
				'audience'     => 'premium',
				'publish_date' => 1700000000,
				'web_url'      => 'https://example.beehiiv.com/p/greetings',
				'thumbnail'    => [ 'url' => 'https://cdn/x.jpg' ],
				'content'      => [
					'free'    => [ 'web' => '<p>free</p>' ],
					'premium' => [ 'web' => '<p>premium</p>' ],
				],
				'content_tags' => [ 'a', 'b' ],
			]
		);

		self::assertSame( 'post_42', $post->id );
		self::assertSame( 'A subtitle', $post->subtitle );
		self::assertSame( 1700000000, $post->publish_date );
		self::assertSame( 'https://cdn/x.jpg', $post->thumbnail_url );
		self::assertSame( '<p>free</p>', $post->content_html['free'] );
		self::assertSame( '<p>premium</p>', $post->content_html['premium'] );
		self::assertSame( [ 'a', 'b' ], $post->content_tags );
	}

	public function test_from_array_tolerates_missing_optional_fields(): void {
		$post = BeehiivPost::from_array(
			[
				'id'    => 'post_min',
				'title' => 'x',
				'slug'  => 'x',
			]
		);

		self::assertNull( $post->subtitle );
		self::assertNull( $post->publish_date );
		self::assertNull( $post->thumbnail_url );
		self::assertSame( [], $post->content_html );
		self::assertSame( [], $post->content_tags );
	}

	public function test_thumbnail_url_falls_back_to_flat_field(): void {
		$post = BeehiivPost::from_array(
			[ 'id' => 'x', 'title' => 't', 'slug' => 's', 'thumbnail_url' => 'https://cdn/y.jpg' ]
		);
		self::assertSame( 'https://cdn/y.jpg', $post->thumbnail_url );
	}
}
