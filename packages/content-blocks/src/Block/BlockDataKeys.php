<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Which keys a block type can legitimately hold: the union of
 * `getDefaultData()` and the children of its built edit form.
 *
 * @see docs/internals/clipboard.md#which-keys-a-block-type-can-hold
 */
final class BlockDataKeys
{
    /**
     * Reserved to ContentBlocks at every level of `Block.data`, collection
     * entries included. **A block type must not declare one.**
     *
     * @see docs/internals/clipboard.md#the-reserved-prefix
     */
    public const RESERVED_PREFIX = '_';

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * Keys in $data that nothing in the block's current shape can hold. An
     * unregistered type reports none — the caller decides what that means.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public function unknownIn(string $blockType, array $data): array
    {
        if (!$this->registry->has($blockType)) {
            return [];
        }

        $type = $this->registry->get($blockType);

        // Building the form is the only definition that stays true when a host
        // adds a field. Builder only — no view, no mapping — so it stays cheap.
        $builder = $this->formFactory->createBuilder(BlockFormType::class, null, [
            'block_type' => $type,
            'block_data' => $data,
        ]);

        $known = [
            ...array_keys($type->getDefaultData()),
            ...array_keys($builder->all()),
        ];

        $unknown = array_diff(array_keys($data), $known);

        // Cast: a hand-written or legacy payload can carry numeric keys, and
        // this runs on data that predates the current code by definition.
        return array_values(array_filter(
            $unknown,
            static fn (int|string $key) => !str_starts_with((string) $key, self::RESERVED_PREFIX),
        ));
    }
}
