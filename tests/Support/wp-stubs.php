<?php
/**
 * Minimal in-memory WordPress function stubs for unit tests.
 *
 * Only the functions exercised by pure-PHP unit tests live here (currently
 * the options API behind Support\Lock). Each is guarded by function_exists so
 * this file is a no-op under a real WordPress runtime.
 */
declare(strict_types=1);

$GLOBALS['__bs_test_options'] = [];

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		// Mirrors WP's unique-key behaviour: fails if the option already exists.
		if ( array_key_exists( $name, $GLOBALS['__bs_test_options'] ) ) {
			return false;
		}
		$GLOBALS['__bs_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__bs_test_options'] )
			? $GLOBALS['__bs_test_options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__bs_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['__bs_test_options'][ $name ] );
		return true;
	}
}
