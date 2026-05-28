<?php
/**
 * Fires when the plugin is deleted from the WordPress admin.
 *
 * @package BeehiivSync
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'beehiiv_sync_schema_version' );
delete_option( 'beehiiv_sync_settings' );

$role = get_role( 'administrator' );
if ( $role && $role->has_cap( 'manage_beehiiv_sync' ) ) {
	$role->remove_cap( 'manage_beehiiv_sync' );
}
