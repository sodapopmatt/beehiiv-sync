<?php
declare(strict_types=1);

namespace BeehiivSync\Settings;

final class Options {

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( Schema::OPTION );
		if ( ! is_array( $stored ) ) {
			return Schema::defaults();
		}
		return array_replace_recursive( Schema::defaults(), $stored );
	}

	/**
	 * @param array<string, mixed> $input  User-provided patch.
	 * @return array<string, mixed>        The resulting full settings array.
	 */
	public function update( array $input ): array {
		$next = Schema::sanitize( $this->all(), $input );
		update_option( Schema::OPTION, $next, false );
		return $next;
	}
}
