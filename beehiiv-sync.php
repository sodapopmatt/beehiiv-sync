<?php
/**
 * Plugin Name:       Beehiiv Sync
 * Plugin URI:        https://github.com/matt-ramirez/beehiiv-sync
 * Description:       Sync beehiiv newsletters into WordPress posts and embed beehiiv subscribe forms.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Matt Ramirez
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beehiiv-sync
 *
 * @package BeehiivSync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BS_VERSION', '0.1.0' );
define( 'BS_FILE', __FILE__ );
define( 'BS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BS_URL', plugin_dir_url( __FILE__ ) );
define( 'BS_BASENAME', plugin_basename( __FILE__ ) );

require_once BS_DIR . 'lib/action-scheduler/action-scheduler.php';

$bs_autoload = BS_DIR . 'vendor/autoload.php';
if ( is_readable( $bs_autoload ) ) {
	require_once $bs_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			if ( strpos( $class, 'BeehiivSync\\' ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( 'BeehiivSync\\' ) );
			$path     = BS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

register_activation_hook( __FILE__, [ \BeehiivSync\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \BeehiivSync\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\BeehiivSync\Plugin::boot();
	}
);
