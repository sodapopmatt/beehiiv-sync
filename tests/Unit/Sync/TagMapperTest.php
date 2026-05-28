<?php
declare(strict_types=1);

namespace BeehiivSync\Tests\Unit\Sync;

use BeehiivSync\Sync\TagMapper;
use PHPUnit\Framework\TestCase;

final class TagMapperTest extends TestCase {

	public function test_target_category_returns_category_taxonomy(): void {
		$out = TagMapper::resolve( [ 'news', 'tech' ], 'category' );
		self::assertSame( 'category', $out['taxonomy'] );
		self::assertSame( [ 'news', 'tech' ], $out['term_names'] );
	}

	public function test_target_post_tag_returns_post_tag_taxonomy(): void {
		$out = TagMapper::resolve( [ 'news' ], 'post_tag' );
		self::assertSame( 'post_tag', $out['taxonomy'] );
	}

	public function test_target_none_returns_empty(): void {
		$out = TagMapper::resolve( [ 'news' ], 'none' );
		self::assertSame( '', $out['taxonomy'] );
		self::assertSame( [], $out['term_names'] );
	}

	public function test_strips_blanks_and_dedupes(): void {
		$out = TagMapper::resolve( [ 'news', ' ', 'news', 'tech' ], 'category' );
		self::assertSame( [ 'news', 'tech' ], $out['term_names'] );
	}
}
