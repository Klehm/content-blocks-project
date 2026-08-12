<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Twig;

use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\I18n\Twig\I18nExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Translator;

/**
 * The three helpers a host uses to link into the workbench from its own admin
 * UI. They are the package's only *outward* Twig surface, so their names and
 * shapes are a contract.
 */
final class I18nExtensionTest extends TestCase
{
    public function testTheWorkbenchUrlDefaultsToTheFirstTargetLocale(): void
    {
        // "Open the translation view" is the common call, with no locale in
        // hand — a host's page list has a button, not a language picker.
        $extension = $this->extension(new TranslationLocales('fr', ['de', 'es']));

        $this->assertSame(
            '/wb/7/de',
            $extension->workbenchUrl(Entities::area(7), null),
        );
    }

    public function testAnExplicitLocaleWins(): void
    {
        $extension = $this->extension(new TranslationLocales('fr', ['de', 'es']));

        $this->assertSame('/wb/7/es', $extension->workbenchUrl(Entities::area(7), 'es'));
    }

    /**
     * A host that installed the package but configured no target locale still
     * gets a URL rather than a TypeError in a template. The workbench itself
     * 404s on it, which is the honest answer to "translate into nothing".
     */
    public function testWithNoTargetsConfiguredItFallsBackToTheSourceLocale(): void
    {
        $extension = $this->extension(new TranslationLocales('fr', []));

        $this->assertSame('/wb/7/fr', $extension->workbenchUrl(Entities::area(7)));
    }

    public function testTheLocaleListCarriesTheSourceFlagForPickers(): void
    {
        $locales = $this->extension(new TranslationLocales('fr', ['de']))->localeList();

        $this->assertSame(
            [['code' => 'fr', 'source' => true], ['code' => 'de', 'source' => false]],
            array_map(static fn (array $l): array => ['code' => $l['code'], 'source' => $l['source']], $locales),
        );
    }

    /**
     * The matrix a page list decorates itself with: keyed by locale, one
     * plain array per entry, so a host template needs to know nothing about
     * how any of it is computed.
     */
    public function testProgressIsReturnedPerLocaleAsPlainArrays(): void
    {
        $extension = $this->extension(new TranslationLocales('en', ['fr', 'de']));

        $area = Entities::area(7, Entities::block(1, draft: [
            'heading' => 'Welcome',
            'body' => '',
            'align' => 'center',
            'items' => [],
        ]));

        $progress = $extension->progress($area);

        $this->assertSame(['fr', 'de'], array_keys($progress));
        $this->assertSame(1, $progress['fr']['missing']);
        $this->assertSame(0, $progress['fr']['percent']);
    }

    private function extension(TranslationLocales $locales): I18nExtension
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => sprintf('/wb/%s/%s', $params['id'], $params['locale']),
        );

        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturn([]);
        $repository->method('findOneFor')->willReturn(null);

        $inspector = new TranslationInspector(
            new TranslationStore($repository, $this->createMock(EntityManagerInterface::class)),
            CatalogFactory::create(),
            $locales,
            CatalogFactory::registry(),
            new Translator('en'),
        );

        return new I18nExtension($urls, $locales, $inspector);
    }
}
