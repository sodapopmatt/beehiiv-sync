<?php
declare(strict_types=1);

namespace BeehiivSync\Support;

use WP_Role;

final class Capabilities {

	public const MANAGE = 'manage_beehiiv_sync';

	public static function grant_to_admin(): void {
		$role = get_role( 'administrator' );
		if ( $role instanceof WP_Role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	public static function revoke_from_admin(): void {
		$role = get_role( 'administrator' );
		if ( $role instanceof WP_Role && $role->has_cap( self::MANAGE ) ) {
			$role->remove_cap( self::MANAGE );
		}
	}

	public static function ensure_registered(): void {
		if ( ! is_admin() ) {
			return;
		}
		$role = get_role( 'administrator' );
		if ( $role instanceof WP_Role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}
}
