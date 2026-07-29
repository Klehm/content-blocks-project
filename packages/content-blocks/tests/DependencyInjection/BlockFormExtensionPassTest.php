<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\DependencyInjection\BlockFormExtensionPass;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\FormBuilderInterface;

final class BlockFormExtensionPassTest extends TestCase
{
    public function testTagAttributesAreWiredAsOrderedPairs(): void
    {
        $container = $this->containerWithCollection();

        $this->tagExtension($container, 'ext.button', ['button'], priority: 0);
        $this->tagExtension($container, 'ext.global', [], priority: 100);
        $this->tagExtension($container, 'ext.multi', ['card', 'list'], priority: 50);

        (new BlockFormExtensionPass())->process($container);

        $pairs = $container->getDefinition(BlockFormExtensionCollection::class)->getArgument(0);

        // Ordered by priority desc: global(100), multi(50), button(0).
        $this->assertCount(3, $pairs);
        $this->assertPairReferences('ext.global', ['*'], $pairs[0]);
        $this->assertPairReferences('ext.multi', ['card', 'list'], $pairs[1]);
        $this->assertPairReferences('ext.button', ['button'], $pairs[2]);
    }

    public function testMissingBlockTypesTagDefaultsToWildcard(): void
    {
        $container = $this->containerWithCollection();
        // Tag without a `block_types` attribute at all.
        $container->register('ext.bare', $this->extensionClass())
            ->addTag('content_blocks.block_form_extension');

        (new BlockFormExtensionPass())->process($container);

        $pairs = $container->getDefinition(BlockFormExtensionCollection::class)->getArgument(0);
        $this->assertPairReferences('ext.bare', ['*'], $pairs[0]);
    }

    public function testSamePriorityKeepsRegistrationOrder(): void
    {
        $container = $this->containerWithCollection();
        $this->tagExtension($container, 'ext.a', ['button'], priority: 0);
        $this->tagExtension($container, 'ext.b', ['card'], priority: 0);

        (new BlockFormExtensionPass())->process($container);

        $pairs = $container->getDefinition(BlockFormExtensionCollection::class)->getArgument(0);
        $this->assertSame('ext.a', (string) $pairs[0][0]);
        $this->assertSame('ext.b', (string) $pairs[1][0]);
    }

    public function testNoCollectionServiceIsANoop(): void
    {
        $container = new ContainerBuilder();
        $this->tagExtension($container, 'ext.button', ['button'], priority: 0);

        // Must not throw even though the collection service is absent.
        (new BlockFormExtensionPass())->process($container);
        $this->assertFalse($container->hasDefinition(BlockFormExtensionCollection::class));
    }

    private function containerWithCollection(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(
            BlockFormExtensionCollection::class,
            new Definition(BlockFormExtensionCollection::class),
        );

        return $container;
    }

    /**
     * @param list<string> $blockTypes
     */
    private function tagExtension(ContainerBuilder $container, string $id, array $blockTypes, int $priority): void
    {
        $container->register($id, $this->extensionClass())
            ->addTag('content_blocks.block_form_extension', [
                'priority' => $priority,
                'block_types' => [] === $blockTypes ? ['*'] : $blockTypes,
            ]);
    }

    /**
     * @param array{0: Reference, 1: list<string>} $pair
     * @param list<string>                          $expectedTypes
     */
    private function assertPairReferences(string $expectedId, array $expectedTypes, array $pair): void
    {
        $this->assertInstanceOf(Reference::class, $pair[0]);
        $this->assertSame($expectedId, (string) $pair[0]);
        $this->assertSame($expectedTypes, $pair[1]);
    }

    private function extensionClass(): string
    {
        $extension = new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
            }
        };

        return $extension::class;
    }
}
