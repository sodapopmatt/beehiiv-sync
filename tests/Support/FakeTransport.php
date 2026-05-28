<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Support;

use BeehiivSync\Api\HttpTransport;

final class FakeTransport implements HttpTransport {

	/** @var array<int, array{url:string, args:array<string,mixed>}> */
	public array $calls = [];

	/** @var array<int, array{status:int, headers:array<string,string>, body:string}> */
	private array $responses;

	/**
	 * @param array<int, array{status:int, headers?:array<string,string>, body?:string}> $responses
	 */
	public function __construct( array $responses ) {
		$this->responses = array_map(
			static fn( array $r ): array => [
				'status'  => $r['status'],
				'headers' => $r['headers'] ?? [],
				'body'    => $r['body'] ?? '',
			],
			$responses
		);
	}

	public function request( string $url, array $args ): array {
		$this->calls[] = [
			'url'  => $url,
			'args' => $args,
		];
		if ( $this->responses === [] ) {
			throw new \RuntimeException( 'FakeTransport: no more queued responses.' );
		}
		return array_shift( $this->responses );
	}
}
