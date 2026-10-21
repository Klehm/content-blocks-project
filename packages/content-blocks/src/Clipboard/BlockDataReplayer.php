<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\Exception\ExceptionInterface as FormExceptionInterface;
use Symfony\Component\Form\Extension\Core\DataMapper\DataMapper;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Turns one clipboard block's `data` into data it is safe to write, by
 * replaying it through the block's own form — the payload is untrusted input.
 *
 * @see docs/internals/clipboard.md#why-the-clipboard-needs-a-replayer
 */
final class BlockDataReplayer
{
    /**
     * Bound on the drop-and-resubmit loop — each pass removes a field, so this
     * is only here so a pathological type cannot spin.
     */
    private const MAX_PASSES = 20;

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
        private readonly BlockDataDefaults $blockDataDefaults,
        private readonly CollectionItemIds $collectionItemIds,
    ) {
    }

    /**
     * @param array<string, mixed> $data raw payload data, untrusted
     *
     * @throws \InvalidArgumentException when the type is unregistered; callers
     *                                   decide what that means beforehand
     */
    public function replay(string $type, array $data): BlockDataReplayResult
    {
        if (!$this->registry->has($type)) {
            throw new \InvalidArgumentException(sprintf('Block type "%s" is not registered.', $type));
        }

        $blockType = $this->registry->get($type);
        // Built from the defaults, never the payload: a type may size its own
        // fields from its data, and a forgery must not shape its own validator.
        $initial = array_replace_recursive($this->blockDataDefaults->get(), $blockType->getDefaultData());

        $form = $this->buildForm($blockType, $initial);
        $editable = array_keys($form->all());
        $passthrough = array_diff(array_keys($blockType->getDefaultData()), $editable);

        $dropped = [];
        $candidate = [];
        foreach ($this->keysIn($data, $editable) as $key => $value) {
            if ($this->fits($form->get($key), $value)) {
                $candidate[$key] = $value;
            } else {
                $dropped[] = $key;
            }
        }
        $submitted = $this->toSubmittedShape($blockType, $initial, $candidate, $dropped);

        for ($pass = 0; $pass < self::MAX_PASSES; ++$pass) {
            // A form cannot be submitted twice, so each pass gets a fresh one.
            $form = $this->buildForm($blockType, $initial);
            // clearMissing: false — a key the payload omits keeps the type's
            // default rather than being blanked.
            $form->submit($submitted, false);

            if ($form->isValid()) {
                break;
            }

            $failing = $this->failingChildren($form);
            if ($failing === []) {
                // A form-level error blames no child, so the block lands on
                // defaults. Already empty and still refused: nothing to strip.
                if ($submitted === []) {
                    break;
                }
                $dropped = [...$dropped, ...array_keys($submitted)];
                $submitted = [];
                continue;
            }

            foreach ($failing as $name) {
                unset($submitted[$name]);
                $dropped[] = $name;
            }
        }

        $out = $form->isSubmitted() && \is_array($form->getData()) ? $form->getData() : $initial;
        foreach ($this->keysIn($data, $passthrough) as $key => $value) {
            $out[$key] = $value;
        }

        return new BlockDataReplayResult(
            $this->collectionItemIds->backfill($form, $out),
            array_values(array_unique($dropped)),
        );
    }

    /**
     * Whether a value has a shape the field can hold at all: a list where a
     * string goes would reach Symfony as a warning, not as a refusal.
     *
     * @see docs/internals/clipboard.md#a-value-of-the-wrong-shape
     */
    private function fits(FormInterface $field, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $config = $field->getConfig();
        if ($this->isChoice($field)) {
            return $config->getOption('multiple') ? \is_array($value) : !\is_array($value);
        }
        if ($this->isCollection($field)) {
            if (!\is_array($value)) {
                return false;
            }
            $entry = $this->entryForm($field);
            foreach ($value as $item) {
                if ($entry !== null && !$this->fits($entry, $item)) {
                    return false;
                }
            }

            return true;
        }
        if (!$config->getCompound()) {
            return !\is_array($value);
        }
        // A type mapping its own data (a palette colour) stores a shape only
        // it knows; the later passes judge it.
        if (!$config->getDataMapper() instanceof DataMapper) {
            return true;
        }
        if (!\is_array($value)) {
            return false;
        }
        foreach ($field as $name => $child) {
            if (\array_key_exists((string) $name, $value) && !$this->fits($child, $value[(string) $name])) {
                return false;
            }
        }

        return true;
    }

    private function isCollection(FormInterface $form): bool
    {
        for ($type = $form->getConfig()->getType(); $type !== null; $type = $type->getParent()) {
            if ($type->getInnerType() instanceof CollectionType) {
                return true;
            }
        }

        return false;
    }

    /** One entry of a collection, built alone: what each item must fit. */
    private function entryForm(FormInterface $collection): ?FormInterface
    {
        $config = $collection->getConfig();
        $entryType = $config->getOption('entry_type');
        if (!\is_string($entryType)) {
            return null;
        }

        try {
            return $config->getFormFactory()->createNamed(
                '__name__',
                $entryType,
                null,
                (array) $config->getOption('entry_options'),
            );
        } catch (FormExceptionInterface) {
            return null;
        }
    }

    /**
     * Converts stored block data into the shape a *submit* expects — the model
     * shape and the posted shape are not the same.
     *
     * @see docs/internals/clipboard.md#the-view-shape-trap
     *
     * @param array<string, mixed> $initial
     * @param array<string, mixed> $candidate whitelisted to editable keys
     * @param list<string>         $dropped   accumulator, by reference
     *
     * @return array<string, mixed>
     */
    private function toSubmittedShape(
        BlockTypeInterface $blockType,
        array $initial,
        array $candidate,
        array &$dropped,
    ): array {
        if ($candidate === []) {
            return [];
        }

        try {
            return $this->postShapeOf($blockType, $initial, $candidate, array_keys($candidate));
        } catch (FormExceptionInterface) {
            // Fall through to the per-field pass.
        }

        $posted = [];
        foreach ($candidate as $key => $value) {
            try {
                $posted += $this->postShapeOf($blockType, $initial, [$key => $value], [$key]);
            } catch (FormExceptionInterface) {
                $dropped[] = (string) $key;
            }
        }

        return $posted;
    }

    /**
     * @param array<string, mixed> $initial
     * @param array<string, mixed> $values
     * @param list<array-key>      $keys    which children to read back
     *
     * @return array<string, mixed>
     *
     * @throws FormExceptionInterface when a value will not map in, or is of
     *                                a shape the field cannot even hold
     */
    private function postShapeOf(BlockTypeInterface $blockType, array $initial, array $values, array $keys): array
    {
        $form = $this->buildForm($blockType, array_replace($initial, $values));

        $posted = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (!$form->has($key)) {
                continue;
            }

            $shape = $this->viewShape($form->get($key));
            // An unrepresentable value comes back empty, and submitting that
            // blanks the field silently — so submit the raw one and be refused.
            $raw = $values[$key] ?? null;
            $posted[$key] = ($shape === null || $shape === '') && $raw !== null && $raw !== ''
                ? $raw
                : $shape;
        }

        return $posted;
    }

    /**
     * A form's value as the browser would post it. A choice field always
     * answers with its view data, expanded or not.
     *
     * @see docs/internals/clipboard.md#the-view-shape-trap
     */
    private function viewShape(FormInterface $form): mixed
    {
        if (\count($form) === 0 || $this->isChoice($form)) {
            return $form->getViewData();
        }

        $out = [];
        foreach ($form as $name => $child) {
            $out[(string) $name] = $this->viewShape($child);
        }

        return $out;
    }

    /**
     * Whether this form is a choice field — its own type or anything built on
     * it, since a host type extending ChoiceType inherits the same wire shape.
     */
    private function isChoice(FormInterface $form): bool
    {
        for ($type = $form->getConfig()->getType(); $type !== null; $type = $type->getParent()) {
            if ($type->getInnerType() instanceof ChoiceType) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $initial
     */
    private function buildForm(BlockTypeInterface $blockType, array $initial): FormInterface
    {
        return $this->formFactory->create(BlockFormType::class, $initial, [
            'block_type' => $blockType,
            'block_data' => $initial,
        ]);
    }

    /**
     * Payload entries whose key is in $allowed, in payload order.
     * Reserved-prefix keys never pass; they are re-minted rather than carried.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $allowed
     *
     * @return array<string, mixed>
     */
    private function keysIn(array $data, array $allowed): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, BlockDataKeys::RESERVED_PREFIX)) {
                continue;
            }
            if (\in_array($key, $allowed, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Names of the direct children carrying an error, their own or a
     * descendant's — the granularity at which a field is dropped.
     *
     * @return list<string>
     */
    private function failingChildren(FormInterface $form): array
    {
        $names = [];
        foreach ($form as $name => $child) {
            if (\count($child->getErrors(true)) > 0) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
