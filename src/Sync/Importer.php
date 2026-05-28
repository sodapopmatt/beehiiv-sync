<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use BeehiivSync\Api\Client;
use BeehiivSync\Api\Dto\BeehiivPost;
use BeehiivSync\Api\Exceptions\ApiException;
use BeehiivSync\Api\Exceptions\RateLimitException;
use BeehiivSync\Support\Logger;

/**
 * Coordinates an import run.
 *
 * `start()` enqueues the first page-fetch action and returns the run_id.
 * The remaining methods are the worker callbacks invoked by Action Scheduler.
 *
 * Concurrency model: one fetch_page action at a time per status. Each
 * fetch_page schedules N process_item actions plus either the next
 * page fetch or — when there are no more pages — moves on to the next
 * status, then finally schedules finalize().
 */
final class Importer {

	public const GROUP             = 'beehiiv-sync';
	public const HOOK_FETCH_PAGE   = 'beehiiv_sync/fetch_page';
	public const HOOK_PROCESS_ITEM = 'beehiiv_sync/process_item';
	public const HOOK_FINALIZE     = 'beehiiv_sync/finalize';

	public function __construct(
		private readonly Client $client,
		private readonly ItemProcessor $processor,
		private readonly RunRepository $runs,
	) {}

	public function start( ImportParams $params ): string {
		$run_id = $this->generate_run_id();
		$run    = new ImportRun( $run_id, ImportRun::STATUS_QUEUED, $params->to_array() );
		$this->runs->save( $run );

		as_schedule_single_action(
			time(),
			self::HOOK_FETCH_PAGE,
			[
				[
					'run_id'       => $run_id,
					'status_index' => 0,
					'page'         => 1,
				],
			],
			self::GROUP
		);

		return $run_id;
	}

	/**
	 * @param array{run_id:string, status_index:int, page:int} $args
	 */
	public function on_fetch_page( array $args ): void {
		$run_id       = (string) $args['run_id'];
		$status_index = (int) $args['status_index'];
		$page         = (int) $args['page'];

		$run = $this->runs->load( $run_id );
		if ( $run === null ) {
			return;
		}

		if ( $run->status === ImportRun::STATUS_QUEUED ) {
			$run->status = ImportRun::STATUS_RUNNING;
			$this->runs->save( $run );
		}

		$params  = ImportParams::from_array( $run->params );
		$status  = $params->beehiiv_statuses[ $status_index ] ?? null;
		if ( $status === null ) {
			$this->schedule_finalize( $run_id );
			return;
		}

		try {
			$result = $this->client->list_posts( $params->api_query( $status, $page ) );
		} catch ( RateLimitException $e ) {
			as_schedule_single_action(
				time() + $e->retry_after_seconds,
				self::HOOK_FETCH_PAGE,
				[ $args ],
				self::GROUP
			);
			return;
		} catch ( ApiException $e ) {
			$this->runs->mutate(
				$run_id,
				static function ( ImportRun $r ) use ( $e, $status, $page ): void {
					$r->record_error( "page:{$status}:{$page}", $e->getMessage() );
				}
			);
			$this->advance_after_page( $run_id, $status_index, $page, has_next_page: false );
			return;
		}

		$scheduled = 0;
		$failed    = 0;
		$this->runs->mutate(
			$run_id,
			static function ( ImportRun $r ) use ( $status, $page, $result ): void {
				$r->record_page( $status, $page, $result->total_pages, $result->total_results );
			}
		);

		foreach ( $result->posts as $post ) {
			$this->runs->save_payload( $run_id, $post->id, $post->raw );

			$action_id = as_schedule_single_action(
				time(),
				self::HOOK_PROCESS_ITEM,
				[
					[
						'run_id'     => $run_id,
						'beehiiv_id' => $post->id,
					],
				],
				self::GROUP
			);

			if ( $action_id ) {
				$scheduled++;
			} else {
				$failed++;
				$this->runs->delete_payload( $run_id, $post->id );
				Logger::error( 'item.schedule_failed', [ 'beehiiv_id' => $post->id ] );
			}
		}

		Logger::info(
			'fetch_page.scheduled',
			[
				'run_id'    => $run_id,
				'status'    => $status,
				'page'      => $page,
				'total'     => count( $result->posts ),
				'scheduled' => $scheduled,
				'failed'    => $failed,
			]
		);

		$has_next_page = $page < $result->total_pages;
		$this->advance_after_page( $run_id, $status_index, $page, $has_next_page );
	}

