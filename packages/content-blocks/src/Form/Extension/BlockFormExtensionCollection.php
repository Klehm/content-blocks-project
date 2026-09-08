<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * Runs the {@see BlockFormExtensionInterface} services targeting the block
 * being edited. Pairing and order are assembled at compile time.
 *
 * @see docs/internals/forms.md#why-form-extensions-are-a-package-seam
 */
final class BlockFormExtensionCollection
{
    /** @var list<array{0: BlockFormExtensionInterface, 1: list<string>}> */
    private array $extensions;

    /**
     * @param iterable<array{
     *     0: BlockFormExtensionInterface,
     *     1: list<string>,
     * }> $extensions priority-ordered [extension, targeted type ids] pairs
     */
    public function __construct(iterable $extensions = [])
    {
        $this->extensions = $extensions instanceof \Traversable
            ? iterator_to_array($extensions, false)
            : array_values($extensions);
    }

    /**
     * Called by {@see \ContentBlocks\Form\Type\BlockFormType} after the block's
     * own buildForm().
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
