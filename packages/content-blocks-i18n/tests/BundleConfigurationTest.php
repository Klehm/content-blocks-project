<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests;

use ContentBlocks\I18n\ContentBlocksI18nBundle;
use ContentBlocks\I18n\Rendering\FallbackLanguageDecorator;
use ContentBlocks\I18n\Rendering\FallbackLanguageMarker;
use ContentBlocks\I18n\Rendering\TranslationBlockDataResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BundleConfigurationTest extends TestCase
{
    public function testWorkbenchPublicLinksDefaultToOn(): void
    {
        $this->assertTrue($this->process([])['workbench']['public_links']);
    }

    public function testWorkbenchPublicLinksCanBeTurnedOff(): void
    {
        $config = $this->process(['workbench' => ['public_links' => false]]);

        $this->assertFalse($config['workbench']['public_links']);
    }

    /** It changes the public markup, so a host opts in. */
    public function testFallbackLangIsOffByDefaultAndWiresNothing(): void
    {
        $this->assertFalse($this->process([])['fallback_lang']);

        $container = $this->load([]);

        $this->assertFalse($container->hasDefinition(FallbackLanguageMarker::class));
        $this->assertFalse($container->hasDefinition(FallbackLanguageDecorator::class));
    }

    public function testFallbackLangWiresTheMarkerAndTheDecorator(): void
    {
        $container = $this->load(['fallback_lang' => true]);

        $this->assertTrue($container->hasDefinition(FallbackLanguageMarker::class));
        $this->assertTrue($container->getDefinition(FallbackLanguageDecorator::class)->hasTag('content_blocks.block_decorator'));
        $marker = $container->getDefinition(TranslationBlockDataResolver::class)->getArgument('$marker');
        $this->assertSame(FallbackLanguageMarker::class, (string) $marker);
    }

    public function testThereIsNoFallbackChainByDefault(): void
    {
        $this->assertSame([], $this->process([])['fallbacks']);
    }

    public function testAFallbackCanBeASingleCodeOrAList(): void
    {
        $config = $this->process(['fallbacks' => ['fr_CA' => 'fr', 'pt_BR' => ['pt_PT', 'es']]]);

        $this->assertSame(['fr_CA' => ['fr'], 'pt_BR' => ['pt_PT', 'es']], $config['fallbacks']);
    }

    public function testAHyphenatedLocaleKeyIsKeptVerbatim(): void
    {
        $config = $this->process(['fallbacks' => ['zh-Hant-HK' => 'zh-Hant']]);

        $this->assertSame(['zh-Hant-HK' => ['zh-Hant']], $config['fallbacks']);
    }

    public function testTheSourceLocaleCannotBeListedAsAFallback(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('the source locale ends every chain already');

        $this->process(['source_locale' => 'en', 'fallbacks' => ['fr_CA' => ['fr', 'en']]]);
    }

    public function testALocaleCannotFallBackToItself(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['fallbacks' => ['fr_CA' => 'fr_CA']]);
    }

    public function testTheSourceLocaleHasNoFallbacks(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is the source locale');

        $this->process(['source_locale' => 'en', 'fallbacks' => ['en' => 'fr']]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new ContentBlocksI18nBundle())->getContainerExtension();
        $this->assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }

    private function process(array $config): array
    {
        $extension = (new ContentBlocksI18nBundle())->getContainerExtension();
        $configuration = $extension?->getConfiguration([], new ContainerBuilder());
        $this->assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
