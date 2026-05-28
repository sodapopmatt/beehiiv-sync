<?php
declare(strict_types=1);

namespace BeehiivSync\Api;

use BeehiivSync\Api\Exceptions\ApiException;
use WP_Error;

final class WpHttpTransport implements HttpTransport {

	public function request( string $url, array $args ): array {
		$response = wp_remote_request( $url, $args );

		if ( $response instanceof WP_Error ) {
			throw new ApiException( 'HTTP transport error: ' . $response->get_error_message() );
		}

		$headers_obj = $response['headers'] ?? [];
		$headers     = [];
		if ( is_object( $headers_obj ) && method_exists( $headers_obj, 'getAll' ) ) {
			foreach ( $headers_obj->getAll() as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
			}
		} elseif ( is_array( $headers_obj ) ) {
			foreach ( $headers_obj as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
			}
		}

		return [
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		];
	}
}
