<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Tests;

use ContentBlocks\Entity\ContentArea;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PanelTemplateTest extends TestCase
{
    public function testThePanelCarriesWhatItsScriptReads(): void
    {
        $html = $this->render();
        $root = $this->root($html);

        $this->assertTrue($root->hasAttribute('hidden'));
        $this->assertSame('/page/7?cb_preview=1', $root->getAttribute('data-preview-url'));
        $this->assertSame('/asset/audit?v=a1', $root->getAttribute('data-audit-url'));
        $this->assertSame('fr', $root->getAttribute('data-locale'));
        $this->assertSame(
            ['paragraphMaxWords' => 150, 'genericLinkTexts' => ["plus d'infos"], 'disabled' => []],
            json_decode($root->getAttribute('data-options'), true),
        );
        $strings = json_decode($root->getAttribute('data-i18n'), true);
        $this->assertSame('cb_seo.check.heading_level_skipped', $strings['check_heading_level_skipped']);
        $this->assertStringContainsString('src="/asset/js?v=j1"', $html);
        $this->assertStringContainsString('href="/asset/css?v=c1"', $html);
    }

    // Two builders on one page must not share ids.
    public function testTheTitleIdIsScopedToTheArea(): void
    {
        $root = $this->root($this->render());

        $this->assertSame('cb-seo-title-7', $root->getAttribute('aria-labelledby'));
    }

    private function root(string $html): \DOMElement
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $root = (new \DOMXPath($dom))->query('//*[@data-cb-seo]')->item(0);
        \assert($root instanceof \DOMElement);

        return $root;
    }

    private function render(): string
    {
        $twig = new Environment(new FilesystemLoader(), ['strict_variables' => true]);
        $twig->getLoader()->addPath(\dirname(__DIR__) . '/templates', 'ContentBlocksSeo');
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (string $name, array $params): string => '/asset/' . $params['type'] . '?v=' . $params['v']));
        $twig->addFunction(new TwigFunction('cb_preview_url', static fn (ContentArea $area): string => '/page/7?cb_preview=1'));
        $twig->addGlobal('app', ['request' => ['locale' => 'fr']]);
        $twig->addTokenParser(new class () extends \Twig\TokenParser\AbstractTokenParser {
            public function parse(\Twig\Token $token): \Twig\Node\Node
            {
                $this->parser->getExpressionParser()->parseExpression();
                $this->parser->getStream()->expect(\Twig\Token::BLOCK_END_TYPE);

                return new \Twig\Node\Node();
            }

            public function getTag(): string
            {
                return 'trans_default_domain';
            }
        });

        $area = new ContentArea();
        (new \ReflectionProperty(ContentArea::class, 'id'))->setValue($area, 7);

        return $twig->render('@ContentBlocksSeo/panel.html.twig', [
            'area' => $area,
            'auditOptions' => ['paragraphMaxWords' => 150, 'genericLinkTexts' => ["plus d'infos"], 'disabled' => []],
            'assetVersions' => ['css' => 'c1', 'js' => 'j1', 'audit' => 'a1'],
        ]);
    }
}