	/**
	 * @param array{run_id:string, beehiiv_id:string} $args
	 */
	public function on_process_item( array $args ): void {
		$run_id     = (string) $args['run_id'];
		$beehiiv_id = (string) ( $args['beehiiv_id'] ?? '' );

		Logger::info(
			'process_item.received',
			[ 'run_id' => $run_id, 'beehiiv_id' => $beehiiv_id ]
		);

		$run = $this->runs->load( $run_id );
		if ( $run === null ) {
			Logger::warn( 'process_item.run_missing', [ 'run_id' => $run_id ] );
			return;
		}

		$payload = $this->runs->load_payload( $run_id, $beehiiv_id );
		if ( $payload === null ) {
			Logger::warn( 'process_item.payload_missing', [ 'run_id' => $run_id, 'beehiiv_id' => $beehiiv_id ] );
			$this->runs->mutate(
				$run_id,
				static function ( ImportRun $r ) use ( $beehiiv_id ): void {
					$r->record_error( $beehiiv_id, 'payload transient expired or missing' );
				}
			);
			return;
		}

		$params  = ImportParams::from_array( $run->params );
		$beehiiv = BeehiivPost::from_array( $payload );

		try {
			$result = $this->processor->process( $beehiiv, $params->defaults );
			$this->runs->mutate(
				$run_id,
				static function ( ImportRun $r ) use ( $result ): void {
					$r->record_item( $result->action );
				}
			);
		} catch ( \Throwable $e ) {
			$this->runs->mutate(
				$run_id,
				static function ( ImportRun $r ) use ( $e, $beehiiv ): void {
					$r->record_error( $beehiiv->id, $e->getMessage() );
				}
			);
		} finally {
			$this->runs->delete_payload( $run_id, $beehiiv_id );
		}
	}

	/**
	 * @param array{run_id:string} $args
	 */
	public function on_finalize( array $args ): void {
		$this->runs->mutate(
			(string) $args['run_id'],
			static function ( ImportRun $r ): void {
				$r->complete( ImportRun::STATUS_COMPLETED );
			}
		);
	}

	private function advance_after_page( string $run_id, int $status_index, int $page, bool $has_next_page ): void {
		if ( $has_next_page ) {
			as_schedule_single_action(
				time(),
				self::HOOK_FETCH_PAGE,
				[
					[
						'run_id'       => $run_id,
						'status_index' => $status_index,
						'page'         => $page + 1,
					],
				],
				self::GROUP
			);
			return;
		}

		$run = $this->runs->load( $run_id );
		if ( $run === null ) {
			return;
		}

		$params       = ImportParams::from_array( $run->params );
		$next_index   = $status_index + 1;

		if ( isset( $params->beehiiv_statuses[ $next_index ] ) ) {
			as_schedule_single_action(
				time(),
				self::HOOK_FETCH_PAGE,
				[
					[
						'run_id'       => $run_id,
						'status_index' => $next_index,
						'page'         => 1,
					],
				],
				self::GROUP
			);
			return;
		}

		$this->schedule_finalize( $run_id );
	}

	private function schedule_finalize( string $run_id ): void {
		as_schedule_single_action(
			time(),
			self::HOOK_FINALIZE,
			[ [ 'run_id' => $run_id ] ],
			self::GROUP
		);
	}

	private function generate_run_id(): string {
		return 'run_' . wp_generate_uuid4();
	}
}
