<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the title view to guard the size/tag split: the visual size and the
 * semantic element are independent, so a <h2> can carry an h1 size class.
 */
final class TitleViewTest extends TestCase
{
    public function testSizeAndTagAreIndependent(): void
    {
        // Semantic h2, but sized like an h1.
        $html = $this->render(['text' => 'Hello', 'tag' => 'h2', 'size' => 'h1']);

        $this->assertStringContainsString('<h2 class="cb-kit-title cb-kit-title--h1"', $html);
        $this->assertStringContainsString('>Hello</h2>', $html);
    }

    public function testTagDrivesTheElement(): void
    {
        $html = $this->render(['text' => 'Hi', 'tag' => 'span', 'size' => 'h3']);

        $this->assertStringContainsString('<span class="cb-kit-title cb-kit-title--h3"', $html);
        $this->assertStringContainsString('</span>', $html);
    }

    public function testInvalidValuesFallBackToH2(): void
    {
        $html = $this->render(['text' => 'X', 'tag' => 'script', 'size' => 'huge']);

        $this->assertStringContainsString('<h2 class="cb-kit-title cb-kit-title--h2"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('huge', $html);
    }

    public function testLegacyBlockWithoutSizeFollowsTheTag(): void
    {
        // Saved before the size field existed: size absent → visual scale
        // follows the tag so the title doesn't shrink to the h2 default.
        $html = $this->render(['text' => 'Old', 'tag' => 'h1']);

        $this->assertStringContainsString('<h1 class="cb-kit-title cb-kit-title--h1"', $html);
    }

    public function testLegacyNonHeadingTagFallsBackToH2Size(): void
    {
        $html = $this->render(['text' => 'Old', 'tag' => 'p']);

        $this->assertStringContainsString('<p class="cb-kit-title cb-kit-title--h2"', $html);
    }

    public function testEmptyTextRendersPlaceholder(): void
    {
        $html = $this->render(['text' => '']);

        $this->assertStringNotContainsString('cb-kit-title', $html);
    }

    public function testPaletteColorIsRenderedAsInlineStyle(): void
    {
        // The hex is html_attr-escaped (# → &#x23;, browser-decoded), like the
        // divider block — assert the style is present and carries the hex.
        $html = $this->render(['text' => 'Hi', 'tag' => 'h2', 'size' => 'h2', 'color' => '#4f46e5']);

        $this->assertStringContainsString('style="color:', $html);
        $this->assertStringContainsString('4f46e5', $html);
    }

    public function testNoColorMeansNoInlineStyle(): void
    {
        $html = $this->render(['text' => 'Hi', 'tag' => 'h2', 'size' => 'h2', 'color' => '']);

        $this->assertStringNotContainsString('style="color', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): string
    {
        return $this->makeTwig()->render('@ContentBlocksKit/block/title/view.html.twig', ['data' => $data]);
    }

    private function makeTwig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new TranslationExtension($this->makeTranslator()));

        return $env;
    }

    private function makeTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            use TranslatorTrait;
        };
    }
}
