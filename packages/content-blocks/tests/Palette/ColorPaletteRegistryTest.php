<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Palette;

use ContentBlocks\Palette\ColorPaletteProviderInterface;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\ConfigColorPaletteProvider;
use ContentBlocks\Palette\PaletteColor;
use PHPUnit\Framework\TestCase;

final class ColorPaletteRegistryTest extends TestCase
{
    public function testEmptyRegistry(): void
    {
        $registry = new ColorPaletteRegistry([]);

        $this->assertTrue($registry->isEmpty());
        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->getChoices());
        $this->assertSame([], $registry->getHexes());
    }

    public function testAggregatesProvidersInOrder(): void
    {
        $registry = new ColorPaletteRegistry([
            new ConfigColorPaletteProvider([
                ['label' => 'Primary', 'color' => '#eb0540'],
            ]),
            $this->provider(new PaletteColor('Dark', '#252525')),
        ]);

        $this->assertFalse($registry->isEmpty());
        $this->assertSame(
            ['Primary' => '#eb0540', 'Dark' => '#252525'],
            $registry->getChoices(),
        );
        $this->assertSame(['#eb0540', '#252525'], $registry->getHexes());
    }

    public function testLastProviderWinsOnHexCollision(): void
    {
        $registry = new ColorPaletteRegistry([
            new ConfigColorPaletteProvider([
                ['label' => 'Primary', 'color' => '#EB0540'],
            ]),
            $this->provider(new PaletteColor('Brand red', '#eb0540')),
        ]);

        // Re-labeled in place (position preserved), not duplicated.
        $this->assertSame(['Brand red' => '#eb0540'], $registry->getChoices());
        $this->assertSame(['#eb0540'], $registry->getHexes());
    }

    private function provider(PaletteColor ...$colors): ColorPaletteProviderInterface
    {
        return new class($colors) implements ColorPaletteProviderInterface {
            /** @param list<PaletteColor> $colors */
            public function __construct(private readonly array $colors)
            {
            }

            public function getColors(): iterable
            {
                return $this->colors;
            }
        };
    }
}
