<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Security;

use ContentBlocks\Entity\Section;
use PHPUnit\Framework\TestCase;

final class LayoutValidationTest extends TestCase
{
    private const ALLOWED_LAYOUTS = [
        Section::LAYOUT_FULL,
        Section::LAYOUT_TWO_COLS,
        Section::LAYOUT_THREE_COLS,
    ];

    public function testAllowedLayoutsAreValid(): void
    {
        foreach (self::ALLOWED_LAYOUTS as $layout) {
            $this->assertTrue(
                \in_array($layout, self::ALLOWED_LAYOUTS, true),
                sprintf('Layout "%s" should be allowed', $layout),
            );
        }
    }

    public function testInvalidLayoutIsRejected(): void
    {
        $this->assertFalse(\in_array('evil_layout', self::ALLOWED_LAYOUTS, true));
        $this->assertFalse(\in_array('', self::ALLOWED_LAYOUTS, true));
        $this->assertFalse(\in_array('FULL', self::ALLOWED_LAYOUTS, true));
    }

    public function testLayoutConstantsHaveExpectedValues(): void
    {
        $this->assertSame('full', Section::LAYOUT_FULL);
        $this->assertSame('two_cols', Section::LAYOUT_TWO_COLS);
        $this->assertSame('three_cols', Section::LAYOUT_THREE_COLS);
    }
}
