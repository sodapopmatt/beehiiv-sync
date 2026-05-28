<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * Persists ImportRun state. Default implementation uses transients.
 *
 * Methods are intentionally tiny so the AS worker can read, mutate,
 * and save with no extra ceremony. Concurrent updates from parallel
 * workers may overwrite each other's counts — for our cadence
 * (one run at a time, ~few items per second) that's acceptable.
 */
final class RunRepository {

	private const TRANSIENT_PREFIX = 'beehiiv_sync_run_';
	private const PAYLOAD_PREFIX   = 'bs_item_';
	private const TTL              = 86400;
	private const PAYLOAD_TTL      = 3600;

	public function save( ImportRun $run ): void {
		set_transient( self::TRANSIENT_PREFIX . $run->run_id, $run->to_array(), self::TTL );
	}

	public function load( string $run_id ): ?ImportRun {
		$data = get_transient( self::TRANSIENT_PREFIX . $run_id );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return ImportRun::from_array( $data );
	}

	public function delete( string $run_id ): void {
		delete_transient( self::TRANSIENT_PREFIX . $run_id );
	}

	public function mutate( string $run_id, callable $fn ): ?ImportRun {
		$run = $this->load( $run_id );
		if ( $run === null ) {
			return null;
		}
		$fn( $run );
		$this->save( $run );
		return $run;
	}

	/**
	 * Stash a beehiiv post payload so the process_item worker can read
	 * it back. We do this because Action Scheduler limits action args
	 * to ~8 KB, and beehiiv posts with expanded content are 100+ KB.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function save_payload( string $run_id, string $beehiiv_id, array $payload ): bool {
		return (bool) set_transient(
			self::PAYLOAD_PREFIX . md5( $run_id . '|' . $beehiiv_id ),
			$payload,
			self::PAYLOAD_TTL
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function load_payload( string $run_id, string $beehiiv_id ): ?array {
		$data = get_transient( self::PAYLOAD_PREFIX . md5( $run_id . '|' . $beehiiv_id ) );
		return is_array( $data ) ? $data : null;
	}

	public function delete_payload( string $run_id, string $beehiiv_id ): void {
		delete_transient( self::PAYLOAD_PREFIX . md5( $run_id . '|' . $beehiiv_id ) );
	}
}
