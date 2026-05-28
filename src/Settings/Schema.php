<?php
declare(strict_types=1);

namespace BeehiivSync\Settings;

final class Schema {

	public const OPTION = 'beehiiv_sync_settings';

	public const AUDIENCES   = [ 'free', 'premium', 'all' ];
	public const POST_STATUS = [ 'draft', 'pending', 'publish', 'private', 'future' ];
	public const TAG_TARGETS = [ 'category', 'post_tag', 'none' ];
	public const IMPORT_MODE = [ 'new', 'update', 'both' ];
	public const FREQUENCY   = [ 'hourly', 'daily', 'weekly' ];

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'schema_version' => 1,
			'defaults'       => [
				'post_type'           => 'post',
				'post_status_map'     => [
					'draft'     => 'draft',
					'confirmed' => 'draft',
					'archived'  => 'draft',
				],
				'author_id'           => 0,
				'audience'            => 'all',
				'tag_target'          => 'post_tag',
				'fixed_taxonomy'      => '',
				'fixed_term_id'       => 0,
				'import_mode'         => 'both',
				'reconcile_deletions' => false,
			],
			'schedule' => [
				'enabled'   => false,
				'frequency' => 'daily',
				'hour'      => 3,
				'minute'    => 0,
				'weekday'   => 1,
			],
		];
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $current, array $input ): array {
		$out = $current;

		if ( isset( $input['defaults'] ) && is_array( $input['defaults'] ) ) {
			$out['defaults'] = self::sanitize_defaults( $current['defaults'], $input['defaults'] );
		}

		if ( isset( $input['schedule'] ) && is_array( $input['schedule'] ) ) {
			$s  = $current['schedule'];
			$in = $input['schedule'];

			if ( isset( $in['enabled'] ) ) {
				$s['enabled'] = (bool) $in['enabled'];
			}
			if ( isset( $in['frequency'] ) && in_array( $in['frequency'], self::FREQUENCY, true ) ) {
				$s['frequency'] = $in['frequency'];
			}
			if ( isset( $in['hour'] ) ) {
				$s['hour'] = max( 0, min( 23, (int) $in['hour'] ) );
			}
			if ( isset( $in['minute'] ) ) {
				$s['minute'] = max( 0, min( 59, (int) $in['minute'] ) );
			}
			if ( isset( $in['weekday'] ) ) {
				$s['weekday'] = max( 0, min( 6, (int) $in['weekday'] ) );
			}

			$out['schedule'] = $s;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	public static function sanitize_defaults( array $current, array $in ): array {
		$d = $current;

		if ( isset( $in['post_type'] ) ) {
			$d['post_type'] = sanitize_key( (string) $in['post_type'] );
		}
		if ( isset( $in['author_id'] ) ) {
			$d['author_id'] = max( 0, (int) $in['author_id'] );
		}
		if ( isset( $in['audience'] ) && in_array( $in['audience'], self::AUDIENCES, true ) ) {
			$d['audience'] = $in['audience'];
		}
		if ( isset( $in['tag_target'] ) && in_array( $in['tag_target'], self::TAG_TARGETS, true ) ) {
			$d['tag_target'] = $in['tag_target'];
		}
		if ( isset( $in['fixed_taxonomy'] ) ) {
			$d['fixed_taxonomy'] = sanitize_key( (string) $in['fixed_taxonomy'] );
		}
		if ( isset( $in['fixed_term_id'] ) ) {
			$d['fixed_term_id'] = max( 0, (int) $in['fixed_term_id'] );
		}
		if ( isset( $in['import_mode'] ) && in_array( $in['import_mode'], self::IMPORT_MODE, true ) ) {
			$d['import_mode'] = $in['import_mode'];
		}
		if ( isset( $in['reconcile_deletions'] ) ) {
			$d['reconcile_deletions'] = (bool) $in['reconcile_deletions'];
		}
		if ( isset( $in['post_status_map'] ) && is_array( $in['post_status_map'] ) ) {
			foreach ( [ 'draft', 'confirmed', 'archived' ] as $key ) {
				if ( isset( $in['post_status_map'][ $key ] )
					&& in_array( $in['post_status_map'][ $key ], self::POST_STATUS, true ) ) {
					$d['post_status_map'][ $key ] = $in['post_status_map'][ $key ];
				}
			}
		}

		return $d;
	}
}
