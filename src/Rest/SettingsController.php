<?php
declare(strict_types=1);

namespace BeehiivSync\Rest;

use BeehiivSync\Settings\Options;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsController extends Controller {

	public function __construct( private readonly Options $options ) {}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'write' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	public function read( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->options->all() );
	}

	public function write( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		return new WP_REST_Response( $this->options->update( is_array( $body ) ? $body : [] ) );
	}
}
