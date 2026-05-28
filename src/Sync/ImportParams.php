<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

use BeehiivSync\Settings\Schema;

/**
 * Validated parameters for one import invocation.
 *
 * Bundles together the request-level overrides (audience, status
 * filter) with the persisted defaults from settings, so the
 * importer doesn't have to consult both layers.
 */
final class ImportParams {

	/**
	 * @param string[]             $beehiiv_statuses  Filter sent to beehiiv: confirmed/draft/archived.
	 * @param array<string, mixed> $defaults          The 'defaults' block from settings.
	 */
	public function __construct(
		public readonly string $audience,
		public readonly array $beehiiv_statuses,
		public readonly int $per_page,
		public readonly array $defaults,
	) {}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $defaults
	 */
	public static function build( array $input, array $defaults ): self {
		$audience = isset( $input['audience'] ) && in_array( $input['audience'], Schema::AUDIENCES, true )
			? $input['audience']
			: (string) ( $defaults['audience'] ?? 'all' );

		$valid    = [ 'draft', 'confirmed', 'archived' ];
		$statuses = isset( $input['beehiiv_statuses'] ) && is_array( $input['beehiiv_statuses'] )
			? array_values( array_intersect( $valid, $input['beehiiv_statuses'] ) )
			: [ 'confirmed' ];

		if ( $statuses === [] ) {
			$statuses = [ 'confirmed' ];
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 50;
		$per_page = max( 1, min( 100, $per_page ) );

		return new self( $audience, $statuses, $per_page, $defaults );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'audience'         => $this->audience,
			'beehiiv_statuses' => $this->beehiiv_statuses,
			'per_page'         => $this->per_page,
			'defaults'         => $this->defaults,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			audience: (string) ( $data['audience'] ?? 'all' ),
			beehiiv_statuses: is_array( $data['beehiiv_statuses'] ?? null ) ? $data['beehiiv_statuses'] : [ 'confirmed' ],
			per_page: (int) ( $data['per_page'] ?? 50 ),
			defaults: is_array( $data['defaults'] ?? null ) ? $data['defaults'] : [],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function api_query( string $status, int $page ): array {
		$query = [
			'page'   => $page,
			'limit'  => $this->per_page,
			'status' => $status,
			'expand' => $this->expand_for_audience(),
		];
		if ( $this->audience !== 'all' ) {
			$query['audience'] = $this->audience;
		}
		return $query;
	}

	/**
	 * Beehiiv requires explicit `expand` to return post bodies.
	 *
	 * @return string[]
	 */
	private function expand_for_audience(): array {
		return match ( $this->audience ) {
			'free'    => [ 'free_web_content' ],
			'premium' => [ 'premium_web_content' ],
			default   => [ 'free_web_content', 'premium_web_content' ],
		};
	}
}
