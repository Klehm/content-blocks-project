<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Section\ConfigSectionStyleProvider;
use ContentBlocks\Section\SectionStyleRegistry;
use PHPUnit\Framework\TestCase;

final class ConfigSectionStyleProviderTest extends TestCase
{
    public function testEmptyConfigYieldsNoStyles(): void
    {
        $registry = new SectionStyleRegistry([new ConfigSectionStyleProvider([])]);

        $this->assertSame([], $registry->all());
    }

    public function testConfigEntriesBecomeSectionStyles(): void
    {
        $registry = new SectionStyleRegistry([new ConfigSectionStyleProvider([
            [
                'name' => 'boxed',
                'label' => 'Boxed',
                'css_class' => 'my-section--boxed',
                'settings' => ['styling' => ['backgroundColor' => '#f5f5f5']],
            ],
            [
                // Class-only preset: css_class/settings are optional.
                'name' => 'bevel',
                'label' => 'Bevel',
                'css_class' => 'my-section--bevel',
            ],
            [
                // Settings-only preset.
                'name' => 'airy',
                'label' => 'Airy',
                'settings' => ['styling' => ['padding' => ['d' => ['top' => 80, 'bottom' => 80]]]],
            ],
        ])]);

        $boxed = $registry->get('boxed');
        $this->assertNotNull($boxed);
        $this->assertSame('Boxed', $boxed->label);
        $this->assertSame('my-section--boxed', $boxed->cssClass);
        $this->assertSame(['styling' => ['backgroundColor' => '#f5f5f5']], $boxed->settings);

        $this->assertSame([], $registry->get('bevel')->settings);
        $this->assertSame('', $registry->get('airy')->cssClass);
        $this->assertSame(
            ['Boxed' => 'boxed', 'Bevel' => 'bevel', 'Airy' => 'airy'],
            $registry->getChoices(),
        );
    }
}
