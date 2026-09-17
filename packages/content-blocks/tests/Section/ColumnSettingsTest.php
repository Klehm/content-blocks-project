<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Section\ColumnSettings;
use PHPUnit\Framework\TestCase;

final class ColumnSettingsTest extends TestCase
{
    public function testKnownKeysOnlyAndATrimmedLabel(): void
    {
        $this->assertSame(
            ['label' => 'Specs'],
            ColumnSettings::sanitize(['label' => '  Specs ', 'onclick' => 'x', 'styling' => []]),
        );
    }

    /** Clipboard, import and templates all hand over untrusted payloads. */
    public function testMalformedPayloadsComeOutEmpty(): void
    {
        $this->assertSame([], ColumnSettings::sanitize(null));
        $this->assertSame([], ColumnSettings::sanitize('label'));
        $this->assertSame([], ColumnSettings::sanitize(['label' => ['nested']]));
        $this->assertSame([], ColumnSettings::sanitize(['label' => '   ']));
    }

    public function testTheLabelIsCappedWithoutSplittingACharacter(): void
    {
        $label = ColumnSettings::sanitize(['label' => str_repeat('é', 150)])['label'];

        $this->assertSame(ColumnSettings::LABEL_MAX_LENGTH, mb_strlen($label));
        $this->assertTrue(mb_check_encoding($label, 'UTF-8'));
    }

    public function testLabelReadsNullWhenAbsentOrBlank(): void
    {
        $this->assertSame('Tab', ColumnSettings::label(['label' => 'Tab']));
        $this->assertNull(ColumnSettings::label([]));
        $this->assertNull(ColumnSettings::label(['label' => ' ']));
        $this->assertNull(ColumnSettings::label(['label' => 3]));
    }
}
