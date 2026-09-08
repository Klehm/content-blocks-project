<?php

declare(strict_types=1);

namespace ContentBlocks\Translation;

/**
 * Which of a block type's fields may be translated, read off its **built** edit
 * form. Override seam; the core ships no consumer for it.
 *
 * @see docs/internals/forms.md#which-fields-are-translatable
 */
interface TranslatableFieldsInterface
{
    /**
     * Dotted paths in form-declaration order, collection entries marked `[]`.
     * An unregistered type yields an empty list.
     *
     * @see docs/internals/forms.md#which-fields-are-translatable
     *
     * @param array<string, mixed> $data passed to the builder, since a block
     *                                   may declare fields conditionally
     *
     * @return list<string>
     */
    public function forBlockType(string $blockType, array $data = []): array;
}
