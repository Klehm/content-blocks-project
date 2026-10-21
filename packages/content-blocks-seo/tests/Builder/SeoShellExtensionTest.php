<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Tests\Builder;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Seo\Builder\SeoActionProvider;
use ContentBlocks\Seo\Builder\SeoShellExtension;
use ContentBlocks\Seo\Controller\AssetController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

final class SeoShellExtensionTest extends TestCase
{
    private const OPTIONS = ['paragraphMaxWords' => 150, 'genericLinkTexts' => ['here'], 'disabled' => []];

    public function testThePanelIsOneFragmentCarryingTheOptionsAndAssetVersions(): void
    {
        $fragments = iterator_to_array((new SeoShellExtension(self::OPTIONS))->getFragments(new ContentArea()), false);

        $this->assertCount(1, $fragments);
        $this->assertSame('@ContentBlocksSeo/panel.html.twig', $fragments[0]->template);
        $this->assertSame(self::OPTIONS, $fragments[0]->context['auditOptions']);
        $this->assertSame(AssetController::version('audit'), $fragments[0]->context['assetVersions']['audit']);
        $this->assertSame(AssetController::version('js'), $fragments[0]->context['assetVersions']['js']);
    }

    public function testTheTemplateExistsWhereTheFragmentNamesIt(): void
    {
        $this->assertFileExists(\dirname(__DIR__, 2) . '/templates/panel.html.twig');
    }

    public function testTheActionCarriesTheKeyThePanelSwitchesOn(): void
    {
        $actions = iterator_to_array((new SeoActionProvider())->getActions(new ContentArea()), false);

        $this->assertCount(1, $actions);
        $this->assertSame(SeoActionProvider::KEY, $actions[0]->key);
        $this->assertInstanceOf(TranslatableMessage::class, $actions[0]->label);
        $this->assertStringContainsString(
            \sprintf("ACTION_KEY = '%s'", SeoActionProvider::KEY),
            (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/panel.js'),
        );
    }
}
