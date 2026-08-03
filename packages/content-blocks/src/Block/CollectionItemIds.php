<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use Symfony\Component\Form\FormInterface;

/**
 * Gives every collection entry in a block's data a stable `_id`.
 *
 * ---- Why ----
 *
 * A collection entry has no identity of its own: it is a position in a list.
 * Three editor actions shift those positions — reordering, duplicating and
 * deleting an entry — so anything that keys per-entry information by index
 * silently attaches it to the wrong entry afterwards. Content translation is
 * the first consumer that would suffer (a French title landing on the wrong
 * card), but the problem is older than translation and belongs to the data
 * contract, which is why the key ships in 1.0 rather than with the package
 * that needs it.
 *
 * ---- Scope of uniqueness ----
 *
 * Within one collection of one block. Cloning a section, importing an area or
 * inserting a section template all copy `_id`s verbatim, and that is correct:
 * the copies live under different block ids, and carrying the same entry ids
 * lets per-entry information map straight across to the copy.
 *
 * ---- Where ids are minted ----
 *
 * Only where an entry appears without one, which is exactly two places:
 * a newly added entry (the collection prototype has no `_id`), and a duplicated
 * entry (the copy is stripped of the original's id before it gets here — see
 * {@see \ContentBlocks\Twig\Component\BlockComponent::duplicateCollectionItem()}).
 * Everything else round-trips the existing value: a key no form child declares
 * is preserved by the compound form, which is what makes this work without an
 * `_id` field in a single item type.
 */
final class CollectionItemIds
{
    public const KEY = '_id';

    /**
     * Ensures every entry of every collection field reachable from $form has an
     * `_id`, recursing into nested collections (the kit's table: rows → cells).
     *
     * Driven by the **form** rather than by the shape of the data: only a form
     * knows which of a block's array values is a collection of entries as
     * opposed to an ordinary nested array a block type happens to store.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function backfill(FormInterface $form, array $data): array
    {
        foreach ($form as $name => $child) {
            $name = (string) $name;

            if (!\array_key_exists($name, $data) || !\is_array($data[$name])) {
                continue;
            }

            if ($this->isCollection($child)) {
                $data[$name] = $this->backfillEntries($child, $data[$name]);

                continue;
            }

            // A compound field that is not a collection (a sub-form grouping
            // several inputs) may still contain one further down.
            if (\count($child) > 0) {
                $data[$name] = $this->backfill($child, $data[$name]);
            }
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $entries
     *
     * @return array<int|string, mixed>
     */
    private function backfillEntries(FormInterface $collection, array $entries): array
    {
        // Entry forms exist per submitted row; a row with no matching form (data
        // written by something other than this form) still gets an id, it just
        // cannot be recursed into.
        $entryForms = iterator_to_array($collection);

        foreach ($entries as $index => $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            if (!isset($entry[self::KEY]) || !\is_string($entry[self::KEY]) || $entry[self::KEY] === '') {
                $entry[self::KEY] = $this->mint();
            }

            $entryForm = $entryForms[$index] ?? null;
            if ($entryForm instanceof FormInterface && \count($entryForm) > 0) {
                $entry = $this->backfill($entryForm, $entry);
            }

            $entries[$index] = $entry;
        }

        return $entries;
    }

    private function isCollection(FormInterface $form): bool
    {
        $config = $form->getConfig();

        return $config->hasOption('entry_type') && \is_string($config->getOption('entry_type'));
    }

    /**
     * Ids only have to be unique inside one collection, so a short random token
     * is enough — no need for a UUID's size in every stored row.
     */
    private function mint(): string
    {
        return bin2hex(random_bytes(6));
    }
}
