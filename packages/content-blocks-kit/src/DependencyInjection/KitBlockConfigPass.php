<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\DependencyInjection;

use ContentBlocks\Kit\Block\AbstractKitBlock;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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

        $known = array_keys(ContentBlocksKitBundle::BLOCKS);

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

            // No container yet: a kit block's type cannot depend on its
            // constructor, which the kit docs state for subclasses.
            $type = (new \ReflectionClass($class))
                ->newInstanceWithoutConstructor()
                ->getType();
            $known[] = $type;
            $blockConfig = $blocksConfig[$type] ?? null;

            if ($blockConfig === null) {
                continue;
            }

            // Against the *subclass's* defaults, so one that widened
            // defaultOptions() keeps its additions under the host's config.
            $this->argue($definition, '$options', array_replace($class::defaultOptions(), $blockConfig['options'] ?? []));
            $this->argue($definition, '$choiceOverrides', $blockConfig['choices'] ?? []);
            $this->argue($definition, '$defaultOverrides', $blockConfig['defaults'] ?? []);
        }

        $this->refuseUnknown(array_keys($blocksConfig), $known);
    }

    /**
     * A misspelled type used to configure nothing, silently.
     *
     * @param list<string|int> $configured
     * @param list<string>     $known
     */
    private function refuseUnknown(array $configured, array $known): void
    {
        foreach ($configured as $type) {
            $type = (string) $type;
            if (\in_array($type, $known, true)) {
                continue;
            }

            $close = array_filter(
                array_unique($known),
                static fn (string $k): bool => levenshtein($type, $k) <= 2,
            );

            $hint = $close === []
                ? ''
                : sprintf(' (did you mean "%s"?)', implode('", "', $close));
            $message = 'Unknown block type "%s" under "content_blocks_kit.blocks"%s.'
                . ' Known kit block types: %s.';

            $list = implode(', ', array_unique($known));

            throw new InvalidConfigurationException(sprintf($message, $type, $hint, $list));
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
