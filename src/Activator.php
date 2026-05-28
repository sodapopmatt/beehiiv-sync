<?php
declare(strict_types=1);

namespace BeehiivSync;

use BeehiivSync\Support\Capabilities;

final class Activator {

	public const OPTION_SCHEMA_VERSION = 'beehiiv_sync_schema_version';
	public const CURRENT_SCHEMA        = 1;

	public static function activate(): void {
		Capabilities::grant_to_admin();

		if ( get_option( self::OPTION_SCHEMA_VERSION ) === false ) {
			add_option( self::OPTION_SCHEMA_VERSION, self::CURRENT_SCHEMA, '', false );
		} else {
			update_option( self::OPTION_SCHEMA_VERSION, self::CURRENT_SCHEMA, false );
		}
	}
}
