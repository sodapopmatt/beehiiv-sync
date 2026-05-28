<?php
declare(strict_types=1);

namespace BeehiivSync\Rest;

use BeehiivSync\Api\Client;
use BeehiivSync\Api\Credentials;
use BeehiivSync\Api\Exceptions\ApiException;
use BeehiivSync\Api\Exceptions\AuthException;
use BeehiivSync\Api\WpHttpTransport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CredentialsController extends Controller {

	public function __construct( private readonly Credentials $credentials ) {}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/credentials',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_status' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'store' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'api_key'        => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => static fn( $v ) => trim( (string) $v ),
						],
						'publication_id' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => static fn( $v ) => trim( (string) $v ),
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'forget' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/credentials/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'api_key'        => [
						'type'              => 'string',
						'sanitize_callback' => static fn( $v ) => trim( (string) $v ),
					],
					'publication_id' => [
						'type'              => 'string',
						'sanitize_callback' => static fn( $v ) => trim( (string) $v ),
					],
				],
			]
		);
	}

	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'configured'     => $this->credentials->exists(),
				'publication_id' => $this->credentials->publication_id(),
			]
		);
	}

	public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$api_key        = (string) $request->get_param( 'api_key' );
		$publication_id = (string) $request->get_param( 'publication_id' );

		if ( $api_key === '' || $publication_id === '' ) {
			return new WP_Error( 'beehiiv_sync_invalid', 'API key and publication ID are required.', [ 'status' => 400 ] );
		}

		$probe = $this->probe( $api_key, $publication_id );
		if ( $probe instanceof WP_Error ) {
			return $probe;
		}

		$this->credentials->store( $api_key, $publication_id );

		return new WP_REST_Response(
			[
				'configured'  => true,
				'publication' => $probe,
			]
		);
	}

	public function forget( WP_REST_Request $request ): WP_REST_Response {
		$this->credentials->forget();
		return new WP_REST_Response( [ 'configured' => false ] );
	}

	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$api_key        = (string) ( $request->get_param( 'api_key' ) ?: $this->credentials->api_key() );
		$publication_id = (string) ( $request->get_param( 'publication_id' ) ?: $this->credentials->publication_id() );

		if ( $api_key === '' || $publication_id === '' ) {
			return new WP_Error( 'beehiiv_sync_no_credentials', 'No credentials provided or stored.', [ 'status' => 400 ] );
		}

		$probe = $this->probe( $api_key, $publication_id );
		if ( $probe instanceof WP_Error ) {
			return $probe;
		}

		return new WP_REST_Response( [ 'ok' => true, 'publication' => $probe ] );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function probe( string $api_key, string $publication_id ) {
		$client = new Client( $api_key, $publication_id, new WpHttpTransport() );
		try {
			return $client->get_publication();
		} catch ( AuthException $e ) {
			return new WP_Error( 'beehiiv_sync_auth_failed', 'Beehiiv rejected the credentials.', [ 'status' => 401 ] );
		} catch ( ApiException $e ) {
			return new WP_Error(
				'beehiiv_sync_api_error',
				$e->getMessage(),
				[ 'status' => 502 ]
			);
		}
	}
}
