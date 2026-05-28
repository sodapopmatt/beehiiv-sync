<?php
declare(strict_types=1);

namespace BeehiivSync\Admin;

use BeehiivSync\Support\Capabilities;

final class Menu {

	public const SLUG = 'beehiiv-sync';

	public string $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Beehiiv Sync', 'beehiiv-sync' ),
			__( 'Beehiiv Sync', 'beehiiv-sync' ),
			Capabilities::MANAGE,
			self::SLUG,
			[ $this, 'render' ],
			self::icon_svg(),
			58
		);
	}

	public function render(): void {
		echo '<div class="wrap"><div id="beehiiv-sync-app"></div></div>';
	}

	/**
	 * Inline SVG for the menu icon (a stylized bee).
	 */
	public static function icon_svg(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black">'
			. '<path d="M10 1.5a3.5 3.5 0 0 0-3.46 3H5a1 1 0 1 0 0 2h.5a4.5 4.5 0 0 0 .54 1.5H5a1 1 0 0 0 0 2h1.04A4.5 4.5 0 0 0 5.5 11.5H5a1 1 0 1 0 0 2h1.04A3.5 3.5 0 0 0 10 18.5a3.5 3.5 0 0 0 3.96-5H15a1 1 0 1 0 0-2h-.5A4.5 4.5 0 0 0 13.96 10H15a1 1 0 1 0 0-2h-1.04A4.5 4.5 0 0 0 14.5 6.5H15a1 1 0 1 0 0-2h-1.54A3.5 3.5 0 0 0 10 1.5Zm0 2a1.5 1.5 0 0 1 1.5 1.5h-3A1.5 1.5 0 0 1 10 3.5Z"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
