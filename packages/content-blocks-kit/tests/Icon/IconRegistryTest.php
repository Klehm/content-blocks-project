<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Icon;

use ContentBlocks\Kit\Block\IconBlock;
use ContentBlocks\Kit\Icon\IconProviderInterface;
use ContentBlocks\Kit\Icon\IconRegistry;
use ContentBlocks\Kit\Icon\IconSet;
use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use ContentBlocks\Kit\Twig\IconExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * `icon.name` was the one choice field where an unknown value cost the whole
 * block: `cb_kit_icon()` returned nothing, the view's `{% if %}` failed, and the
 * page rendered no markup at all. Widening `choices` made that state reachable
 * from configuration, so the fix is a seam that supplies the glyph alongside the
 * name — plus a fallback for the value that still slips through.
 */
final class IconRegistryTest extends TestCase
{
    private function provider(array $icons): IconProviderInterface
    {
        return new class($icons) implements IconProviderInterface {
            public function __construct(private readonly array $icons)
            {
            }

            public function icons(): array
            {
                return $this->icons;
            }
        };
    }

    public function testTheShippedSetIsAvailableWithNoProvider(): void
    {
        $registry = new IconRegistry();

        $this->assertSame(IconSet::all(), $registry->all());
        $this->assertTrue($registry->has('star'));
    }

    public function testAProviderAddsItsGlyphs(): void
    {
        $registry = new IconRegistry([$this->provider(['brand' => '<path d="M0 0h24v24H0z"/>'])]);

        $this->assertTrue($registry->has('brand'));
        $this->assertTrue($registry->has('star'), 'the shipped set is not displaced');
        $this->assertStringContainsString('M0 0h24v24H0z', (string) $registry->svg('brand'));
    }

    public function testAProviderReplacesAShippedGlyphOfTheSameName(): void
    {
        // The obvious way to restyle one kit icon without overriding a template.
        $registry = new IconRegistry([$this->provider(['star' => '<path d="M1 1"/>'])]);

        $this->assertSame('<path d="M1 1"/>', $registry->inner('star'));
    }

    public function testMalformedProviderEntriesAreSkipped(): void
    {
        $registry = new IconRegistry([$this->provider(['' => '<path/>', 'ok' => '<path/>', 'bad' => 42])]);

        $this->assertTrue($registry->has('ok'));
        $this->assertFalse($registry->has('bad'));
        $this->assertFalse($registry->has(''));
    }

    public function testSvgIsNullForAnUnknownName(): void
    {
        $this->assertNull((new IconRegistry())->svg('nope'));
    }

    public function testTheWrapperIsTheKitsForEveryGlyph(): void
    {
        // A contributed icon has to look like it belongs: the host supplies the
        // inner markup, the kit owns sizing, viewBox and currentColor.
        $registry = new IconRegistry([$this->provider(['brand' => '<path d="M1 1"/>'])]);

        $svg = (string) $registry->svg('brand', 48);

        $this->assertStringContainsString('width="48" height="48"', $svg);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
    }

    public function testTheBlocksPickerReflectsAProviderWithoutAnyConfig(): void
    {
        $registry = new IconRegistry([$this->provider(['brand-logo' => '<path d="M1 1"/>'])]);
        $block = new IconBlock([], [], [], $registry);

        $method = new \ReflectionMethod($block, 'choices');
        $values = array_values($method->invoke($block, 'name'));

        $this->assertContains('brand-logo', $values);
        $this->assertContains('star', $values);
    }

    public function testChoicesStillRestrictsWhatTheRegistryProduced(): void
    {
        $registry = new IconRegistry([$this->provider(['brand-logo' => '<path d="M1 1"/>'])]);
        $block = new IconBlock([], ['name' => ['brand-logo', 'star']], [], $registry);

        $method = new \ReflectionMethod($block, 'choices');

        $this->assertSame(['brand-logo', 'star'], array_values($method->invoke($block, 'name')));
    }

    public function testAContributedIconRendersInTheBlockView(): void
    {
        $html = $this->render(
            ['name' => 'brand-logo'],
            new IconRegistry([$this->provider(['brand-logo' => '<path d="M9 9h6v6H9z"/>'])]),
        );

        $this->assertStringContainsString('M9 9h6v6H9z', $html);
        $this->assertStringContainsString('cb-kit-icon', $html);
    }

    public function testAnUnknownIconFallsBackInsteadOfRenderingNothing(): void
    {
        // The regression this seam exists to prevent. Stored data older than an
        // icon set, or a `choices` map naming a glyph nobody contributed, must
        // not silently erase the block.
        $html = $this->render(['name' => 'does-not-exist'], new IconRegistry());

        $this->assertStringContainsString('cb-kit-icon', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    private function render(array $data, IconRegistry $registry): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new IconExtension($registry));
        $env->addExtension(new ChoiceTokenExtension());

        return $env->render('@ContentBlocksKit/block/icon/view.html.twig', ['data' => $data]);
    }
}
