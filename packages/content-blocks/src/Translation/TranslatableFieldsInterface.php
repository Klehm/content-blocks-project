<?php

declare(strict_types=1);

namespace ContentBlocks\Translation;

/**
 * Answers "which of this block type's fields may be translated?" by reading the
 * `cb_translatable` tags off its built edit form
 * (see {@see \ContentBlocks\Form\Extension\TranslatableFieldTypeExtension}).
 *
 * Reading the built form rather than a static declaration is the same rule
 * {@see \ContentBlocks\Block\BlockDataKeys} follows, and for the same reason:
 * it stays true when a host adds fields through
 * {@see \ContentBlocks\Form\Extension\BlockFormExtensionInterface}. A host that
 * adds a translatable field to someone else's block gets it picked up for free.
 *
 * The core ships no consumer for this — it is the allow-list a translation
 * package builds its per-field UI from, and the filter it applies when merging
 * a locale payload. It lives in the core so the *convention* is frozen with the
 * 1.0 contract.
 *
 * Override seam: the bundle aliases this to the shipped {@see TranslatableFields}.
 */
interface TranslatableFieldsInterface
{
    /**
     * Paths of the translatable fields of $blockType, in form-declaration
     * order. Nesting is dotted and collection entries are marked `[]`:
     *
     *     ['title', 'items[].label', 'items[].description']
     *
     * An unregistered block type yields an empty list — same "no shape to
     * inspect, so nothing to report" rule the restore paths use.
     *
     * @param array<string, mixed> $data current block data; passed to the form
     *                                    builder because a block may declare
     *                                    fields conditionally on its own values
     *
     * @return list<string>
     */
    public function forBlockType(string $blockType, array $data = []): array;
}
