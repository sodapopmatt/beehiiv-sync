<?php
declare(strict_types=1);

namespace BeehiivSync\Rest;

use BeehiivSync\Support\Capabilities;
use WP_REST_Request;

abstract class Controller {

	public const NAMESPACE = 'beehiiv-sync/v1';

	abstract public function register_routes(): void;

	public function check_permission( WP_REST_Request $request ): bool {
		return current_user_can( Capabilities::MANAGE );
	}
}
