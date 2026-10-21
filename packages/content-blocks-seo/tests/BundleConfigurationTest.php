<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Tests;

use ContentBlocks\Seo\Builder\SeoActionProvider;
use ContentBlocks\Seo\Builder\SeoShellExtension;
use ContentBlocks\Seo\ContentBlocksSeoBundle;
use ContentBlocks\Seo\SeoChecks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BundleConfigurationTest extends TestCase
{
    public function testTheDefaultsAuditEverything(): void
    {
        $options = $this->compile([])->getParameter('content_blocks_seo.audit_options');

        $this->assertSame(150, $options['paragraphMaxWords']);
        $this->assertSame([], $options['disabled']);
        $this->assertContains('click here', $options['genericLinkTexts']);
        $this->assertContains('en savoir plus', $options['genericLinkTexts']);
    }

    public function testAHostListReplacesTheGenericLinkTexts(): void
    {
        $options = $this->compile(['generic_link_texts' => ['Klik hier', 'Klik hier']])
            ->getParameter('content_blocks_seo.audit_options');

        $this->assertSame(['Klik hier'], $options['genericLinkTexts']);
    }

    public function testChecksCanBeDisabledByName(): void
    {
        $options = $this->compile(['disabled_checks' => ['image_alt_empty'], 'paragraph_max_words' => 0])
            ->getParameter('content_blocks_seo.audit_options');

        $this->assertSame(['image_alt_empty'], $options['disabled']);
        $this->assertSame(0, $options['paragraphMaxWords']);
    }

    public function testAnUnknownCheckIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown check "image_alt"');

        $this->compile(['disabled_checks' => ['image_alt']]);
    }

    public function testANegativeWordLimitIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->compile(['paragraph_max_words' => -1]);
    }

    // Autoconfigured, so the core's shell and Actions menu find them.
    public function testThePanelServicesAreAutoconfigured(): void
    {
        $container = $this->compile([], compile: false);
        $container->getExtension('content_blocks_seo')->load([[]], $container);

        $this->assertTrue($container->hasDefinition(SeoShellExtension::class));
        $this->assertTrue($container->getDefinition(SeoShellExtension::class)->isAutoconfigured());
        $this->assertTrue($container->getDefinition(SeoActionProvider::class)->isAutoconfigured());
    }

    // The config validates names the browser runs: one list, read twice.
    public function testThePhpListOfChecksIsTheOneTheAuditRuns(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__) . '/assets/audit.js');
        $this->assertSame(1, preg_match('/export const CHECKS = \[(.*?)\];/s', $source, $match));
        preg_match_all("/'([a-z0-9_]+)'/", $match[1], $names);

        $this->assertSame(SeoChecks::ALL, $names[1]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function compile(array $config, bool $compile = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new ContentBlocksSeoBundle())->getContainerExtension();
        \assert($extension !== null);
        $container->registerExtension($extension);
        $container->loadFromExtension('content_blocks_seo', $config);
        if ($compile) {
            $container->compile();
        }

        return $container;
    }
}
