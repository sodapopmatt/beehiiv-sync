<?php
declare(strict_types=1);

namespace BeehiivSync\Api\Exceptions;

final class RateLimitException extends ApiException {

	public function __construct(
		string $message,
		public readonly int $retry_after_seconds,
		?int $status_code = null,
		?string $response_body = null
	) {
		parent::__construct( $message, $status_code, $response_body );
	}
}
