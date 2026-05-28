<?php
declare(strict_types=1);

namespace BeehiivSync\Api;

use BeehiivSync\Api\Exceptions\ApiException;

/**
 * Pluggable HTTP transport. The default implementation wraps
 * wp_remote_request; tests inject a fake.
 *
 * Implementations MUST return a normalized array:
 *   [ 'status' => int, 'headers' => array<string,string>, 'body' => string ]
 *
 * Network-level failures MUST throw ApiException.
 */
interface HttpTransport {

	/**
	 * @param array<string, mixed> $args  Method, headers, body, timeout.
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public function request( string $url, array $args ): array;
}
