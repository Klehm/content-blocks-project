<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Icon;

use ContentBlocks\Icon\CoreUiIcons;
use ContentBlocks\Icon\UiIconProviderInterface;
use ContentBlocks\Icon\UiIconRegistry;
use ContentBlocks\Twig\UiIconExtension;
use PHPUnit\Framework\TestCase;

final class UiIconRegistryTest extends TestCase
{
    public function testAKnownNameComesBackWrappedInItsSvg(): void
    {
        $svg = (new UiIconRegistry([new CoreUiIcons()]))->svg('pos-tl');

        $this->assertNotNull($svg);
        $this->assertStringStartsWith('<svg class="cb-ui-icon" viewBox="0 0 20 20"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
    }

    public function testAnUnknownNameIsNull(): void
    {
        $registry = new UiIconRegistry([new CoreUiIcons()]);

        $this->assertNull($registry->svg('no-such-icon'));
        $this->assertFalse($registry->has('no-such-icon'));
    }

    public function testTheFirstProviderWinsSoAHostRedrawsACoreIcon(): void
    {
        $registry = new UiIconRegistry([
            $this->provider(['pos-tl' => '<circle r="1"/>', 'brand' => '<path d="M0 0"/>']),
            new CoreUiIcons(),
        ]);

        $this->assertStringContainsString('<circle r="1"/>', (string) $registry->svg('pos-tl'));
        $this->assertTrue($registry->has('brand'));
        $this->assertTrue($registry->has('pos-br'), 'the rest of the core set is still there');
    }

    public function testNamesListsEveryProviderOnce(): void
    {
        $registry = new UiIconRegistry([
            $this->provider(['pos-tl' => '<circle r="1"/>', 'brand' => '<path/>']),
            new CoreUiIcons(),
        ]);
        $names = $registry->names();

        $this->assertSame(\count(array_unique($names)), \count($names));
        $this->assertContains('brand', $names);
        $this->assertContains('display-slider', $names);
    }

    public function testTheTwigFunctionRendersNothingForAnUnknownName(): void
    {
        $extension = new UiIconExtension(new UiIconRegistry([new CoreUiIcons()]));

        $this->assertSame('', (string) $extension->icon('nope'));
        $this->assertStringContainsString('<svg', (string) $extension->icon('auto'));
    }

    /** @param array<string, string> $icons */
    private function provider(array $icons): UiIconProviderInterface
    {
        return new class ($icons) implements UiIconProviderInterface {
            /** @param array<string, string> $icons */
            public function __construct(private readonly array $icons)
            {
            }

            public function getIcons(): array
            {
                return $this->icons;
            }
        };
    }
}
