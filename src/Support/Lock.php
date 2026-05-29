<?php
declare(strict_types=1);

namespace BeehiivSync\Support;

/**
 * A lightweight, cross-process named mutex.
 *
 * Action Scheduler runs workers concurrently, so two workers can pick up
 * the same beehiiv post (e.g. when the list endpoint returns it on two
 * pages) and both miss the "already imported?" check, producing a duplicate.
 *
 * We serialize per-id work with an atomic lock built on `add_option()`:
 * the options table has a UNIQUE index on `option_name`, so the underlying
 * INSERT either succeeds for exactly one caller or fails for the rest. That
 * gives us a DB-level guarantee no two workers hold the same lock at once,
 * without adding a custom table.
 *
 * Locks self-expire: if a worker dies holding one, the next caller past the
 * TTL steals it so an id is never wedged forever.
 */
final class Lock {

	private const PREFIX      = 'bs_lock_';
	private const DEFAULT_TTL = 120;

	/**
	 * Attempt to acquire the lock. Returns true only for the caller that wins.
	 */
	public static function acquire( string $key, int $ttl = self::DEFAULT_TTL ): bool {
		$name    = self::option_name( $key );
		$expires = time() + max( 1, $ttl );

		// Atomic: succeeds for one caller, fails (duplicate key) for the rest.
		// autoload 'no' keeps these out of the alloptions cache.
		if ( add_option( $name, (string) $expires, '', 'no' ) ) {
			return true;
		}

		// Someone holds it. Steal only if it's clearly stale.
		$held = (int) get_option( $name, 0 );
		if ( $held > 0 && $held < time() ) {
			update_option( $name, (string) $expires, false );
			return true;
		}

		return false;
	}

	public static function release( string $key ): void {
		delete_option( self::option_name( $key ) );
	}

	private static function option_name( string $key ): string {
		// md5 keeps us under the option_name length limit for arbitrary ids.
		return self::PREFIX . md5( $key );
	}
}
