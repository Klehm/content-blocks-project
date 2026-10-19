<?php

declare(strict_types=1);

namespace ContentBlocks\DependencyInjection;

use ContentBlocks\BlockType\BlockCategoryRegistry;
use ContentBlocks\BlockType\BlockTypeRegistry;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal Wiring detail of the bundle. See FREEZE-AUDIT.md.
 */
final class BlockTypeCompilerPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private const TAG = 'content_blocks.block_type';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BlockTypeRegistry::class)) {
            return;
        }

        $definition = $container->findDefinition(BlockTypeRegistry::class);
        $known = $container->hasParameter('content_blocks.picker.categories')
            ? $container->getParameter('content_blocks.picker.categories')
            : BlockCategoryRegistry::BUILT_IN;

        $categories = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                $category = $tag['category'] ?? null;
                if (\is_string($category)) {
                    $this->assertKnown($category, $id, \is_array($known) ? $known : []);
                    $categories[$id] = $category;
                }
            }
        }

        // Honours #[AsContentBlock]'s `priority`, and the registry's insertion
        // order is the order within a picker group — so this controls it.
        foreach ($this->findAndSortTaggedServices(self::TAG, $container) as $ref) {
            $definition->addMethodCall('register', [$ref, $categories[(string) $ref] ?? null]);
        }
    }

    /**
     * @param array<mixed> $known
     */
    private function assertKnown(string $category, string $id, array $known): void
    {
        if (\array_key_exists($category, $known)) {
            return;
        }

        $names = array_map('strval', array_keys($known));
        $close = array_filter($names, static fn (string $n): bool => levenshtein($category, $n) <= 2);
        $hint = $close === [] ? '' : sprintf(' (did you mean "%s"?)', implode('", "', $close));

        $message = 'The block "%s" declares the picker category "%s"%s, which is'
            . ' not declared. Known: %s. Declare a new one under'
            . ' "content_blocks.picker.categories".';

        $names = implode(', ', $names);

        throw new InvalidConfigurationException(sprintf($message, $id, $category, $hint, $names));
    }
}
