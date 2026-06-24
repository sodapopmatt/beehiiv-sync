<?php
declare(strict_types=1);

namespace BeehiivSync\Rest;

use BeehiivSync\Api\Client;
use BeehiivSync\Api\Credentials;
use BeehiivSync\Api\Dto\BeehiivPost;
use BeehiivSync\Api\Exceptions\ApiException;
use BeehiivSync\Api\HttpTransport;
use BeehiivSync\Api\WpHttpTransport;
use BeehiivSync\Support\Logger;
use BeehiivSync\Sync\ContentSanitizer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Temporary diagnostic endpoint to debug missing content.
 *
 * Fetches one sample post from beehiiv, dumps the URL we called,
 * the raw response body, and the result of running it through
 * the sanitizer — so we can see where content gets lost.
 */
final class DiagnosticsController extends Controller {

	public function __construct( private readonly Credentials $credentials ) {}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/diagnostics/log/enabled',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_log_enabled' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'set_log_enabled' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'enabled' => [ 'type' => 'boolean', 'required' => true ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/diagnostics/sample',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'sample' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'status'   => [ 'type' => 'string', 'default' => 'confirmed' ],
					'audience' => [ 'type' => 'string', 'default' => 'all' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/diagnostics/log',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read_log' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'clear_log' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	public function get_log_enabled( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( [ 'enabled' => Logger::is_enabled() ] );
	}

	public function set_log_enabled( WP_REST_Request $request ): WP_REST_Response {
		$enabled = (bool) $request->get_param( 'enabled' );
		update_option( 'beehiiv_sync_debug_log', $enabled, false );
		return new WP_REST_Response( [ 'enabled' => $enabled ] );
	}

	public function read_log( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'path' => Logger::path(),
				'tail' => Logger::tail(),
			]
		);
	}

	public function clear_log( WP_REST_Request $request ): WP_REST_Response {
		Logger::clear();
		return new WP_REST_Response( [ 'ok' => true ] );
	}

	public function sample( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->credentials->exists() ) {
			return new WP_Error( 'no_credentials', 'Connect credentials first.', [ 'status' => 400 ] );
		}

		$status   = (string) $request->get_param( 'status' );
		$audience = (string) $request->get_param( 'audience' );

		$expand = match ( $audience ) {
			'free'    => [ 'free_web_content' ],
			'premium' => [ 'premium_web_content' ],
			default   => [ 'free_web_content', 'premium_web_content' ],
		};

		$recording_transport = new RecordingTransport( new WpHttpTransport() );

		$client = new Client(
			(string) $this->credentials->api_key(),
			(string) $this->credentials->publication_id(),
			$recording_transport
		);

		try {
			$page = $client->list_posts(
				[
					'page'   => 1,
					'limit'  => 1,
					'status' => $status,
					'expand' => $expand,
				] + ( $audience !== 'all' ? [ 'audience' => $audience ] : [] )
			);
		} catch ( ApiException $e ) {
			return new WP_REST_Response(
				[
					'stage'   => 'api_call',
					'error'   => $e->getMessage(),
					'request' => $recording_transport->last_request,
					'response' => [
						'status' => $e->status_code,
						'body'   => $e->response_body,
					],
				]
			);
		}

		$beehiiv  = $page->posts[0] ?? null;
		$raw_body = $recording_transport->last_response['body'] ?? '';

		if ( $beehiiv === null ) {
			return new WP_REST_Response(
				[
					'stage'   => 'empty_result',
					'request' => $recording_transport->last_request,
					'response_preview' => substr( $raw_body, 0, 2000 ),
				]
			);
		}

		$sanitizer       = new ContentSanitizer();
		$content_free    = $beehiiv->content_html['free'] ?? null;
		$content_premium = $beehiiv->content_html['premium'] ?? null;
		$chosen          = $content_free ?? $content_premium ?? '';
		$sanitized       = $sanitizer->sanitize( $chosen, $beehiiv->title );

		return new WP_REST_Response(
			[
				'request'  => $recording_transport->last_request,
				'response' => [
					'status' => $recording_transport->last_response['status'] ?? null,
					'body_length' => strlen( $raw_body ),
					'body_preview' => substr( $raw_body, 0, 2000 ),
				],
				'beehiiv_post' => [
					'id'                   => $beehiiv->id,
					'title'                => $beehiiv->title,
					'has_free_content'     => $content_free !== null,
					'has_premium_content'  => $content_premium !== null,
					'free_length'          => $content_free !== null ? strlen( $content_free ) : 0,
					'premium_length'       => $content_premium !== null ? strlen( $content_premium ) : 0,
					'free_preview'         => $content_free !== null ? substr( $content_free, 0, 500 ) : null,
					'raw_keys'             => array_keys( $beehiiv->raw ),
					'raw_content_keys'     => isset( $beehiiv->raw['content'] ) && is_array( $beehiiv->raw['content'] )
						? array_keys( $beehiiv->raw['content'] )
						: null,
				],
				'after_sanitize' => [
					'length'  => strlen( $sanitized ),
					'preview' => substr( $sanitized, 0, 500 ),
				],
			]
		);
	}
}

/**
 * Wraps an HttpTransport to record the last request URL/args and response.
 */
final class RecordingTransport implements HttpTransport {

	public ?array $last_request  = null;
	public ?array $last_response = null;

	public function __construct( private readonly HttpTransport $inner ) {}

	public function request( string $url, array $args ): array {
		$this->last_request = [
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
		];
		$response = $this->inner->request( $url, $args );
		$this->last_response = $response;
		return $response;
	}
}
