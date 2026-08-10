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
 * Guards how the kit caps editor-authored images — and, just as importantly,
 * how far that cap is allowed to reach.
 *
 * `rich_text` and `html_raw` are the only blocks whose content the kit does not
 * author, so they are the only ones where an <img> can arrive at its intrinsic
 * width. kit.css caps those two. It must NOT cap images generally: the
 * stylesheet is linked on the host's entire front layout, so a bare `img` rule
 * would silently resize the host's own pictures — the opposite of the
 * namespacing promise in the file's own header.
 */
final class EditorHtmlImageCapTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/styles/kit.css');
    }

    public function testTheImageCapIsScopedToEditorAuthoredBlocks(): void
    {
        $this->assertMatchesRegularExpression(
            '/:where\(\.cb-block-rich-text, \.cb-block-html-raw\) img \{[^}]*max-width:\s*100%/',
            $this->css(),
        );
    }

    /**
     * The contract this whole file rests on: kit.css only ever selects markup
     * the kit owns. A bare element selector reaches the host's page.
     */
    public function testKitCssNeverSelectsBareElements(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css());

        // Every selector, i.e. everything before a `{` that isn't an at-rule.
        preg_match_all('/(^|\})([^{}@]+)\{/m', (string) $css, $matches);

        foreach ($matches[2] as $selectorList) {
            foreach (explode(',', $selectorList) as $selector) {
                $selector = trim($selector);
                if ($selector === '') {
                    continue;
                }
                // A selector is safe when its FIRST compound is a kit class:
                // descendants may name elements (`… img`, `… th`), the anchor
                // may not. `:where(.a, .b) img` counts by its inner classes.
                $anchor = (string) preg_replace('/^:(?:where|is)\(\s*/', '', $selector);
                $anchor = preg_split('/[\s>+~]+/', $anchor)[0] ?? '';
                $this->assertMatchesRegularExpression(
                    '/^[.:\[]|^$/',
                    $anchor,
                    sprintf('"%s" starts with a bare element selector and would reach outside the kit', $selector),
                );
            }
        }
    }

    public function testRawHtmlBlockKeepsItsMarkupVerbatimInsideTheHook(): void
    {
        $stored = '<p>Hello <b>world</b></p><img src="/uploads/wide.png" alt="">';

        $html = $this->render('html_raw', ['html' => $stored]);

        // The wrapper is a styling hook; what it wraps is untouched.
        $this->assertStringContainsString('<div class="cb-block-html-raw">', $html);
        $this->assertStringContainsString($stored, $html);
    }

    public function testRawHtmlBlockStillRendersItsHookWhenEmpty(): void
    {
        $this->assertStringContainsString('<div class="cb-block-html-raw">', $this->render('html_raw', []));
    }

    public function testRichTextKeepsItsExistingHook(): void
    {
        $html = $this->render('rich_text', ['content' => '<p>Body</p>']);

        $this->assertStringContainsString('<div class="cb-block-rich-text">', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(string $type, array $data): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new TranslationExtension(new class implements TranslatorInterface {
            use TranslatorTrait;
        }));

        return $env->render("@ContentBlocksKit/block/{$type}/view.html.twig", ['data' => $data]);
    }
}
