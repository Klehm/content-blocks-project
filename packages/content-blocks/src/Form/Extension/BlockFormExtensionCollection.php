<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * Runs the registered {@see BlockFormExtensionInterface} implementations that
 * target the block being edited, in priority order.
 *
 * Each entry pairs an extension with the list of block type ids it targets
 * (or `['*']` for global); the pairing + ordering is assembled at compile time
 * by {@see \ContentBlocks\DependencyInjection\BlockFormExtensionPass} from the
 * {@see AsBlockFormExtension} attribute.
 */
final class BlockFormExtensionCollection
{
    /** @var list<array{0: BlockFormExtensionInterface, 1: list<string>}> */
    private array $extensions;

    /**
     * @param iterable<array{0: BlockFormExtensionInterface, 1: list<string>}> $extensions
     *        [extension, targeted block type ids] pairs, already priority-ordered
     */
    public function __construct(iterable $extensions = [])
    {
        $this->extensions = $extensions instanceof \Traversable
            ? iterator_to_array($extensions, false)
            : array_values($extensions);
    }

    /**
     * Invoke every extension targeting $blockType, letting each add fields to
     * the builder. Called by {@see \ContentBlocks\Form\Type\BlockFormType} after
     * the block's own buildForm().
     *
     * @param array<string, mixed> $data
     */
    public function applyTo(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        foreach ($this->extensions as [$extension, $blockTypes]) {
            if (self::supports($blockTypes, $blockType)) {
                $extension->buildForm($builder, $data, $blockType);
            }
        }
    }

    /** @param list<string> $blockTypes */
    private static function supports(array $blockTypes, string $blockType): bool
    {
        return \in_array('*', $blockTypes, true) || \in_array($blockType, $blockTypes, true);
    }
}
