<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests;

use ContentBlocks\I18n\ContentBlocksI18nBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
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

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $extension = (new ContentBlocksI18nBundle())->getContainerExtension();
        $configuration = $extension?->getConfiguration([], new ContainerBuilder());
        $this->assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
