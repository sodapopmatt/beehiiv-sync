<?php
declare(strict_types=1);

namespace BeehiivSync\Support;

/**
 * Append-only debug log written to the uploads directory.
 *
 * Lives at: <uploads>/beehiiv-sync/debug.log
 * Auto-rotates: when the file passes ROTATE_BYTES the file is moved
 * to debug.log.1 (overwriting any previous rotation).
 */
final class Logger {

	private const SUBDIR       = 'beehiiv-sync';
	private const FILENAME     = 'debug.log';
	private const ROTATE_BYTES = 1_048_576;

	public static function info( string $event, array $context = [] ): void {
		self::write( 'INFO', $event, $context );
	}

	public static function warn( string $event, array $context = [] ): void {
		self::write( 'WARN', $event, $context );
	}

	public static function error( string $event, array $context = [] ): void {
		self::write( 'ERROR', $event, $context );
	}

	public static function clear(): bool {
		$path = self::path();
		if ( $path === null ) {
			return false;
		}
		return @file_put_contents( $path, '' ) !== false;
	}

	/**
	 * @return string|null  Absolute path to the log file, or null if uploads dir is not writable.
	 */
	public static function path(): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		return $dir . '/' . self::FILENAME;
	}

	/**
	 * @return string  Last N bytes of the log; empty string if no log exists.
	 */
	public static function tail( int $max_bytes = 200_000 ): string {
		$path = self::path();
		if ( $path === null || ! is_readable( $path ) ) {
			return '';
		}
		$size = filesize( $path );
		if ( $size === false || $size === 0 ) {
			return '';
		}
		$start = max( 0, $size - $max_bytes );
		$fh    = fopen( $path, 'rb' );
		if ( $fh === false ) {
			return '';
		}
		fseek( $fh, $start );
		$data = stream_get_contents( $fh );
		fclose( $fh );
		return is_string( $data ) ? $data : '';
	}

	private static function write( string $level, string $event, array $context ): void {
		$path = self::path();
		if ( $path === null ) {
			return;
		}

		if ( is_file( $path ) && filesize( $path ) > self::ROTATE_BYTES ) {
			@rename( $path, $path . '.1' );
		}

		$context_json = $context === [] ? '' : ' ' . wp_json_encode( $context );
		$line         = sprintf(
			"[%s] %-5s %s%s\n",
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$level,
			$event,
			$context_json === false ? '' : $context_json
		);

		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}
}
