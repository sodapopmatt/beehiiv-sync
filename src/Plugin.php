<?php
declare(strict_types=1);

namespace BeehiivSync;

use BeehiivSync\Admin\Assets;
use BeehiivSync\Admin\Menu;
use BeehiivSync\Api\Credentials;
use BeehiivSync\Rest\CredentialsController;
use BeehiivSync\Rest\SettingsController;
use BeehiivSync\Settings\Options;
use BeehiivSync\Support\Capabilities;
use BeehiivSync\Support\Encryption;

final class Plugin {

	private static ?self $instance = null;

	public Menu $menu;
	public Assets $assets;

	public static function boot(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->menu   = new Menu();
		$this->assets = new Assets( $this->menu );
	}

	private function register_hooks(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ Capabilities::class, 'ensure_registered' ] );

		$this->menu->register();
		$this->assets->register();

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'beehiiv-sync', false, dirname( BS_BASENAME ) . '/languages' );
	}

	public function register_rest_routes(): void {
		( new CredentialsController( new Credentials( Encryption::from_wp_install() ) ) )->register_routes();
		( new SettingsController( new Options() ) )->register_routes();
	}
}
