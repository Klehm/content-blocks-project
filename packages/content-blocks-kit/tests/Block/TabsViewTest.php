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
 * The tabs view used to carry its own <style>, whose active state was keyed on
 * the instance's random id — (1,4,0) specificity against a name a theme could
 * not even predict, so retheming meant !important. The styling now lives in
 * kit.css and hangs off `:checked + label [+ panel]`, which is what these tests
 * pin: no inline CSS, no id in a selector, and the markup order the two rules
 * depend on.
 */
final class TabsViewTest extends TestCase
{
    public function testShipsNoInlineStyle(): void
    {
        $html = $this->render($this->tabs(3));

        $this->assertStringNotContainsString('<style', $html);
    }

    public function testMarkupOrderIsRadioThenLabelThenPanel(): void
    {
        // The two kit.css rules are adjacency-based, so this order IS the
        // behaviour: break it and the tabs stop switching.
        $html = $this->render($this->tabs(2));

        $this->assertMatchesRegularExpression(
            '/<input[^>]*class="cb-kit-tabs__radio".*?<label[^>]*class="cb-kit-tabs__tab".*?'
            . '<div[^>]*class="cb-kit-tabs__panel".*?<input[^>]*class="cb-kit-tabs__radio"/s',
            $html,
        );
    }

    public function testFirstTabIsCheckedAndTheOthersAreNot(): void
    {
        $html = $this->render($this->tabs(3));

        $this->assertSame(3, substr_count($html, 'class="cb-kit-tabs__radio"'));
        $this->assertSame(1, substr_count($html, 'checked'));
    }

    public function testLabelIsPairedWithItsInputAndPanelIsLabelledByIt(): void
    {
        $html = $this->render($this->tabs(1));

        preg_match('/<input[^>]*id="([^"]+)"/', $html, $input);
        preg_match('/<label[^>]*for="([^"]+)"[^>]*id="([^"]+)"/', $html, $label);
        preg_match('/<div class="cb-kit-tabs__panel"[^>]*aria-labelledby="([^"]+)"/s', $html, $panel);

        $this->assertSame($input[1], $label[1], 'label[for] must point at its radio');
        $this->assertSame($label[2], $panel[1], 'panel must be labelled by its tab');
    }

    public function testTwoBlocksOnOnePageDoNotShareARadioGroup(): void
    {
        $first = $this->render($this->tabs(2));
        $second = $this->render($this->tabs(2));

        preg_match('/name="([^"]+)"/', $first, $a);
        preg_match('/name="([^"]+)"/', $second, $b);

        $this->assertNotSame($a[1], $b[1]);
    }

    public function testEmptyItemsRenderThePlaceholder(): void
    {
        $html = $this->render([]);

        $this->assertStringNotContainsString('cb-kit-tabs__tab', $html);
        $this->assertStringContainsString('cb-kit-tabs__empty', $html);
    }

    /** @return list<array{title: string, content: string}> */
    private function tabs(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = ['title' => 'Tab ' . $i, 'content' => 'Content ' . $i];
        }

        return $items;
    }

    /** @param list<array<string, string>> $items */
    private function render(array $items): string
    {
        return $this->makeTwig()->render(
            '@ContentBlocksKit/block/tabs/view.html.twig',
            ['data' => ['items' => $items]],
        );
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
