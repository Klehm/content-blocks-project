<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base class for kit blocks, giving a host three levers — `options`, `choices`
 * and `defaults` — all wired from `content_blocks_kit.blocks.<type>`.
 *
 * @see docs/internals/kit.md#three-levers-one-source-of-truth
 */
abstract class AbstractKitBlock extends AbstractBlockType
{
    /**
     * @param array<string, mixed>        $options          coded + host
     * @param array<string, list<string>> $choiceOverrides  `choices.<field>`
     * @param array<string, mixed>        $defaultOverrides `defaults.<field>`
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
     * Falls back to the coded default, so a block built without the bundle's
     * merge still works.
     */
    protected function option(string $key): mixed
    {
        return $this->options[$key] ?? static::defaultOptions()[$key] ?? null;
    }

    /**
     * Coded choice maps by field: `['field' => ['label' => 'value']]`,
     * instance-level so dynamic sets (icons, column counts) can be computed.
     *
     * @see choices()
     * @see choiceConstraint()
     *
     * @return array<string, array<array-key, string|int>>
     */
    protected function choiceFields(): array
    {
        return [];
    }

    /**
     * The shared align map, centralized so its values and translation keys stay
     * in sync across every block exposing one.
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
     * Resolved choices for a ChoiceType field: a list override restricts the
     * coded map, a map override replaces it.
     *
     * @see docs/kit/configuration.md for both shapes, and for how far an added
     *      value travels into the rendered markup
     *
     * @return array<array-key, string|int> label => value, for `choices`
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
     * A loop rather than `array_flip()`, because the host's map is arbitrary
     * input and two values sharing a label would collapse.
     *
     * @see docs/internals/kit.md#why-the-constraint-is-a-union
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
     * Over the **union** of the coded and resolved value sets. Both halves earn
     * their place.
     *
     * @see docs/internals/kit.md#why-the-constraint-is-a-union
     */
    protected function choiceConstraint(string $field): Assert\Choice
    {
        $coded = array_values($this->choiceFields()[$field] ?? []);
        $resolved = array_values($this->choices($field));

        return new Assert\Choice(choices: array_values(array_unique([...$coded, ...$resolved])));
    }

    /**
     * Coded initial data. Override this, not {@see getDefaultData()}, which
     * merges the host's `defaults.<field>` over it.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaults(): array;

    /**
     * Coded defaults, host overrides merged over them, reconciled with the
     * resolved choice sets. Only declared keys survive.
     *
     * @see docs/internals/kit.md#three-levers-one-source-of-truth
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
     * Pulls each default back into the set the picker offers — and only when it
     * is not already on offer.
     *
     * @see docs/internals/kit.md#defaults-are-pulled-back-into-the-offered-set
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
     * Reads a string field out of stored block data, defensively: the row may
     * predate the current shape of the block.
     *
     * @see \ContentBlocks\BlockType\BlockPreviewHintInterface::previewHint()
     *
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
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
     *
     * @param array<string, mixed> $data
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
     * The block's host-configurable surface as shipped, for the
     * `content-blocks-kit:blocks` command. Reads the schema `buildForm()` uses.
     *
     * @return array{
     *     options: array<string, mixed>,
     *     choices: array<string, array<array-key, string|int>>,
     *     defaults: array<string, mixed>,
     * }
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
     * Same shape as {@see describe()}, but with host config applied — what an
     * operator debugging their own `choices` needs, not the coded set.
     *
     * @return array{
     *     options: array<string, mixed>,
     *     choices: array<string, array<array-key, string|int>>,
     *     defaults: array<string, mixed>,
     * }
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
