<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\ContentTranslator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for should_skip_block_translation() and should_translate_block_fragment().
 */
class BlockTranslationTest extends TestCase {

	// -----------------------------------------------------------------------
	// should_skip_block_translation
	// -----------------------------------------------------------------------

	#[DataProvider( 'provideSkippedBlockNames' )]
	public function test_skips_code_like_block_types( string $blockName ): void {
		$block  = [ 'blockName' => $blockName, 'attrs' => [] ];
		$result = $this->invokeStatic( ContentTranslator::class, 'should_skip_block', [ $block ] );
		$this->assertTrue( $result );
	}

/** @return array<string, array{string}> */
public static function provideSkippedBlockNames(): array {
return [
'core/code'                   => [ 'core/code' ],
'core/preformatted'           => [ 'core/preformatted' ],
'core/html'                   => [ 'core/html' ],
'core/shortcode'              => [ 'core/shortcode' ],
'kevinbatdorf/code-block-pro' => [ 'kevinbatdorf/code-block-pro' ],
];
}

public function test_does_not_skip_regular_paragraph_block(): void {
$block  = [ 'blockName' => 'core/paragraph', 'attrs' => [] ];
$result = $this->invokeStatic( ContentTranslator::class, 'should_skip_block', [ $block ] );
$this->assertFalse( $result );
}

public function test_does_not_skip_heading_block(): void {
$block  = [ 'blockName' => 'core/heading', 'attrs' => [] ];
$result = $this->invokeStatic( ContentTranslator::class, 'should_skip_block', [ $block ] );
$this->assertFalse( $result );
}

public function test_skips_block_with_code_attr(): void {
$block  = [ 'blockName' => 'some/custom', 'attrs' => [ 'code' => 'echo "hi";' ] ];
$result = $this->invokeStatic( ContentTranslator::class, 'should_skip_block', [ $block ] );
$this->assertTrue( $result );
}

public function test_skips_block_with_codehtml_attr(): void {
$block  = [ 'blockName' => 'some/custom', 'attrs' => [ 'codeHTML' => '<b>bold</b>' ] ];
$result = $this->invokeStatic( ContentTranslator::class, 'should_skip_block', [ $block ] );
$this->assertTrue( $result );
}

public function test_does_not_skip_block_with_non_code_attrs(): void {
$block  = [ 'blockName' => 'core/image', 'attrs' => [ 'url' => 'http://example.com/img.png' ] ];
$result = $this->invokeStatic( ContentTranslator::class, 'should_skip_block', [ $block ] );
$this->assertFalse( $result );
}

public function test_handles_missing_block_name_gracefully(): void {
$block  = [ 'attrs' => [] ];
$result = $this->invokeStatic( ContentTranslator::class, 'should_skip_block', [ $block ] );
$this->assertFalse( $result );
}

// -----------------------------------------------------------------------
// should_translate_block_fragment
// -----------------------------------------------------------------------

public function test_translates_fragment_with_plain_text(): void {
$result = $this->invokeStatic( ContentTranslator::class, 'should_translate_fragment', [ 'Hello world' ] );
$this->assertTrue( $result );
}

public function test_translates_fragment_with_html(): void {
$result = $this->invokeStatic( ContentTranslator::class, 'should_translate_fragment', [ '<p>Translate me</p>' ] );
$this->assertTrue( $result );
}

public function test_skips_empty_fragment(): void {
$result = $this->invokeStatic( ContentTranslator::class, 'should_translate_fragment', [ '' ] );
$this->assertFalse( $result );
}

public function test_skips_whitespace_only_fragment(): void {
$result = $this->invokeStatic( ContentTranslator::class, 'should_translate_fragment', [ '   ' ] );
$this->assertFalse( $result );
}

public function test_skips_fragment_with_only_html_tags_no_text(): void {
// After stripping all tags, only whitespace remains.
$result = $this->invokeStatic( ContentTranslator::class, 'should_translate_fragment', [ '<br /><hr />' ] );
$this->assertFalse( $result );
}
}
