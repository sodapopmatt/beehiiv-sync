<?php
declare(strict_types=1);

namespace BeehiivSync\Api\Exceptions;

use RuntimeException;
use Throwable;

class ApiException extends RuntimeException {

	public function __construct(
		string $message,
		public readonly ?int $status_code = null,
		public readonly ?string $response_body = null,
		?Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}
}
