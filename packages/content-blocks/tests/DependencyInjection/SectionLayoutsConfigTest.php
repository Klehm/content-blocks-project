<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * `content_blocks.section.layouts`: validated at cache:clear, resolved over
 * the built-ins into the parameter the registry is built from.
 */
final class SectionLayoutsConfigTest extends TestCase
{
    public function testTheBuiltInsAreResolvedWhenNothingIsConfigured(): void
    {
        $layouts = $this->build([])->getParameter('content_blocks.section.layouts');

        $this->assertIsArray($layouts);
        $this->assertSame(['full', 'two_cols', 'three_cols'], array_column($layouts, 'name'));
    }

    public function testAFourColumnLayoutAndAHiddenBuiltInComeThrough(): void
    {
        $layouts = $this->build(['section' => ['layouts' => [
            'three_cols' => false,
            'four_cols' => ['label' => '4 columns', 'columns' => [3, 3, 3, 3]],
        ]]])->getParameter('content_blocks.section.layouts');

        $this->assertIsArray($layouts);
        $byName = array_column($layouts, null, 'name');
        $this->assertFalse($byName['three_cols']['enabled']);
        $this->assertSame([3, 3, 3, 3], $byName['four_cols']['columns']);
        $this->assertTrue($byName['four_cols']['enabled']);
    }

    public function testAnUnknownDisplayIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->build(['section' => ['layouts' => [
            'slider' => ['label' => 'Slider', 'columns' => [6, 6], 'display' => 'carousel'],
        ]]]);
    }

    public function testALayoutMayStartAsAnAccordion(): void
    {
        $layouts = $this->build(['section' => ['layouts' => [
            'faq' => ['label' => 'FAQ', 'columns' => [6, 6], 'display' => 'accordion'],
        ]]])->getParameter('content_blocks.section.layouts');

        $this->assertIsArray($layouts);
        $this->assertSame('accordion', array_column($layouts, null, 'name')['faq']['display']);
    }

    public function testAPresetMayCarryTheDisplay(): void
    {
        $container = $this->build(['section_styles' => [
            ['name' => 'tabbed', 'label' => 'Tabbed', 'settings' => ['display' => 'tabs', 'reverseOnMobile' => true]],
        ]]);

        $this->assertSame('tabs', $container->getParameter('content_blocks.section_styles')[0]['settings']['display']);
        $this->assertTrue($container->getParameter('content_blocks.section_styles')[0]['settings']['reverseOnMobile']);
    }

    public function testSpansThatDoNotAddUpToTwelveAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('add up to 12');

        $this->build(['section' => ['layouts' => [
            'four_cols' => ['label' => '4 columns', 'columns' => [3, 3, 3]],
        ]]]);
    }

    public function testASpanOutsideTheGridIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->build(['section' => ['layouts' => [
            'wide' => ['label' => 'Wide', 'columns' => [13, -1]],
        ]]]);
    }

    /** The name lands in a CSS class and a 30-character column. */
    public function testANameThatIsNotSnakeCaseIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('snake_case');

        $this->build(['section' => ['layouts' => [
            'Four Cols' => ['label' => '4 columns', 'columns' => [3, 3, 3, 3]],
        ]]]);
    }

    public function testANewLayoutWithoutColumnsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build(['section' => ['layouts' => [
            'four_cols' => ['label' => '4 columns'],
        ]]]);
    }

    /** @param array<string, mixed> $config */
    private function build(array $config): ContainerBuilder
    {
        $configDir = \dirname(__DIR__, 2) . '/config';
        $treeBuilder = new TreeBuilder('content_blocks');
        (new ContentBlocksBundle())->configure(new DefinitionConfigurator(
            $treeBuilder,
            new DefinitionFileLoader($treeBuilder, new FileLocator($configDir)),
            $configDir,
            'services.php',
        ));
        $processed = (new Processor())->process($treeBuilder->buildTree(), [$config]);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $instanceof = [];
        $loader = new PhpFileLoader($container, new FileLocator($configDir));
        (new ContentBlocksBundle())->loadExtension(
            $processed,
            new ContainerConfigurator($container, $loader, $instanceof, $configDir, 'services.php'),
            $container,
        );

        return $container;
    }
}
