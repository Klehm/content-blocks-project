<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
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

    /**
     * The two fields are deliberately not treated alike.
     *
     * `tag` becomes the element, so its list stays closed no matter what
     * `content_blocks_kit.blocks.title.choices` says — emitting markup is not
     * something config gets to widen. `size` only suffixes a class, so a value
     * the kit does not code reaches the page and the host styles it; that is
     * what makes the choice set extensible at all.
     */
    public function testAnUnknownTagFallsBackWhileAnUnknownSizePassesThrough(): void
    {
        $html = $this->render(['text' => 'X', 'tag' => 'script', 'size' => 'huge']);

        $this->assertStringContainsString('<h2 class="cb-kit-title cb-kit-title--huge"', $html);
        $this->assertStringNotContainsString('script', $html);
    }

    public function testAMalformedSizeStillFallsBack(): void
    {
        // A value that is not a single class token would leak a second class
        // into the attribute — the shape guard cb_kit_token() kept from the
        // whitelists it replaced.
        $html = $this->render(['text' => 'X', 'size' => 'huge display-1']);

        $this->assertStringContainsString('class="cb-kit-title cb-kit-title--h2"', $html);
        $this->assertStringNotContainsString('display-1', $html);
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
        // Kit views pass choice values through cb_kit_token() instead of
        // re-listing them inline; see ChoiceTokenExtension.
        $env->addExtension(new ChoiceTokenExtension());
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
