<?php
declare(strict_types=1);

namespace BeehiivSync\Api;

use BeehiivSync\Api\Dto\BeehiivPost;
use BeehiivSync\Api\Dto\PostPage;
use BeehiivSync\Api\Exceptions\ApiException;
use BeehiivSync\Api\Exceptions\AuthException;
use BeehiivSync\Api\Exceptions\RateLimitException;

final class Client {

	public const BASE_URL = 'https://api.beehiiv.com/v2';
	public const TIMEOUT  = 30;

	public function __construct(
		private readonly string $api_key,
		private readonly string $publication_id,
		private readonly HttpTransport $transport,
		private readonly string $base_url = self::BASE_URL,
	) {}

	/**
	 * Validate credentials by fetching the publication record.
	 *
	 * @return array<string, mixed>
	 */
	public function get_publication(): array {
		$response = $this->request( 'GET', '/publications/' . rawurlencode( $this->publication_id ) );
		return $this->decode_body( $response['body'] );
	}

	/**
	 * @param array{
	 *   page?: int,
	 *   limit?: int,
	 *   audience?: string,
	 *   status?: string,
	 *   expand?: string[]|string,
	 *   order_by?: string,
	 *   direction?: string,
	 * } $params
	 */
	public function list_posts( array $params = [] ): PostPage {
		$query = array_merge(
			[
				'page'  => 1,
				'limit' => 50,
			],
			$params
		);

		if ( isset( $query['expand'] ) && is_array( $query['expand'] ) ) {
			$query['expand'] = implode( ',', $query['expand'] );
		}

		$response = $this->request(
			'GET',
			'/publications/' . rawurlencode( $this->publication_id ) . '/posts',
			$query
		);

		$decoded = $this->decode_body( $response['body'] );
		$raw     = is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [];
		$posts   = array_map( static fn( array $row ) => BeehiivPost::from_array( $row ), $raw );

		return new PostPage(
			posts: $posts,
			page: (int) ( $decoded['page'] ?? $query['page'] ),
			total_pages: (int) ( $decoded['total_pages'] ?? 1 ),
			total_results: (int) ( $decoded['total_results'] ?? count( $posts ) ),
		);
	}

	/**
	 * @param array<string, scalar> $query
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	private function request( string $method, string $path, array $query = [] ): array {
		$url = $this->base_url . $path;
		if ( $query !== [] ) {
			$url .= '?' . http_build_query( $query );
		}

		$args = [
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'BeehiivSync/' . ( defined( 'BS_VERSION' ) ? BS_VERSION : 'dev' ),
			],
		];

		$response = $this->transport->request( $url, $args );
		$status   = $response['status'];

		if ( $status === 401 || $status === 403 ) {
			throw new AuthException(
				'Beehiiv API rejected credentials.',
				status_code: $status,
				response_body: $response['body']
			);
		}

		if ( $status === 429 ) {
			$retry_after = (int) ( $response['headers']['retry-after']
				?? $response['headers']['x-ratelimit-reset']
				?? 30 );
			throw new RateLimitException(
				'Beehiiv API rate limit exceeded.',
				retry_after_seconds: max( 1, $retry_after ),
				status_code: $status,
				response_body: $response['body']
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new ApiException(
				sprintf( 'Beehiiv API returned HTTP %d.', $status ),
				status_code: $status,
				response_body: $response['body']
			);
		}

		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode_body( string $body ): array {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new ApiException( 'Beehiiv API returned non-JSON response.', response_body: $body );
		}
		return $decoded;
	}
}
