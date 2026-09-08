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

    public function testAllStaysAContiguousListAcrossACollision(): void
    {
        // A collision overwrites an earlier index rather than appending, so the
        // keys must still come back 0..n-1 for callers that index positionally.
        $registry = new ColorPaletteRegistry([
            $this->provider(
                new PaletteColor('First', '#111111'),
                new PaletteColor('Second', '#222222'),
            ),
            $this->provider(new PaletteColor('First again', '#111111')),
        ]);

        $this->assertSame([0, 1], array_keys($registry->all()));
    }

    private function provider(PaletteColor ...$colors): ColorPaletteProviderInterface
    {
        return new class ($colors) implements ColorPaletteProviderInterface {
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
