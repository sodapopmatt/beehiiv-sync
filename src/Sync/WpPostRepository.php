<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use RuntimeException;
use WP_Error;
use WP_Term;

final class WpPostRepository implements PostRepository {

	/**
	 * Meta keys that store a beehiiv post id, newest first.
	 *
	 * `_beehiiv_post_id` is ours. `beehiiv_campaign_id` is written by the
	 * "Integration Toolkit for beehiiv" (ITFB) plugin with the same beehiiv
	 * `id` value, so we can adopt ITFB-imported posts by an exact id match
	 * instead of guessing by slug.
	 */
	private const ID_META_KEYS = [ '_beehiiv_post_id', 'beehiiv_campaign_id' ];

	public function find_existing( string $beehiiv_id, string $slug, string $post_type ): ?array {
		// 1. Exact match on a beehiiv-id meta key (ours or a prior importer's).
		$post_id = $this->find_id_by_beehiiv_id( $beehiiv_id );

		// 2. Last resort: a same-slug post with no id marker at all, so we adopt
		//    it rather than duplicate.
		if ( $post_id === null && $slug !== '' ) {
			$post_id = $this->find_adoptable_id_by_slug( $slug, $post_type, $beehiiv_id );
		}

		if ( $post_id === null ) {
			return null;
		}

		$hash = get_post_meta( $post_id, '_beehiiv_content_hash', true );

		return [
			'id'           => $post_id,
			'content_hash' => is_string( $hash ) && $hash !== '' ? $hash : null,
		];
	}

	private function find_id_by_beehiiv_id( string $beehiiv_id ): ?int {
		$meta_query = [ 'relation' => 'OR' ];
		foreach ( self::ID_META_KEYS as $key ) {
			$meta_query[] = [
				'key'   => $key,
				'value' => $beehiiv_id,
			];
		}

		$posts = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
				'no_found_rows'  => true,
			]
		);

		return is_array( $posts ) && $posts !== [] ? (int) $posts[0] : null;
	}

	private function find_adoptable_id_by_slug( string $slug, string $post_type, string $beehiiv_id ): ?int {
		$posts = get_posts(
			[
				'post_type'      => $post_type !== '' ? $post_type : 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'name'           => $slug,
				'no_found_rows'  => true,
			]
		);

		if ( ! is_array( $posts ) || $posts === [] ) {
			return null;
		}

		$post_id = (int) $posts[0];

		// Don't hijack a post that already belongs to a *different* beehiiv post
		// under any known id meta key (ours or a prior importer's).
		foreach ( self::ID_META_KEYS as $key ) {
			$claimed = get_post_meta( $post_id, $key, true );
			if ( is_string( $claimed ) && $claimed !== '' && $claimed !== $beehiiv_id ) {
				return null;
			}
		}

		return $post_id;
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
