<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Api;

use BeehiivSync\Api\Client;
use BeehiivSync\Api\Exceptions\ApiException;
use BeehiivSync\Api\Exceptions\AuthException;
use BeehiivSync\Api\Exceptions\RateLimitException;
use BeehiivSync\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {

	private function client( FakeTransport $transport ): Client {
		return new Client( 'sk_test', 'pub_123', $transport );
	}

	public function test_get_publication_sends_bearer_and_returns_decoded_body(): void {
		$transport = new FakeTransport(
			[
				[
					'status' => 200,
					'body'   => '{"id":"pub_123","name":"My Newsletter"}',
				],
			]
		);

		$result = $this->client( $transport )->get_publication();

		self::assertSame( 'My Newsletter', $result['name'] );
		self::assertCount( 1, $transport->calls );
		self::assertStringContainsString( '/publications/pub_123', $transport->calls[0]['url'] );
		self::assertSame( 'Bearer sk_test', $transport->calls[0]['args']['headers']['Authorization'] );
		self::assertSame( 'GET', $transport->calls[0]['args']['method'] );
	}

	public function test_list_posts_maps_dtos_and_passes_query(): void {
		$body = json_encode(
			[
				'page'          => 1,
				'total_pages'   => 2,
				'total_results' => 60,
				'data'          => [
					[
						'id'           => 'post_1',
						'title'        => 'Hello',
						'slug'         => 'hello',
						'status'       => 'confirmed',
						'audience'     => 'free',
						'publish_date' => 1700000000,
						'content'      => [ 'free' => [ 'web' => '<p>hi</p>' ] ],
						'content_tags' => [ 'news', '' ],
					],
				],
			]
		);

		$transport = new FakeTransport( [ [ 'status' => 200, 'body' => $body ] ] );

		$page = $this->client( $transport )->list_posts(
			[ 'page' => 1, 'audience' => 'free', 'expand' => [ 'free_web_content' ] ]
		);

		self::assertSame( 2, $page->total_pages );
		self::assertCount( 1, $page->posts );
		self::assertSame( 'post_1', $page->posts[0]->id );
		self::assertSame( [ 'news' ], $page->posts[0]->content_tags );
		self::assertSame( '<p>hi</p>', $page->posts[0]->content_html['free'] );

		$url = $transport->calls[0]['url'];
		self::assertStringContainsString( 'page=1', $url );
		self::assertStringContainsString( 'audience=free', $url );
		// Beehiiv requires `expand[]=` array notation (URL-encoded as %5B%5D=).
		self::assertStringContainsString( 'expand%5B%5D=free_web_content', $url );
		self::assertStringNotContainsString( 'expand%5B0%5D', $url );
	}

	public function test_401_throws_auth_exception(): void {
		$transport = new FakeTransport( [ [ 'status' => 401, 'body' => '{"error":"unauthorized"}' ] ] );
		$this->expectException( AuthException::class );
		$this->client( $transport )->get_publication();
	}

	public function test_429_throws_rate_limit_with_retry_after(): void {
		$transport = new FakeTransport(
			[
				[
					'status'  => 429,
					'headers' => [ 'retry-after' => '42' ],
					'body'    => '',
				],
			]
		);

		try {
			$this->client( $transport )->get_publication();
			self::fail( 'Expected RateLimitException.' );
		} catch ( RateLimitException $e ) {
			self::assertSame( 42, $e->retry_after_seconds );
		}
	}

	public function test_5xx_throws_api_exception(): void {
		$transport = new FakeTransport( [ [ 'status' => 503, 'body' => 'oops' ] ] );
		$this->expectException( ApiException::class );
		$this->client( $transport )->get_publication();
	}

	public function test_non_json_body_throws(): void {
		$transport = new FakeTransport( [ [ 'status' => 200, 'body' => '<html>nope</html>' ] ] );
		$this->expectException( ApiException::class );
		$this->client( $transport )->get_publication();
	}
}
