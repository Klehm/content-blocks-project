<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Guards what the `ContentBlocks\Form\` glob in config/services.php registers.
 *
 * The subtlety worth a test: {@see AsBlockFormExtension} lives under
 * `src/Form/Extension/` but is an attribute, not a service — a marker class read
 * by reflection, never instantiated by the container. Its constructor arguments
 * all have defaults, so leaving it in the glob compiles fine and merely puts a
 * meaningless service in every host's container (and would hard-fail the day
 * those defaults go away). It is excluded, and this test fails the day someone
 * drops the `exclude()`.
 */
final class FormServicesGlobTest extends TestCase
{
    public function testTheFormExtensionCollectionIsRegistered(): void
    {
        $container = $this->loadServices();

        $this->assertTrue(
            $container->hasDefinition(BlockFormExtensionCollection::class),
            'the collection BlockFormType depends on must be a service',
        );
        $this->assertTrue($container->hasDefinition(BlockFormType::class));
    }

    public function testTheAttributeIsNotRegisteredAsAService(): void
    {
        $container = $this->loadServices();

        // An excluded class still gets a placeholder definition carrying the
        // `container.excluded` tag (that's how the loader records "seen but not
        // a service"); it is abstract and dropped at compile time. What matters
        // is that it is *not* an instantiable service definition.
        $definition = $container->hasDefinition(AsBlockFormExtension::class)
            ? $container->getDefinition(AsBlockFormExtension::class)
            : null;

        $this->assertTrue(
            null === $definition || $definition->hasTag('container.excluded'),
            'the AsBlockFormExtension attribute is a marker class, not a service',
        );
    }

    public function testTheCollectionIsInstantiableFromTheCompiledContainer(): void
    {
        $container = $this->loadServices();
        $container->getDefinition(BlockFormExtensionCollection::class)->setPublic(true);
        $container->compile();

        // No host extension tagged: the collection still builds (its
        // `iterable $extensions` argument defaults to an empty list) and the
        // attribute is gone from the compiled container.
        $this->assertInstanceOf(
            BlockFormExtensionCollection::class,
            $container->get(BlockFormExtensionCollection::class),
        );
        $this->assertFalse($container->has(AsBlockFormExtension::class));
    }

    public function testTheInterfaceIsNotRegisteredAsAService(): void
    {
        $container = $this->loadServices();

        // Sanity check on the glob's own behaviour: interfaces are skipped by
        // registerClasses, so hosts alias/implement it without collision.
        $this->assertFalse($container->hasDefinition('ContentBlocks\Form\Extension\BlockFormExtensionInterface'));
    }

    private function loadServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $configDir = \dirname(__DIR__, 2) . '/config';

        (new PhpFileLoader($container, new FileLocator($configDir)))->load('services.php');

        return $container;
    }
}
