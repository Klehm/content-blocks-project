<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use ContentBlocks\Twig\LanguageExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * A field the i18n package marked as shown in another language (`_lang`)
 * gets `lang` on the element that holds it, entries included.
 */
final class FallbackLanguageViewTest extends TestCase
{
    public function testAnAccordionEntryCarriesItsOwnLanguage(): void
    {
        $html = $this->render('accordion', ['items' => [
            ['_id' => 'a', 'title' => 'Livraison', 'content' => 'Partout.'],
            ['_id' => 'b', 'title' => 'Returns', 'content' => 'Free.', '_lang' => ['title' => 'en', 'content' => 'en']],
        ]]);

        $this->assertStringContainsString('<span>Livraison</span>', $html);
        $this->assertStringContainsString('<span lang="en">Returns</span>', $html);
        $this->assertStringContainsString('class="cb-kit-accordion__content" lang="en"', $html);
    }

    public function testAListItemCarriesItsLanguage(): void
    {
        $html = $this->render('list', ['items' => [
            ['_id' => 'a', 'text' => 'Un'],
            ['_id' => 'b', 'text' => 'Two', '_lang' => ['text' => 'en']],
        ]]);

        $this->assertStringContainsString('<span>Un</span>', $html);
        $this->assertStringContainsString('<span lang="en">Two</span>', $html);
    }

    public function testATableMarksAHeaderAndACellButNotAMissingCell(): void
    {
        $html = $this->render('table', [
            'columns' => [['_id' => 'c1', 'label' => 'Name', '_lang' => ['label' => 'en']], ['_id' => 'c2', 'label' => 'Prix']],
            'rows' => [['_id' => 'r1', 'cells' => [['_id' => 'x', 'content' => 'Tea', '_lang' => ['content' => 'en']]]]],
        ]);

        $this->assertStringContainsString('lang="en">Name</th>', $html);
        $this->assertStringContainsString('>Prix</th>', $html);
        $this->assertStringContainsString('lang="en">Tea</td>', $html);
    }

    public function testAnAlertMarksOnlyTheFieldThatFellBack(): void
    {
        $html = $this->render('alert', ['title' => 'Attention', 'content' => 'Closed today.', '_lang' => ['content' => 'en']]);

        $this->assertStringContainsString('<p class="cb-kit-alert__title">Attention</p>', $html);
        $this->assertStringContainsString('<p class="cb-kit-alert__message" lang="en">', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(string $block, array $data): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new ChoiceTokenExtension());
        $env->addExtension(new LanguageExtension());
        $env->addFunction(new TwigFunction('cb_kit_icon', static fn () => '<svg></svg>'));

        return $env->render("@ContentBlocksKit/block/$block/view.html.twig", ['data' => $data, 'block_id' => 1]);
    }
}
