<?php
declare(strict_types=1);

namespace BeehiivSync\Settings;

final class Schema {

	public const OPTION = 'beehiiv_sync_settings';

	public const AUDIENCES   = [ 'free', 'premium', 'all' ];
	public const POST_STATUS = [ 'draft', 'pending', 'publish', 'private' ];
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
				'post_type'       => 'post',
				'post_status_map' => [
					'draft'     => 'draft',
					'confirmed' => 'publish',
					'archived'  => 'draft',
				],
				'author_id'           => 0,
				'audience'            => 'all',
				'tag_target'          => 'category',
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
	 * Merge user input with current settings, validating each leaf.
	 *
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $current, array $input ): array {
		$out = $current;

		if ( isset( $input['defaults'] ) && is_array( $input['defaults'] ) ) {
			$d   = $current['defaults'];
			$in  = $input['defaults'];

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

			$out['defaults'] = $d;
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
}
