<?php

declare(strict_types=1);

namespace ContentBlocks\Translation;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Default {@see TranslatableFieldsInterface} — see it for the contract.
 *
 * Walks the block's form builder tree. Only the builder is created (no view, no
 * data mapping), the same cheap path {@see \ContentBlocks\Block\BlockDataKeys}
 * takes.
 */
final class TranslatableFields implements TranslatableFieldsInterface
{
    /**
     * Guards against a form type that nests itself. Ten levels is far past any
     * real block; a block that deep has a shape problem of its own.
     */
    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function forBlockType(string $blockType, array $data = []): array
    {
        if (!$this->registry->has($blockType)) {
            return [];
        }

        $builder = $this->formFactory->createBuilder(BlockFormType::class, null, [
            'block_type' => $this->registry->get($blockType),
            'block_data' => $data,
        ]);

        $out = [];
        $this->collect($builder, '', $out, 0);

        return $out;
    }

    /**
     * @param list<string> $out
     */
    private function collect(FormBuilderInterface $builder, string $prefix, array &$out, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($builder->all() as $name => $child) {
            $path = $prefix === '' ? $name : $prefix . '.' . $name;

            // A collection has no children until it is bound to data, so its
            // shape lives in `entry_type`. Descend into a throwaway prototype
            // of one entry and mark the segment as repeating.
            $entryType = $child->hasOption('entry_type') ? $child->getOption('entry_type') : null;
            if (\is_string($entryType) && $entryType !== '') {
                $entryOptions = $child->hasOption('entry_options') ? $child->getOption('entry_options') : [];
                $entry = $this->formFactory->createBuilder($entryType, null, \is_array($entryOptions) ? $entryOptions : []);
                $this->collect($entry, $path . '[]', $out, $depth + 1);

                continue;
            }

            if ($child->hasOption(TranslatableFieldTypeExtension::OPTION)
                && $child->getOption(TranslatableFieldTypeExtension::OPTION) === true
            ) {
                $out[] = $path;

                continue;
            }

            // Compound field that isn't tagged itself (a sub-form grouping
            // several inputs): its children may still be.
            if (\count($child->all()) > 0) {
                $this->collect($child, $path, $out, $depth + 1);
            }
        }
    }
}
