<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base class for kit blocks. On top of the core {@see AbstractBlockType} it
 * gives the host three levers, all wired from `content_blocks_kit.blocks.<type>`:
 *
 *  - `options`  — block-level knobs (e.g. `max_columns`), merged over
 *                 {@see defaultOptions()} and read at runtime via {@see option()}.
 *  - `choices`  — a per-field allow-list restricting/reordering a ChoiceType's
 *                 options. Declared once per block in {@see choiceFields()} and
 *                 consumed in `buildForm()` via {@see choices()} — a single
 *                 source of truth the doc command can introspect too.
 *  - `defaults` — per-field overrides of {@see defaults()} (the block's coded
 *                 initial data), applied by the final {@see getDefaultData()}.
 *
 * The bundle injects the merged option set and the raw choice/default overrides
 * as constructor arguments at registration time, so at runtime a block reads a
 * fully-resolved surface — no null-coalescing against defaults needed. All three
 * arguments default to empty, so `new SomeBlock()` still works (unit tests, the
 * doc command) and yields the coded surface.
 */
abstract class AbstractKitBlock extends AbstractBlockType
{
    /**
     * @param array<string, mixed>              $options         Merged option set (defaults + host overrides).
     * @param array<string, list<string>>       $choiceOverrides Host `choices.<field>` allow-lists.
     * @param array<string, mixed>              $defaultOverrides Host `defaults.<field>` value overrides.
     */
    public function __construct(
        protected readonly array $options = [],
        protected readonly array $choiceOverrides = [],
        protected readonly array $defaultOverrides = [],
    ) {
    }

    /**
     * Default option values for this block. The host's
     * `content_blocks_kit.blocks.<type>.options` are merged over these.
     *
     * @return array<string, mixed>
     */
    public static function defaultOptions(): array
    {
        return [];
    }

    /**
     * Read a resolved option, falling back to the coded default (so the
     * block is robust even if constructed without the bundle's merge, e.g.
     * in a unit test).
     */
    protected function option(string $key): mixed
    {
        return $this->options[$key] ?? static::defaultOptions()[$key] ?? null;
    }

    /**
     * Coded choice maps keyed by field name: `['field' => ['label' => 'value']]`.
     * Instance-level so dynamic choices (icon set, column counts derived from
     * options) can be computed. Blocks with ChoiceType fields override this and
     * consume it via {@see choices()} / {@see choiceConstraint()} in buildForm().
     *
     * @return array<string, array<string, string>>
     */
    protected function choiceFields(): array
    {
        return [];
    }

    /**
     * The shared horizontal-alignment choice map (start / center / end). Several
     * blocks expose an identical "align" field; centralizing it here keeps the
     * values and their translation keys in sync across the kit.
     *
     * @return array<string, string>
     */
    protected function alignChoices(): array
    {
        return [
            'cb_kit.block.align.left' => 'start',
            'cb_kit.block.align.center' => 'center',
            'cb_kit.block.align.right' => 'end',
        ];
    }

    /**
     * Resolved choices for a ChoiceType field: the coded map ({@see choiceFields()}),
     * filtered and reordered to the host's `choices.<field>` allow-list. Values
     * not in the coded set are ignored, and an empty or all-invalid override
     * falls back to the full coded map so the select is never empty.
     *
     * @return array<string, string> label => value, ready for ChoiceType `choices`
     */
    protected function choices(string $field): array
    {
        $coded = $this->choiceFields()[$field] ?? [];
        $allow = $this->choiceOverrides[$field] ?? null;

        if (!\is_array($allow) || [] === $allow) {
            return $coded;
        }

        // Keep only requested values that actually exist, in the host's order.
        $allow = array_values(array_intersect($allow, array_values($coded)));
        if ([] === $allow) {
            return $coded; // all-invalid → keep the full set rather than an empty select
        }

        $labelByValue = array_flip($coded); // value => label
        $out = [];
        foreach ($allow as $value) {
            $out[$labelByValue[$value]] = $value;
        }

        return $out;
    }

    /**
     * An {@see Assert\Choice} built from the FULL coded value set — a superset
     * of any host restriction — so narrowing the picker never invalidates data
     * already stored with a now-hidden value.
     */
    protected function choiceConstraint(string $field): Assert\Choice
    {
        return new Assert\Choice(choices: array_values($this->choiceFields()[$field] ?? []));
    }

    /**
     * Coded per-field default values (the block's initial data). Override this
     * instead of {@see getDefaultData()}; the host's `defaults.<field>` are
     * merged over it by {@see getDefaultData()}.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaults(): array;

    /**
     * Final default data: coded {@see defaults()} with the host's `defaults.<field>`
     * merged over them. Overrides are restricted to keys the block declares, so a
     * typo in host config never leaks a stray key into stored block data.
     *
     * @return array<string, mixed>
     */
    final public function getDefaultData(): array
    {
        $coded = $this->defaults();

        return array_replace($coded, array_intersect_key($this->defaultOverrides, $coded));
    }

    /**
     * Reads a string field out of stored block data.
     *
     * Every {@see \ContentBlocks\BlockType\BlockPreviewHintInterface::previewHint()}
     * in the kit goes through this: the data it receives is whatever was
     * persisted, possibly by an older version of the block, so a field that is
     * a string today may be absent or another type entirely in an old row.
     */
    protected static function previewString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Entries of a collection field, keeping only the well-formed ones — same
     * defensive contract as {@see previewString()}.
     *
     * @return list<array<string, mixed>>
     */
    protected static function previewItems(array $data, string $key = 'items'): array
    {
        $items = $data[$key] ?? null;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * First non-empty value of `$key` across a collection's entries — the
     * cover image of a gallery, the heading of the first card, and so on.
     */
    protected static function previewFirst(array $data, string $itemsKey, string $key): ?string
    {
        foreach (self::previewItems($data, $itemsKey) as $item) {
            $value = self::previewString($item, $key);
            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Machine-readable description of this block's host-configurable surface,
     * for tooling (see the `content-blocks-kit:blocks` command). Reads the same
     * coded schema `buildForm()` consumes, so it never drifts from reality.
     *
     * @return array{options: array<string, mixed>, choices: array<string, array<string, string>>, defaults: array<string, mixed>}
     */
    public function describe(): array
    {
        return [
            'options' => static::defaultOptions(),
            'choices' => $this->choiceFields(),
            'defaults' => $this->defaults(),
        ];
    }
}
