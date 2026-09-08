<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use Symfony\Component\Form\FormInterface;

/**
 * Gives every collection entry in a block's data a stable `_id`, so per-entry
 * information survives a reorder, a duplicate or a delete.
 *
 * @see docs/internals/clipboard.md#stable-entry-ids
 */
final class CollectionItemIds
{
    public const KEY = '_id';

    /**
     * Ensures every entry reachable from $form has an `_id`, recursing into
     * nested collections. Driven by the form, not by the shape of the data.
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
        // A row with no matching entry form still gets an id; it just cannot
        // be recursed into.
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
