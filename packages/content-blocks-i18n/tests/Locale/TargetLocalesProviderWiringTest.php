<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Locale;

use ContentBlocks\I18n\ContentBlocksI18nBundle;
use ContentBlocks\I18n\Locale\ConfiguredLocaleFallbacksProvider;
use ContentBlocks\I18n\Locale\ConfiguredTargetLocalesProvider;
use ContentBlocks\I18n\Locale\LocaleFallbacksProviderInterface;
use ContentBlocks\I18n\Locale\TargetLocalesProviderInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The shipped services.php, compiled: the config drives the locales and their
 * fallbacks by default, and a host alias of either provider replaces it.
 */
final class TargetLocalesProviderWiringTest extends TestCase
{
    public function testTheConfigDrivesTheLocalesByDefault(): void
    {
        $locales = $this->locales([
            'source_locale' => 'en',
            'locales' => ['en', 'fr', ['code' => 'de', 'label' => 'Deutsch']],
        ]);

        $this->assertSame(['fr', 'de'], $locales->getTargetLocales());
        $this->assertSame('Deutsch', $locales->getLabel('de'));
    }

    public function testAHostProviderReplacesTheConfiguredCodes(): void
    {
        $locales = $this->locales(
            [
                'source_locale' => 'en',
                'locales' => ['fr', ['code' => 'nl', 'label' => 'Vlaams']],
            ],
            HostTargetLocales::class,
        );

        $this->assertSame(['nl', 'it'], $locales->getTargetLocales());
        $this->assertFalse($locales->isTarget('fr'));
        $this->assertSame('Vlaams', $locales->getLabel('nl'));
    }

    public function testTheConfigDrivesTheFallbacksByDefault(): void
    {
        $locales = $this->locales([
            'source_locale' => 'en',
            'locales' => ['fr', 'fr_CA'],
            'fallbacks' => ['fr_CA' => 'fr'],
        ]);

        $this->assertSame(['fr'], $locales->getFallbacks('fr_CA'));
    }

    public function testAHostFallbacksProviderReplacesTheConfiguredChains(): void
    {
        $locales = $this->locales(
            [
                'source_locale' => 'en',
                'locales' => ['fr', 'fr_CA', 'pt_PT', 'pt_BR'],
                'fallbacks' => ['fr_CA' => 'fr'],
            ],
            fallbacks: HostLocaleFallbacks::class,
        );

        $this->assertSame([], $locales->getFallbacks('fr_CA'));
        $this->assertSame(['pt_PT'], $locales->getFallbacks('pt_BR'));
    }

    /**
     * @param array<string, mixed> $config
     * @param class-string<TargetLocalesProviderInterface>|null $hostProvider
     * @param class-string<LocaleFallbacksProviderInterface>|null $fallbacks
     */
    private function locales(
        array $config,
        ?string $hostProvider = null,
        ?string $fallbacks = null,
    ): TranslationLocales {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new ContentBlocksI18nBundle();
        $extension = $bundle->getContainerExtension();
        $this->assertNotNull($extension);
        $container->registerExtension($extension);
        $extension->load([$config], $container);

        // Keep the locale services: the rest needs Doctrine, Twig and the core.
        $keep = [
            ConfiguredTargetLocalesProvider::class,
            ConfiguredLocaleFallbacksProvider::class,
            TranslationLocales::class,
        ];
        foreach (array_keys($container->getDefinitions()) as $id) {
            if (!\in_array($id, $keep, true) && $id !== 'service_container') {
                $container->removeDefinition($id);
            }
        }
        foreach (array_keys($container->getAliases()) as $id) {
            if ($id !== TargetLocalesProviderInterface::class
                && $id !== LocaleFallbacksProviderInterface::class) {
                $container->removeAlias($id);
            }
        }

        if ($hostProvider !== null) {
            $container->register($hostProvider, $hostProvider);
            $container->setAlias(TargetLocalesProviderInterface::class, $hostProvider);
        }

        if ($fallbacks !== null) {
            $container->register($fallbacks, $fallbacks);
            $container->setAlias(LocaleFallbacksProviderInterface::class, $fallbacks);
        }

        $container->compile();

        $locales = $container->get(TranslationLocales::class);
        \assert($locales instanceof TranslationLocales);

        return $locales;
    }
}

final class HostTargetLocales implements TargetLocalesProviderInterface
{
    public function getTargetLocales(): array
    {
        return ['en', 'nl', 'it'];
    }
}

final class HostLocaleFallbacks implements LocaleFallbacksProviderInterface
{
    public function getFallbacks(): array
    {
        return ['pt_BR' => ['pt_PT']];
    }
}
