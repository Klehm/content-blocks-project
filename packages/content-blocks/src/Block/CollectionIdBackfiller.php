<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Gives a block's collection entries their `_id` from the type alone, for
 * data written without going through the block's form.
 *
 * @see docs/internals/i18n.md#paths-and-patterns
 */
final class CollectionIdBackfiller
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
        private readonly CollectionItemIds $collectionItemIds = new CollectionItemIds(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function backfill(string $type, array $data): array
    {
        if (!$this->registry->has($type)) {
            return $data;
        }

        // Only the form's shape is used (which children are collections).
        $form = $this->formFactory->create(BlockFormType::class, $data, [
            'block_type' => $this->registry->get($type),
            'block_data' => $data,
        ]);

        return $this->collectionItemIds->backfill($form, $data);
    }
}
