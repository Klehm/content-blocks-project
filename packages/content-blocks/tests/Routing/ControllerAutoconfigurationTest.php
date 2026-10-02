<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\AttributeAutoconfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A host on Symfony 7.4+ imports `routing.controllers`, which mounts every
 * service FrameworkBundle tagged `routing.controller` — unprefixed.
 */
final class ControllerAutoconfigurationTest extends TestCase
{
    public function testNoPackageControllerIsPickedUpByRoutingControllers(): void
    {
        $container = new ContainerBuilder();
        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config')))
            ->load('services.php');

        // What FrameworkExtension registers on 7.4+; the reflector type is
        // what makes the pass read method attributes too.
        $container->registerAttributeForAutoconfiguration(
            Route::class,
            static function (
                ChildDefinition $definition,
                Route $attribute,
                \ReflectionClass|\ReflectionMethod $reflector,
            ): void {
                $definition->addTag('controller.service_arguments')->addTag('routing.controller');
            },
        );
        (new ResolveClassPass())->process($container);
        (new AttributeAutoconfigurationPass())->process($container);
        (new ResolveInstanceofConditionalsPass())->process($container);

        $routed = [];
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (null === $class || $definition->hasTag('container.excluded') || !class_exists($class)) {
                continue;
            }
            if (self::hasRoute(new \ReflectionClass($class))) {
                $routed[] = $class;
                $this->assertFalse($definition->hasTag('routing.controller'), $class);
                $this->assertTrue($definition->hasTag('controller.service_arguments'), $class);
            }
        }

        $this->assertContains('ContentBlocks\Controller\AreaController', $routed);
        $this->assertContains('ContentBlocks\PublicAsset\AssetController', $routed);
    }

    /** @param \ReflectionClass<object> $class */
    private static function hasRoute(\ReflectionClass $class): bool
    {
        if ([] !== $class->getAttributes(Route::class)) {
            return true;
        }
        foreach ($class->getMethods() as $method) {
            if ([] !== $method->getAttributes(Route::class)) {
                return true;
            }
        }

        return false;
    }
}
