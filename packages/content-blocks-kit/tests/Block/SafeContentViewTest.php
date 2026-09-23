<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Kit\RichText\RichTextSanitizerFactory;
use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use ContentBlocks\Kit\Twig\SafeContentExtension;
use ContentBlocks\Twig\ImageExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Stored data reaches the view without the form when it comes from an import,
 * a section template or a translation: the view itself has to hold.
 */
final class SafeContentViewTest extends TestCase
{
    public function testRichTextDropsScriptAndEventHandlers(): void
    {
        $html = $this->render('rich_text', ['content' => '<p>Hi</p>'
            . '<script>alert(1)</script><img src="/a.png" onerror="alert(2)">'
            . '<a href="javascript:alert(3)">x</a><iframe src="//evil"></iframe>']);

        $this->assertStringContainsString('<p>Hi</p>', $html);
        $this->assertStringContainsString('<img src="/a.png"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('iframe', $html);
    }

    public function testRichTextKeepsWhatTheEditorsWrite(): void
    {
        $html = $this->render('rich_text', ['content' => '<h2 class="lead">T</h2>'
            . '<p style="text-align: center; color: rgb(235, 5, 64)">'
            . '<strong>b</strong> <a href="https://example.com" target="_blank">l</a></p>'
            . '<ul><li>i</li></ul><table><tr><td>c</td></tr></table>']);

        $this->assertStringContainsString('<h2 class="lead">T</h2>', $html);
        $this->assertStringContainsString('style="text-align: center; color: rgb(235, 5, 64)"', $html);
        $this->assertStringContainsString('<a href="https://example.com" target="_blank">l</a>', $html);
        $this->assertStringContainsString('<li>i</li>', $html);
        $this->assertStringContainsString('<td>c</td>', $html);
    }

    // An overlay or a beacon is not formatting.
    public function testRichTextStyleKeepsFormattingOnly(): void
    {
        $html = $this->render('rich_text', ['content' => '<p style="color: red; '
            . 'position: fixed; inset: 0; background-image: url(https://evil/x); '
            . 'font-size: expression(alert(1))">x</p><p style="position: fixed">y</p>']);

        $this->assertStringContainsString('<p style="color: red">x</p>', $html);
        $this->assertStringContainsString('<p>y</p>', $html);
        $this->assertStringNotContainsString('evil', $html);
    }

    // The sanitizer's default 20 000-byte cap would cut an article short.
    public function testALongRichTextIsNotTruncated(): void
    {
        $content = str_repeat('<p>' . str_repeat('a', 97) . '</p>', 500);

        $this->assertStringContainsString($content, $this->render('rich_text', ['content' => $content]));
    }

    public function testAButtonWithAJavascriptLinkFallsBackToAnAnchor(): void
    {
        $html = $this->render('button', ['text' => 'Go', 'url' => 'javascript:alert(1)']);

        $this->assertStringContainsString('href="#"', $html);
        $this->assertStringNotContainsString('javascript', $html);
    }

    public function testAButtonGroupEntryWithAJavascriptLinkFallsBackToAnAnchor(): void
    {
        $html = $this->render('button_group', ['items' => [
            ['text' => 'Go', 'url' => "java\tscript:alert(1)", 'variant' => 'primary'],
        ]]);

        $this->assertStringContainsString('href="#"', $html);
        $this->assertStringNotContainsString('script', $html);
    }

    public function testLinkedViewsDropAnUnsafeLink(): void
    {
        $unsafe = 'javascript:alert(1)';
        $views = [
            'image' => ['src' => '/a.png', 'url' => $unsafe],
            'gallery' => ['items' => [['src' => '/a.png', 'url' => $unsafe]]],
            'card' => ['items' => [['title' => 'T', 'url' => $unsafe, 'buttonText' => 'Go']]],
            'breadcrumb' => ['items' => [['label' => 'Home', 'url' => $unsafe]]],
        ];

        foreach ($views as $type => $data) {
            $html = $this->render($type, $data);
            $this->assertStringNotContainsString('javascript', $html, $type);
            $this->assertStringNotContainsString('<a ', $html, $type);
        }
    }

    public function testLinkedViewsKeepASafeLink(): void
    {
        $html = $this->render('breadcrumb', ['items' => [['label' => 'Home', 'url' => '/']]]);

        $this->assertStringContainsString('href="/"', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(string $type, array $data): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');
        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new ChoiceTokenExtension());
        $env->addExtension(new SafeContentExtension(RichTextSanitizerFactory::create()));
        $env->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $env->addExtension(new ImageExtension(new PassthroughImageUrlResolver()));
        $env->addFunction(new TwigFunction('cb_kit_icon', static fn (): string => ''));

        return $env->render(sprintf('@ContentBlocksKit/block/%s/view.html.twig', $type), [
            'data' => $data,
        ]);
    }
}
