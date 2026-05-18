<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type\Styling;

use ContentBlocks\Form\Type\Styling\StylingType;
use Symfony\Component\Form\Test\TypeTestCase;

final class StylingTypeTest extends TypeTestCase
{
    public function testDefaultsBuildPaddingMarginAndBackgroundOnly(): void
    {
        $form = $this->factory->create(StylingType::class);

        $this->assertTrue($form->has('padding'));
        $this->assertTrue($form->has('margin'));
        $this->assertTrue($form->has('backgroundColor'));
        $this->assertFalse($form->has('minHeight'));
        $this->assertFalse($form->has('maxWidth'));
        $this->assertFalse($form->has('verticalAlign'));
    }

    public function testIncludeMinHeightAndAlignmentForSections(): void
    {
        $form = $this->factory->create(StylingType::class, null, [
            'include_min_height' => true,
            'include_alignment' => true,
        ]);

        $this->assertTrue($form->has('minHeight'));
        $this->assertTrue($form->has('verticalAlign'));
    }

    public function testIncludeMaxWidthForBlocks(): void
    {
        $form = $this->factory->create(StylingType::class, null, [
            'include_max_width' => true,
        ]);

        $this->assertTrue($form->has('maxWidth'));
    }

    public function testSubmitRoundTripPreservesData(): void
    {
        $form = $this->factory->create(StylingType::class, null, [
            'include_min_height' => true,
            'include_alignment' => true,
        ]);

        $submitted = [
            'padding' => [
                'd' => ['top' => '10', 'right' => '20', 'bottom' => '10', 'left' => '20', 'linked' => '1'],
                't' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
                'm' => ['top' => '5', 'right' => '5', 'bottom' => '5', 'left' => '5', 'linked' => '1'],
            ],
            'margin' => [
                'd' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
                't' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
                'm' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
            ],
            'backgroundColor' => '#ff0000',
            'minHeight' => ['value' => '400', 'unit' => 'vh'],
            'verticalAlign' => 'center',
        ];

        $form->submit($submitted);

        $this->assertTrue($form->isSynchronized(), 'Form should round-trip cleanly');
        $data = $form->getData();

        $this->assertSame(10, $data['padding']['d']['top']);
        $this->assertSame(20, $data['padding']['d']['right']);
        $this->assertTrue($data['padding']['d']['linked']);
        $this->assertSame(5, $data['padding']['m']['top']);
        $this->assertSame('#ff0000', $data['backgroundColor']);
        $this->assertSame(400, $data['minHeight']['value']);
        $this->assertSame('vh', $data['minHeight']['unit']);
        $this->assertSame('center', $data['verticalAlign']);
    }
}
