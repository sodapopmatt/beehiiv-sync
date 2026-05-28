<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * Mutable state of one import run. Persisted in a transient
 * keyed by run_id; the import controller and AS workers read
 * and write it via RunRepository.
 */
final class ImportRun {

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	public int $started_at;
	public ?int $finished_at = null;
	public int $last_event_at;

	public int $items_seen     = 0;
	public int $inserted       = 0;
	public int $updated        = 0;
	public int $skipped        = 0;
	public int $expected_total = 0;
	public string $current_stage = 'Queued';

	/** @var array<string, int> total_results per beehiiv status, used to compute expected_total */
	public array $totals_by_status = [];

	/** @var array<int, array{beehiiv_id:string, message:string, at:int}> */
	public array $errors = [];

	/**
	 * @param array<string, mixed> $params  The import config (audience, status filter, etc.)
	 */
	public function __construct(
		public readonly string $run_id,
		public string $status,
		public readonly array $params,
	) {
		$this->started_at    = time();
		$this->last_event_at = time();
	}

	public function record_item( string $action ): void {
		$this->items_seen++;
		match ( $action ) {
			ImportPlan::ACTION_INSERT => $this->inserted++,
			ImportPlan::ACTION_UPDATE => $this->updated++,
			default                   => $this->skipped++,
		};
		$this->last_event_at = time();
		$this->current_stage = sprintf( 'Processing items (%d / %d)', $this->items_seen, max( $this->expected_total, $this->items_seen ) );
	}

	public function record_page( string $beehiiv_status, int $page, int $total_pages, int $total_results ): void {
		if ( ! isset( $this->totals_by_status[ $beehiiv_status ] ) ) {
			$this->totals_by_status[ $beehiiv_status ] = $total_results;
			$this->expected_total                       = array_sum( $this->totals_by_status );
		}
		$this->current_stage = sprintf(
			'Fetching %s page %d of %d',
			$beehiiv_status,
			$page,
			max( 1, $total_pages )
		);
		$this->last_event_at = time();
	}

	public function record_error( string $beehiiv_id, string $message ): void {
		$this->errors[] = [
			'beehiiv_id' => $beehiiv_id,
			'message'    => $message,
			'at'         => time(),
		];
		if ( count( $this->errors ) > 50 ) {
			$this->errors = array_slice( $this->errors, -50 );
		}
		$this->last_event_at = time();
	}

	public function complete( string $status ): void {
		$this->status        = $status;
		$this->finished_at   = time();
		$this->last_event_at = time();
		$this->current_stage = $status === self::STATUS_COMPLETED ? 'Completed' : 'Failed';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'run_id'        => $this->run_id,
			'status'        => $this->status,
			'started_at'    => $this->started_at,
			'finished_at'   => $this->finished_at,
			'last_event_at' => $this->last_event_at,
			'current_stage' => $this->current_stage,
			'params'        => $this->params,
			'counts'        => [
				'items_seen'     => $this->items_seen,
				'inserted'       => $this->inserted,
				'updated'        => $this->updated,
				'skipped'        => $this->skipped,
				'expected_total' => $this->expected_total,
			],
			'totals_by_status' => $this->totals_by_status,
			'errors'           => $this->errors,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$run                = new self(
			(string) $data['run_id'],
			(string) $data['status'],
			is_array( $data['params'] ?? null ) ? $data['params'] : []
		);
		$run->started_at    = (int) ( $data['started_at'] ?? time() );
		$run->finished_at   = isset( $data['finished_at'] ) ? (int) $data['finished_at'] : null;
		$run->last_event_at = (int) ( $data['last_event_at'] ?? $run->started_at );
		$run->current_stage = (string) ( $data['current_stage'] ?? 'Queued' );
		$counts             = is_array( $data['counts'] ?? null ) ? $data['counts'] : [];
		$run->items_seen    = (int) ( $counts['items_seen'] ?? 0 );
		$run->inserted      = (int) ( $counts['inserted'] ?? 0 );
		$run->updated       = (int) ( $counts['updated'] ?? 0 );
		$run->skipped       = (int) ( $counts['skipped'] ?? 0 );
		$run->expected_total = (int) ( $counts['expected_total'] ?? 0 );
		$run->totals_by_status = is_array( $data['totals_by_status'] ?? null ) ? $data['totals_by_status'] : [];
		$run->errors        = is_array( $data['errors'] ?? null ) ? $data['errors'] : [];
		return $run;
	}
}
