<?php
declare(strict_types=1);

namespace BeehiivSync\Sync;

/**
 * Resolves beehiiv content_tags onto a target taxonomy.
 */
final class TagMapper {

	/**
	 * @param string[] $beehiiv_tags
	 * @return array{taxonomy:string, term_names:string[]}
	 */
	public static function resolve( array $beehiiv_tags, string $tag_target ): array {
		if ( $tag_target === 'none' || $beehiiv_tags === [] ) {
			return [ 'taxonomy' => '', 'term_names' => [] ];
		}

		$taxonomy = $tag_target === 'post_tag' ? 'post_tag' : 'category';

		$clean = [];
		foreach ( $beehiiv_tags as $tag ) {
			$tag = trim( (string) $tag );
			if ( $tag !== '' ) {
				$clean[] = $tag;
			}
		}

		return [ 'taxonomy' => $taxonomy, 'term_names' => array_values( array_unique( $clean ) ) ];
	}
}
