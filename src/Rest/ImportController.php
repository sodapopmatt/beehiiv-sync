<?php
declare(strict_types=1);

namespace BeehiivSync\Rest;

use BeehiivSync\Api\Client;
use BeehiivSync\Api\Credentials;
use BeehiivSync\Api\Exceptions\ApiException;
use BeehiivSync\Api\Exceptions\AuthException;
use BeehiivSync\Api\Exceptions\RateLimitException;
use BeehiivSync\Api\WpHttpTransport;
use BeehiivSync\Settings\Options;
use BeehiivSync\Settings\Schema;
use BeehiivSync\Sync\ContentSanitizer;
use BeehiivSync\Sync\Importer;
use BeehiivSync\Sync\ImportParams;
use BeehiivSync\Sync\ImportPreview;
use BeehiivSync\Sync\ItemProcessor;
use BeehiivSync\Sync\PostMapper;
use BeehiivSync\Sync\RunRepository;
use BeehiivSync\Sync\WpPostRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ImportController extends Controller {

	public function __construct(
		private readonly Credentials $credentials,
		private readonly Options $options,
		private readonly RunRepository $runs,
	) {}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'start' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/(?P<run_id>[A-Za-z0-9_\-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'status' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'run_id' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->credentials->exists() ) {
			return new WP_Error( 'beehiiv_sync_no_credentials', 'Connect beehiiv credentials first.', [ 'status' => 400 ] );
		}

		$params  = $this->build_params( $request );
		$preview = new ImportPreview(
			$this->make_client(),
			new PostMapper( new ContentSanitizer() ),
			new WpPostRepository()
		);

		try {
			$result = $preview->build( $params );
		} catch ( AuthException $e ) {
			return new WP_Error( 'beehiiv_sync_auth', 'Beehiiv rejected your credentials. Reconnect them and try again.', [ 'status' => 401 ] );
		} catch ( RateLimitException $e ) {
			return new WP_Error( 'beehiiv_sync_rate_limited', 'Beehiiv rate limit hit while previewing. Try again shortly.', [ 'status' => 429 ] );
		} catch ( ApiException $e ) {
			$upstream = $e->status_code ?? 0;
			$message  = $upstream >= 500
				? sprintf( 'Beehiiv returned a server error (HTTP %d) while previewing. It may be temporarily overloaded — please wait a moment and try again.', $upstream )
				: $e->getMessage();
			return new WP_Error( 'beehiiv_sync_api', $message, [ 'status' => 502 ] );
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->credentials->exists() ) {
			return new WP_Error( 'beehiiv_sync_no_credentials', 'Connect beehiiv credentials first.', [ 'status' => 400 ] );
		}

		$params = $this->build_params( $request );

		$importer = new Importer(
			$this->make_client(),
			new ItemProcessor( new PostMapper( new ContentSanitizer() ), new WpPostRepository() ),
			$this->runs,
		);

		try {
			$run_id = $importer->start( $params );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'beehiiv_sync_schedule_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		return new WP_REST_Response( [ 'run_id' => $run_id, 'status' => 'queued' ], 202 );
	}

	private function build_params( WP_REST_Request $request ): ImportParams {
		$body  = $request->get_json_params();
		$input = is_array( $body ) ? $body : $request->get_params();
		$input = is_array( $input ) ? $input : [];

		$persisted_defaults = $this->options->all()['defaults'];
		$override_defaults  = is_array( $input['defaults'] ?? null ) ? $input['defaults'] : [];
		$merged_defaults    = Schema::sanitize_defaults( $persisted_defaults, $override_defaults );

		return ImportParams::build( $input, $merged_defaults );
	}

	private function make_client(): Client {
		return new Client(
			(string) $this->credentials->api_key(),
			(string) $this->credentials->publication_id(),
			new WpHttpTransport()
		);
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run = $this->runs->load( (string) $request->get_param( 'run_id' ) );
		if ( $run === null ) {
			return new WP_Error( 'beehiiv_sync_run_not_found', 'Run not found or expired.', [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $run->to_array() );
	}
}
