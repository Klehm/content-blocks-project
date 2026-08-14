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
 * Renders the text view to guard the optional palette color: it applies as an
 * inline style only when set, and an empty text renders the placeholder.
 */
final class TextViewTest extends TestCase
{
    public function testContentIsRendered(): void
    {
        $html = $this->render(['content' => 'Hello world']);

        $this->assertStringContainsString('cb-block-text', $html);
        $this->assertStringContainsString('Hello world', $html);
    }

    public function testPaletteColorIsRenderedAsInlineStyle(): void
    {
        // The hex is html_attr-escaped (# → &#x23;, browser-decoded), like the
        // divider block — assert the style is present and carries the hex.
        $html = $this->render(['content' => 'Hi', 'color' => '#4f46e5']);

        $this->assertStringContainsString('style="color:', $html);
        $this->assertStringContainsString('4f46e5', $html);
    }

    public function testNoColorMeansNoInlineStyle(): void
    {
        $html = $this->render(['content' => 'Hi', 'color' => '']);

        $this->assertStringNotContainsString('style="color', $html);
    }

    public function testEmptyContentRendersPlaceholder(): void
    {
        $html = $this->render(['content' => '']);

        $this->assertStringNotContainsString('cb-block-text', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): string
    {
        return $this->makeTwig()->render('@ContentBlocksKit/block/text/view.html.twig', ['data' => $data]);
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
