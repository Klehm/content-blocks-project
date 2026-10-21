<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\Exception\ExceptionInterface as FormExceptionInterface;
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

        $blockType = $this->registry->get($type);
        // Only the form's shape is used (which children are collections). Data
        // of the wrong shape cannot build it, so the type's defaults do.
        foreach ([$data, $blockType->getDefaultData()] as $initial) {
            try {
                $form = $this->formFactory->create(BlockFormType::class, $initial, [
                    'block_type' => $blockType,
                    'block_data' => $initial,
                ]);

                return $this->collectionItemIds->backfill($form, $data);
            } catch (FormExceptionInterface) {
                continue;
            }
        }

        return $data;
    }
}
