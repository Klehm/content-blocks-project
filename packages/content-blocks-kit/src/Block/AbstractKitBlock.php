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
     * Resolved choices for a ChoiceType field. The host's `choices.<field>`
     * override is read in one of two shapes, told apart by whether it is a list:
     *
     *  - **a list of values** — an *allow-list*: the coded map ({@see choiceFields()})
     *    filtered and reordered to it. Values the block does not code are ignored,
     *    and an empty or all-invalid list falls back to the full coded map so the
     *    select is never empty.
     *
     *        choices: { variant: [outline, primary] }
     *
     *  - **a map of `value: label`** — a *replacement*: this becomes the field's
     *    entire choice set, so it can add values the kit never coded, relabel
     *    them, and order them freely.
     *
     *        choices: { variant: { ghost: 'Ghost', primary: 'cb_kit.block.button.variant.primary' } }
     *
     *    Labels go through the field's `translation_domain`, so a translation key
     *    is translated and a plain string comes out as written — Symfony returns
     *    an unknown key unchanged.
     *
     * Adding a value only reaches the picker and the stored data. Whether it
     * *renders* is the templates' business: the kit's views pass a choice value
     * through as a CSS class token, so a new one needs the matching CSS on the
     * host's side, and a value that drives a branch (a gallery layout, an alert
     * glyph) lands on that branch's fallback until the view is overridden.
     *
     * @return array<string, string> label => value, ready for ChoiceType `choices`
     */
    protected function choices(string $field): array
    {
        $coded = $this->choiceFields()[$field] ?? [];
        $override = $this->choiceOverrides[$field] ?? null;

        if (!\is_array($override) || [] === $override) {
            return $coded;
        }

        if (!array_is_list($override)) {
            return self::mapToChoices($override);
        }

        // Keep only requested values that actually exist, in the host's order.
        $allow = array_values(array_intersect($override, array_values($coded)));
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
     * Turns a host's `value: label` map into ChoiceType's `label => value`.
     *
     * Written as a loop rather than `array_flip()` because the host's map is
     * arbitrary input: values are cast to string (YAML happily hands over
     * integers), and two values sharing a label would silently collapse — so
     * the second one is disambiguated instead of lost.
     *
     * @param array<array-key, mixed> $map
     *
     * @return array<string, string>
     */
    private static function mapToChoices(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $value = (string) $value;
            $label = \is_scalar($label) ? (string) $label : $value;
            if ($label === '') {
                $label = $value;
            }
            if (isset($out[$label])) {
                $label .= ' (' . $value . ')';
            }
            $out[$label] = $value;
        }

        return $out;
    }

    /**
     * An {@see Assert\Choice} over the **union** of the coded value set and the
     * resolved one.
     *
     * Both halves earn their place. The coded set is kept so narrowing the
     * picker never invalidates content already stored with a now-hidden value.
     * The resolved set is added so a value the host introduced through config
     * survives its own form — without it, `choices` could offer a value the
     * validator would then reject.
     */
    protected function choiceConstraint(string $field): Assert\Choice
    {
        $coded = array_values($this->choiceFields()[$field] ?? []);
        $resolved = array_values($this->choices($field));

        return new Assert\Choice(choices: array_values(array_unique([...$coded, ...$resolved])));
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
     * merged over them, then reconciled with the resolved choice sets. Overrides
     * are restricted to keys the block declares, so a typo in host config never
     * leaks a stray key into stored block data.
     *
     * @return array<string, mixed>
     */
    final public function getDefaultData(): array
    {
        $coded = $this->defaults();
        $data = array_replace($coded, array_intersect_key($this->defaultOverrides, $coded));

        return $this->reconcileChoiceDefaults($data);
    }

    /**
     * Pulls each choice field's default back into the set the picker actually
     * offers.
     *
     * A host that replaces `variant` without also setting `defaults.variant`
     * would otherwise have every new button start on the kit's coded default —
     * a value their config just removed, absent from the dropdown and unstyled
     * on the page. Falling back to the first offered value makes the two halves
     * of the config agree on their own.
     *
     * Only ever moves a default that is *not* on offer, so a block whose config
     * still contains its default is untouched.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function reconcileChoiceDefaults(array $data): array
    {
        foreach (array_keys($this->choiceFields()) as $field) {
            if (!\array_key_exists($field, $data)) {
                continue;
            }

            $offered = array_values($this->choices($field));
            if ($offered === [] || \in_array($data[$field], $offered, true)) {
                continue;
            }

            $data[$field] = $offered[0];
        }

        return $data;
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

    /**
     * The same shape as {@see describe()}, but as this instance was actually
     * built — host config applied.
     *
     * The two are deliberately separate. `describe()` documents the kit *as
     * shipped*, which is what the generated reference pages must show; this one
     * documents one installation, which is what an operator debugging their own
     * `choices` needs to see. Reporting the coded set to someone who has just
     * replaced it is how a config lands in the "it does nothing" bucket.
     *
     * @return array{options: array<string, mixed>, choices: array<string, array<string, string>>, defaults: array<string, mixed>}
     */
    public function describeConfigured(): array
    {
        $choices = [];
        foreach (array_keys($this->choiceFields()) as $field) {
            $choices[$field] = $this->choices($field);
        }

        return [
            'options' => array_replace(static::defaultOptions(), $this->options),
            'choices' => $choices,
            'defaults' => $this->getDefaultData(),
        ];
    }
}
