<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\DependencyInjection;

use ContentBlocks\Kit\Block\AbstractKitBlock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Hands `content_blocks_kit.blocks.<type>` to **every** registered block that
 * extends {@see AbstractKitBlock}, not only to the services the bundle declares
 * itself.
 *
 * The bundle registers its own blocks in `loadExtension()`, with the config
 * already resolved as constructor arguments. That covered exactly the blocks a
 * host had *not* extended: subclassing a kit block requires switching the kit's
 * own service off (`enabled: false`, or the two services collide on one type
 * id), which removed the type from the registration loop — so the host's
 * subclass was autowired with the constructor defaults and its `options`,
 * `choices` and `defaults` were dropped. Nothing said so: the YAML stayed
 * valid, it simply applied to nothing.
 *
 * Working from the `content_blocks.block_type` tag rather than from a tag of
 * our own is deliberate — it is the tag `#[AsContentBlock]` puts on anything
 * that is a block at all, so a subclass that reaches the picker cannot miss
 * this. The type is read from `getType()`, the same identity the config is
 * keyed by, which also means a subclass introducing its own type id is
 * configured under that id.
 *
 * @internal Wiring detail of the bundle. See FREEZE-AUDIT.md.
 */
final class KitBlockConfigPass implements CompilerPassInterface
{
    /**
     * @param \Closure(): array<string, array{options?: array<string, mixed>, choices?: array<string, mixed>, defaults?: array<string, mixed>}> $config
     *        Deferred because the bundle registers this pass in `build()`, before
     *        the extension has processed a single line of configuration.
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

            // Merged against the *subclass's* coded defaults, so a subclass that
            // widened `defaultOptions()` keeps its own additions underneath the
            // host's config.
            $this->argue($definition, '$options', array_replace($class::defaultOptions(), $blockConfig['options'] ?? []));
            $this->argue($definition, '$choiceOverrides', $blockConfig['choices'] ?? []);
            $this->argue($definition, '$defaultOverrides', $blockConfig['defaults'] ?? []);
        }
    }

    /**
     * Sets a named constructor argument unless something already did — the
     * bundle's own registrations, or a host wiring a block by hand. Whoever was
     * explicit keeps the last word.
     */
    private function argue(Definition $definition, string $name, mixed $value): void
    {
        if (\array_key_exists($name, $definition->getArguments())) {
            return;
        }

        $definition->setArgument($name, $value);
    }
}
