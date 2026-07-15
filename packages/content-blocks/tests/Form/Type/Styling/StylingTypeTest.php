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
        $this->assertFalse($form->has('alignSelf'));
    }

    public function testIncludeAlignSelfForBlocks(): void
    {
        $form = $this->factory->create(StylingType::class, null, [
            'include_align_self' => true,
        ]);

        $this->assertTrue($form->has('alignSelf'));
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
                'desktop' => ['top' => '10', 'right' => '20', 'bottom' => '10', 'left' => '20', 'linked' => '1'],
                'tablet' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
                'mobile' => ['top' => '5', 'right' => '5', 'bottom' => '5', 'left' => '5', 'linked' => '1'],
            ],
            'margin' => [
                'desktop' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
                'tablet' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
                'mobile' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'linked' => ''],
            ],
            // Compound palette color: the "Custom…" choice + free picker.
            'backgroundColor' => ['palette' => 'custom', 'custom' => '#ff0000'],
            'minHeight' => ['value' => '400', 'unit' => 'vh'],
            'verticalAlign' => 'center',
        ];

        $form->submit($submitted);

        $this->assertTrue($form->isSynchronized(), 'Form should round-trip cleanly');
        $data = $form->getData();

        $this->assertSame(10, $data['padding']['desktop']['top']);
        $this->assertSame(20, $data['padding']['desktop']['right']);
        $this->assertTrue($data['padding']['desktop']['linked']);
        $this->assertSame(5, $data['padding']['mobile']['top']);
        $this->assertSame('#ff0000', $data['backgroundColor']);
        $this->assertSame(400, $data['minHeight']['value']);
        $this->assertSame('vh', $data['minHeight']['unit']);
        $this->assertSame('center', $data['verticalAlign']);
    }
}
