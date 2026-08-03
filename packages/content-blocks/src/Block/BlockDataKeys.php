<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Answers "which keys can this block type legitimately hold?" — the question
 * both restore paths ask when they replay stored data that may predate the
 * current code (section-template insert, area import).
 *
 * Known keys are the **union** of two sources, because neither alone describes
 * what a block stores:
 *
 *  - the type's getDefaultData() — covers keys a block declares but does not
 *    expose as an editable field;
 *  - the children of the block's built edit form — covers `styling` (added by
 *    {@see BlockFormType}, deliberately absent from getDefaultData()) and every
 *    field contributed by a host
 *    {@see \ContentBlocks\Form\Extension\BlockFormExtensionInterface}.
 *
 * Reading only the first flagged `styling` on every styled block and every
 * host-added field; reading only the second would flag declared-but-not-editable
 * keys. Hence the union.
 */
final class BlockDataKeys
{
    /**
     * Prefix this package reserves inside `Block.data` — at every level,
     * including collection entries. A key starting with `_` belongs to
     * ContentBlocks; **a block type must not declare one.**
     *
     * It exists because some stored values are written by machinery rather than
     * by the block's own form, and would otherwise be reported as unknown keys
     * by both restore paths (area import, section-template insert). Today that
     * is `_id` — the stable identity of a collection entry, see
     * {@see CollectionItemIds}. Reserving the *prefix* rather than a list of
     * names means the next such need does not reopen a frozen data contract.
     *
     * Note the flip side, verified rather than assumed: a POST carrying an
     * underscore-prefixed key is *ignored* by the block form (only declared
     * children map), so reserving the namespace opens no write path through the
     * editor.
     */
    public const RESERVED_PREFIX = '_';

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * Keys present in $data that nothing in the block's current shape can hold.
     * An unregistered type has no shape to compare against, so nothing is
     * reported — the caller decides what an unknown type means (the template
     * flow refuses it, the import flow warns).
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

        // Building the form (rather than reading a static declaration) is the
        // only definition that stays true when a host adds a field. Only the
        // builder is created — no view, no data mapping — so this stays cheap
        // enough for the admin-side operations that call it.
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
