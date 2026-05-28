<?php
declare(strict_types=1);

namespace BeehiivSync\Admin;

final class Assets {

	public const HANDLE = 'beehiiv-sync-admin';

	public function __construct( private readonly Menu $menu ) {}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->menu->hook_suffix ) {
			return;
		}

		$asset_file = BS_DIR . 'build/admin/index.asset.php';
		$script_url = BS_URL . 'build/admin/index.js';
		$style_url  = BS_URL . 'build/admin/index.css';

		if ( ! is_readable( $asset_file ) ) {
			add_action( 'admin_notices', [ $this, 'render_build_notice' ] );
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			$script_url,
			$asset['dependencies'] ?? [],
			$asset['version'] ?? BS_VERSION,
			true
		);

		if ( file_exists( BS_DIR . 'build/admin/index.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$style_url,
				[ 'wp-components' ],
				$asset['version'] ?? BS_VERSION
			);
		}

		wp_set_script_translations( self::HANDLE, 'beehiiv-sync' );

		wp_add_inline_script(
			self::HANDLE,
			'window.beehiivSync = ' . wp_json_encode(
				[
					'apiUrl' => esc_url_raw( rest_url( 'beehiiv-sync/v1' ) ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
				]
			) . ';',
			'before'
		);
	}

	public function render_build_notice(): void {
		echo '<div class="notice notice-warning"><p><strong>Beehiiv Sync:</strong> admin UI assets not built. Run <code>npm install &amp;&amp; npm run build</code> in the plugin directory.</p></div>';
	}
}
