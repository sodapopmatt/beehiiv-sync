<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use BeehiivSync\Api\Client;
use BeehiivSync\Api\Credentials;
use BeehiivSync\Api\WpHttpTransport;
use BeehiivSync\Support\Encryption;

/**
 * Binds Action Scheduler hooks to the Importer.
 *
 * We build dependencies lazily inside the worker so that we read
 * the latest credentials from the DB and avoid loading anything
 * heavy on plain page loads.
 */
final class Hooks {

	public function register(): void {
		add_action( Importer::HOOK_FETCH_PAGE, [ $this, 'on_fetch_page' ], 10, 1 );
		add_action( Importer::HOOK_PROCESS_ITEM, [ $this, 'on_process_item' ], 10, 1 );
		add_action( Importer::HOOK_FINALIZE, [ $this, 'on_finalize' ], 10, 1 );
	}

	public function on_fetch_page( array $args ): void {
		$importer = $this->build_importer();
		if ( $importer === null ) {
			return;
		}
		$importer->on_fetch_page( $args );
	}

	public function on_process_item( array $args ): void {
		$importer = $this->build_importer();
		if ( $importer === null ) {
			return;
		}
		$importer->on_process_item( $args );
	}

	public function on_finalize( array $args ): void {
		$importer = $this->build_importer();
		if ( $importer === null ) {
			return;
		}
		$importer->on_finalize( $args );
	}

	private function build_importer(): ?Importer {
		$creds = new Credentials( Encryption::from_wp_install() );
		if ( ! $creds->exists() ) {
			return null;
		}

		$client     = new Client(
			(string) $creds->api_key(),
			(string) $creds->publication_id(),
			new WpHttpTransport()
		);
		$processor  = new ItemProcessor( new PostMapper( new ContentSanitizer() ), new WpPostRepository() );

		return new Importer( $client, $processor, new RunRepository() );
	}
}
