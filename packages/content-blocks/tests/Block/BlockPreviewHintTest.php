<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Block;

use ContentBlocks\BlockType\BlockPreviewHint;
use PHPUnit\Framework\TestCase;

final class BlockPreviewHintTest extends TestCase
{
    public function testTextIsCollapsedOntoOneLine(): void
    {
        $hint = BlockPreviewHint::text("Two lines\n  and  loose   spacing ");

        $this->assertSame('Two lines and loose spacing', $hint->text);
    }

    public function testLongTextIsTruncatedWithAnEllipsis(): void
    {
        $hint = BlockPreviewHint::heading(str_repeat('a', 300));

        $this->assertSame(121, mb_strlen((string) $hint->text), '120 characters plus the ellipsis');
        $this->assertStringEndsWith('…', (string) $hint->text);
    }

    /**
     * A tile is a few characters wide — cutting mid-word is expected. What
     * must not happen is a trailing space stranded before the ellipsis.
     */
    public function testTruncationDoesNotStrandASpaceBeforeTheEllipsis(): void
    {
        $hint = BlockPreviewHint::text(str_repeat('ab ', 100));

        $this->assertStringNotContainsString(' …', (string) $hint->text);
    }

    public function testEmptyTextDegradesToGeneric(): void
    {
        foreach ([null, '', '   ', "\n\t"] as $empty) {
            $this->assertSame(
                BlockPreviewHint::KIND_GENERIC,
                BlockPreviewHint::heading($empty)->kind,
                var_export($empty, true),
            );
            $this->assertSame(BlockPreviewHint::KIND_GENERIC, BlockPreviewHint::text($empty)->kind);
        }
    }

    /**
     * A picture that was never uploaded must not produce an image tile — the
     * renderer would draw an empty frame where the block's name belongs.
     */
    public function testImageWithoutASourceDegradesToGenericAndKeepsItsCaption(): void
    {
        $hint = BlockPreviewHint::image('  ', 'Team photo');

        $this->assertSame(BlockPreviewHint::KIND_GENERIC, $hint->kind);
        $this->assertNull($hint->image);
        $this->assertSame('Team photo', $hint->text);
    }

    public function testImageKeepsItsSourceUntouched(): void
    {
        $hint = BlockPreviewHint::image(' /uploads/a.png ');

        $this->assertSame(BlockPreviewHint::KIND_IMAGE, $hint->kind);
        $this->assertSame('/uploads/a.png', $hint->image);
    }

    /**
     * Unlike a heading, an unlabelled button is still a button: the pill is
     * the information, so it keeps its kind rather than degrading.
     */
    public function testButtonWithoutALabelStaysAButton(): void
    {
        $hint = BlockPreviewHint::button(null);

        $this->assertSame(BlockPreviewHint::KIND_BUTTON, $hint->kind);
        $this->assertNull($hint->text);
    }

    public function testRuleCarriesNothingElse(): void
    {
        $hint = BlockPreviewHint::rule();

        $this->assertSame(BlockPreviewHint::KIND_RULE, $hint->kind);
        $this->assertNull($hint->text);
        $this->assertNull($hint->image);
    }
}
