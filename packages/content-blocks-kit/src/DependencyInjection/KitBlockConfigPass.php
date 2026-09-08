<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\DependencyInjection;

use ContentBlocks\Kit\Block\AbstractKitBlock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Hands the block config to **every** registered kit block, not only the
 * services the bundle declares — a host's subclass silently missed it before.
 *
 * @see docs/internals/kit.md#disabling-and-why-html_raw-is-off
 *
 * @internal wiring detail of the bundle
 */
final class KitBlockConfigPass implements CompilerPassInterface
{
    /**
     * Deferred because the pass is registered in `build()`, before the
     * extension has processed a line of configuration.
     *
     * @param \Closure(): array<string, array{
     *     options?: array<string, mixed>,
     *     choices?: array<string, mixed>,
     *     defaults?: array<string, mixed>,
     * }> $config
     */
    public function __construct(private readonly \Closure $config)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $blocksConfig = ($this->config)();

        if ($blocksConfig === []) {
            return;
        }

        foreach ($container->findTaggedServiceIds('content_blocks.block_type') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();

            if (!\is_string($class)) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue($class);

            if (!\is_string($class) || !is_subclass_of($class, AbstractKitBlock::class)) {
                continue;
            }

            $blockConfig = $blocksConfig[$class::getType()] ?? null;

            if ($blockConfig === null) {
                continue;
            }

            // Against the *subclass's* defaults, so one that widened
            // defaultOptions() keeps its additions under the host's config.
            $this->argue($definition, '$options', array_replace($class::defaultOptions(), $blockConfig['options'] ?? []));
            $this->argue($definition, '$choiceOverrides', $blockConfig['choices'] ?? []);
            $this->argue($definition, '$defaultOverrides', $blockConfig['defaults'] ?? []);
        }
    }

    /**
     * Sets an argument unless something already did: whoever was explicit —
     * the bundle, or a host wiring by hand — keeps the last word.
     */
    private function argue(Definition $definition, string $name, mixed $value): void
    {
        if (\array_key_exists($name, $definition->getArguments())) {
            return;
        }

        $definition->setArgument($name, $value);
    }
}
