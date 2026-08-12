<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Transfer\ContentAreaExporter;
use ContentBlocks\Transfer\ContentAreaExporterInterface;
use ContentBlocks\Transfer\ContentAreaImporter;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use ContentBlocks\Publishing\ContentAreaPublisher;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Section\SectionCloner;
use ContentBlocks\Section\SectionClonerInterface;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiatorInterface;
use ContentBlocks\SectionTemplate\SectionTemplateSerializer;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\Translation\TranslatableFields;
use ContentBlocks\Translation\TranslatableFieldsInterface;
use ContentBlocks\Versioning\ContentVersionUpgraderInterface;
use ContentBlocks\Versioning\DenyOnMismatchUpgrader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Guards the override seams: every core service a host may want to replace or
 * decorate is reachable through an interface aliased to the shipped default.
 *
 * Without the alias the interface is just documentation — autowiring would fail
 * on the type-hint, and `#[AsDecorator(SomeInterface::class)]` would have
 * nothing to decorate. This test fails the day an alias is dropped, or a new
 * seam ships with an interface nobody wired.
 */
final class ServiceSeamAliasesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function seamProvider(): iterable
    {
        yield 'rendering' => [BlockRendererInterface::class, BlockRenderer::class];
        yield 'publisher' => [ContentAreaPublisherInterface::class, ContentAreaPublisher::class];
        yield 'section cloner' => [SectionClonerInterface::class, SectionCloner::class];
        yield 'exporter' => [ContentAreaExporterInterface::class, ContentAreaExporter::class];
        yield 'importer' => [ContentAreaImporterInterface::class, ContentAreaImporter::class];
        yield 'template serializer' => [SectionTemplateSerializerInterface::class, SectionTemplateSerializer::class];
        yield 'template instantiator' => [SectionTemplateInstantiatorInterface::class, SectionTemplateInstantiator::class];
        yield 'content version upgrader' => [ContentVersionUpgraderInterface::class, DenyOnMismatchUpgrader::class];
        yield 'translatable fields' => [TranslatableFieldsInterface::class, TranslatableFields::class];
        yield 'image url resolver' => [ImageUrlResolverInterface::class, PassthroughImageUrlResolver::class];
    }

    /**
     * @param class-string $interface
     * @param class-string $implementation
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('seamProvider')]
    public function testTheInterfaceIsAliasedToTheShippedDefault(string $interface, string $implementation): void
    {
        $container = $this->loadServices();

        $this->assertTrue(
            $container->hasAlias($interface),
            sprintf('%s must be aliased, otherwise the type-hint is unautowirable', $interface),
        );
        $this->assertSame($implementation, (string) $container->getAlias($interface));
        $this->assertTrue(
            $container->hasDefinition($implementation),
            sprintf('%s must be registered for its alias to resolve', $implementation),
        );
    }

    /**
     * @param class-string $interface
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('seamProvider')]
    public function testTheImplementationDeclaresTheInterface(string $interface, string $implementation): void
    {
        // An alias resolves whatever the target is; the type-hint only holds if
        // the target actually implements the contract.
        $this->assertTrue(
            is_subclass_of($implementation, $interface),
            sprintf('%s must implement %s', $implementation, $interface),
        );
    }

    private function loadServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $configDir = \dirname(__DIR__, 2) . '/config';

        (new PhpFileLoader($container, new FileLocator($configDir)))->load('services.php');

        return $container;
    }
}
