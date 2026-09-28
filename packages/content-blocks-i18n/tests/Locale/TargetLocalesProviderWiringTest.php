<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Locale;

use ContentBlocks\I18n\ContentBlocksI18nBundle;
use ContentBlocks\I18n\Locale\ConfiguredTargetLocalesProvider;
use ContentBlocks\I18n\Locale\TargetLocalesProviderInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The shipped services.php, compiled: the config drives the locales by
 * default, and a host alias of the provider replaces the codes, not the labels.
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

    /**
     * @param array<string, mixed> $config
     * @param class-string<TargetLocalesProviderInterface>|null $hostProvider
     */
    private function locales(array $config, ?string $hostProvider = null): TranslationLocales
    {
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
            TranslationLocales::class,
        ];
        foreach (array_keys($container->getDefinitions()) as $id) {
            if (!\in_array($id, $keep, true) && $id !== 'service_container') {
                $container->removeDefinition($id);
            }
        }
        foreach (array_keys($container->getAliases()) as $id) {
            if ($id !== TargetLocalesProviderInterface::class) {
                $container->removeAlias($id);
            }
        }

        if ($hostProvider !== null) {
            $container->register($hostProvider, $hostProvider);
            $container->setAlias(TargetLocalesProviderInterface::class, $hostProvider);
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
