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
 * Renders the image view template to guard its horizontal alignment.
 *
 * Regression guard: the display width used to be applied as `max-width` on the
 * <figure>, which then sat at the column's left edge — so the alignment classes
 * (`align-items` on the figure) had nothing to move and left/center/right all
 * looked identical. The width now caps the <img> instead, keeping the figure a
 * full-width flex column so the alignment class actually positions the image.
 */
final class ImageViewTest extends TestCase
{
    public function testAlignmentClassMirrorsTheStoredValue(): void
    {
        $this->assertStringContainsString('cb-kit-image--start', $this->render(['align' => 'start']));
        $this->assertStringContainsString('cb-kit-image--center', $this->render(['align' => 'center']));
        $this->assertStringContainsString('cb-kit-image--end', $this->render(['align' => 'end']));
    }

    public function testInvalidAlignmentFallsBackToCenter(): void
    {
        $html = $this->render(['align' => 'bogus']);

        $this->assertStringContainsString('cb-kit-image--center', $html);
        $this->assertStringNotContainsString('cb-kit-image--bogus', $html);
    }

    /**
     * The whole point of the fix: the width lives on the <img>, never on the
     * <figure>, so the figure can stay full-width and align its child.
     */
    public function testWidthCapsTheImageNotTheFigure(): void
    {
        $html = $this->render(['size' => 'md', 'align' => 'end']);

        // <img> carries the display width...
        $this->assertMatchesRegularExpression('/<img[^>]*style="[^"]*width:800px/', $html);
        // ...and the <figure> carries no inline style at all.
        $this->assertMatchesRegularExpression('/<figure class="cb-kit-image cb-kit-image--end">/', $html);
        $this->assertDoesNotMatchRegularExpression('/<figure[^>]*style=/', $html);
    }

    public function testCustomWidthCapsTheImage(): void
    {
        $html = $this->render(['size' => 'custom', 'customWidth' => 512, 'align' => 'start']);

        $this->assertMatchesRegularExpression('/<img[^>]*style="[^"]*width:512px/', $html);
        $this->assertDoesNotMatchRegularExpression('/<figure[^>]*style=/', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): string
    {
        $data += ['src' => 'https://example.test/photo.jpg'];

        return $this->makeTwig()->render('@ContentBlocksKit/block/image/view.html.twig', ['data' => $data]);
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
