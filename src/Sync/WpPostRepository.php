<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use RuntimeException;
use WP_Error;
use WP_Term;

final class WpPostRepository implements PostRepository {

	public function find_by_beehiiv_id( string $beehiiv_id ): ?array {
		$posts = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'   => '_beehiiv_post_id',
						'value' => $beehiiv_id,
					],
				],
				'no_found_rows'  => true,
			]
		);

		if ( ! is_array( $posts ) || $posts === [] ) {
			return null;
		}

		$post_id = (int) $posts[0];
		$hash    = get_post_meta( $post_id, '_beehiiv_content_hash', true );

		return [
			'id'           => $post_id,
			'content_hash' => is_string( $hash ) && $hash !== '' ? $hash : null,
		];
	}

	public function insert( array $post_args ): int {
		$result = wp_insert_post( $post_args, true );
		if ( $result instanceof WP_Error ) {
			throw new RuntimeException( 'wp_insert_post failed: ' . $result->get_error_message() );
		}
		return (int) $result;
	}

	public function update( int $post_id, array $post_args ): void {
		$post_args['ID'] = $post_id;
		$result          = wp_update_post( $post_args, true );
		if ( $result instanceof WP_Error ) {
			throw new RuntimeException( 'wp_update_post failed: ' . $result->get_error_message() );
		}
	}

	public function set_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			if ( $value === null ) {
				delete_post_meta( $post_id, $key );
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
	}

	public function set_terms( int $post_id, string $taxonomy, array $term_names, array $term_ids ): void {
		if ( $taxonomy === '' ) {
			return;
		}

		$ids = $term_ids;

		foreach ( $term_names as $name ) {
			$name = (string) $name;
			if ( $name === '' ) {
				continue;
			}
			$term = term_exists( $name, $taxonomy );
			if ( $term === null || $term === 0 ) {
				$created = wp_insert_term( $name, $taxonomy );
				if ( ! $created instanceof WP_Error && isset( $created['term_id'] ) ) {
					$ids[] = (int) $created['term_id'];
				}
				continue;
			}
			if ( is_array( $term ) && isset( $term['term_id'] ) ) {
				$ids[] = (int) $term['term_id'];
			} elseif ( is_numeric( $term ) ) {
				$ids[] = (int) $term;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( $ids === [] ) {
			return;
		}

		wp_set_object_terms( $post_id, $ids, $taxonomy, false );
	}

	public function sideload_featured_image( int $post_id, string $image_url ): void {
		if ( $image_url === '' || has_post_thumbnail( $post_id ) ) {
			return;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );
		if ( $attachment_id instanceof WP_Error || ! is_int( $attachment_id ) ) {
			return;
		}

		set_post_thumbnail( $post_id, $attachment_id );
	}
}
