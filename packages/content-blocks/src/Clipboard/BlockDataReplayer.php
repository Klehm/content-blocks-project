<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Turns one clipboard block's `data` into data it is safe to write.
 *
 * ---- Why this exists at all ----
 *
 * The two older restore paths — section-template insert and area import — read
 * rows this application wrote itself, so they keep block data verbatim and only
 * *warn* about keys the type no longer declares. The clipboard is different in
 * one decisive way: it lives in `localStorage`, so its payload is whatever the
 * user (or anything running in their browser) put there. It is input, not
 * truth, and the package already has a whitelist-and-validator for block data —
 * the block's own form. This replays the payload through it, exactly as if an
 * editor had typed those values into the sidebar and saved.
 *
 * ---- What survives ----
 *
 *  - **A field of the block's form** — submitted, so its `constraints` decide.
 *    A field that fails validation is dropped and reset to the type's default;
 *    the block still lands (an editor would rather fix one field than recreate
 *    the block), and the dropped names are reported so the UI can say so.
 *  - **A key the type declares in getDefaultData() without exposing a field** —
 *    kept verbatim. Same union rule as {@see BlockDataKeys}: it is part of the
 *    type's own data contract, and no form child can vouch for it.
 *  - **Nothing else.** An undeclared key never reaches `data`, which is the
 *    whole point of routing a user-writable payload through here.
 *
 * Collection entry ids (`_id`) are *not* carried across: entry forms mint their
 * own data, and {@see CollectionItemIds::backfill()} gives every entry a fresh
 * id on the way out. A pasted block is a new block, so per-entry information
 * keyed on the source's ids would not apply to it anyway.
 */
final class BlockDataReplayer
{
    /**
     * Safety bound on the drop-and-resubmit loop. Each pass removes at least
     * one field, so a block would need this many independently invalid fields
     * to reach it; the bound is only there so a pathological type cannot spin.
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
     * @throws \InvalidArgumentException when the type is not registered — callers
     *                                   decide what an unknown type means, so it
     *                                   never reaches here (see {@see ClipboardPaster})
     */
    public function replay(string $type, array $data): BlockDataReplayResult
    {
        if (!$this->registry->has($type)) {
            throw new \InvalidArgumentException(sprintf('Block type "%s" is not registered.', $type));
        }

        $blockType = $this->registry->get($type);
        // The form is built from the *defaults*, never from the payload: a
        // block type may size its own fields from the data it is given, and a
        // forged payload must not get to shape the form that is meant to
        // validate it.
        $initial = array_replace_recursive($this->blockDataDefaults->get(), $blockType->getDefaultData());

        $form = $this->buildForm($blockType, $initial);
        $editable = array_keys($form->all());
        $passthrough = array_diff(array_keys($blockType->getDefaultData()), $editable);

        $dropped = [];
        $submitted = $this->toSubmittedShape(
            $blockType,
            $initial,
            $this->keysIn($data, $editable),
            $dropped,
        );

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
                // Invalid with no child to blame (a form-level error): nothing
                // in the payload can be trusted, so the block lands on defaults.
                // Already empty and still refused means the type rejects even
                // its own defaults — there is nothing left to strip.
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
     * Converts stored block data into the shape a *submit* expects.
     *
     * The two are not the same, and assuming they were is the trap here.
     * `Block.data` holds model values — `styling.backgroundColor` is the string
     * `'#eb0540'` — while `submit()` takes what a browser would post, which for
     * a compound field with a data mapper ({@see \ContentBlocks\Form\Type\PaletteColorType})
     * is the array of its children's view values. Submitting the model shape
     * would fail on every such field and quietly reset it to the default.
     *
     * So the form itself does the conversion: one built *on the payload* maps
     * model → view, and reading its children back gives the post shape. That
     * form is a converter and nothing else — the values it produces are then
     * submitted into a form built from the defaults, which is the one that
     * validates. A payload that shapes its own converter therefore gains
     * nothing: whatever comes out still has to get past a clean form.
     *
     * A value the converter cannot even map (an array where a string belongs)
     * costs only its own field: the whole payload is tried first, and the
     * per-field retry isolates the offender.
     *
     * @param array<string, mixed> $initial
     * @param array<string, mixed> $candidate already whitelisted to editable keys
     * @param list<string>         $dropped   accumulator, passed by reference
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
        } catch (TransformationFailedException) {
            // Fall through to the per-field pass.
        }

        $posted = [];
        foreach ($candidate as $key => $value) {
            try {
                $posted += $this->postShapeOf($blockType, $initial, [$key => $value], [$key]);
            } catch (TransformationFailedException) {
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
     * @throws TransformationFailedException when a value cannot be mapped into the form
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
            // A value the converter could not represent comes back empty — an
            // out-of-list choice, say, which ChoiceType maps to ''. Submitting
            // *that* would blank the field silently; submitting the raw value
            // lets the clean form refuse it out loud, which is how it gets
            // reported and reset to the type's default.
            $raw = $values[$key] ?? null;
            $posted[$key] = ($shape === null || $shape === '') && $raw !== null && $raw !== ''
                ? $raw
                : $shape;
        }

        return $posted;
    }

    /**
     * A form's value as it would come back from the browser: leaves give their
     * view data, compound fields (and collections) an array keyed by child name.
     *
     * One compound family is *not* an array on the wire: an expanded choice
     * (radios, checkbox list) has one child per option, but a browser posts the
     * chosen **value**, and ChoiceType's own submit path reads it that way —
     * handing it a per-child map makes it choke. So a choice field always
     * answers with its view data, expanded or not.
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
     * Payload entries whose key is in $allowed, in payload order. Reserved-prefix
     * keys never pass: they belong to this package's machinery, not to the
     * editor's data, and are re-minted rather than carried.
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
