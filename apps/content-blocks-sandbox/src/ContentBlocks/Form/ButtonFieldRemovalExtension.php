<?php

declare(strict_types=1);

namespace App\ContentBlocks\Form;

use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Reference example — **removing** a field from a block's edit form.
 *
 * The builder handed to an extension is the block's own form builder, so
 * `remove()` is part of the seam: a host can drop a field its design system
 * does not allow, without subclassing the block or forking the kit. Here:
 * "no full-width buttons" — the `fullWidth` checkbox is taken off the button
 * form for good.
 *
 * Two consequences worth knowing (both covered by BlockFormTypeTest):
 *
 * 1. **No data loss.** The form's model data *is* the block's data array
 *    (see BlockComponent::instantiateForm), so a removed field's stored value
 *    is frozen, not deleted — blocks created before this extension keep their
 *    `fullWidth: true` and keep rendering that way. Reset the stored values
 *    with a migration if the rule must apply retroactively.
 * 2. **It is also a hard whitelist.** Only declared children map, so a crafted
 *    POST carrying `fullWidth` is dropped (it lands in the form's extra data)
 *    instead of reaching the draft.
 *
 * Runs early (`priority: 20`) so the field is gone before the extensions that
 * add or reorder fields run — see ButtonFieldOrderExtension.
 */
#[AsBlockFormExtension('button', priority: 20)]
final class ButtonFieldRemovalExtension implements BlockFormExtensionInterface
{
    /** Fields of the button block this app does not expose to editors. */
    private const REMOVED = ['fullWidth'];

    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        foreach (self::REMOVED as $name) {
            // has() keeps this resilient: a kit upgrade that renames or drops
            // the field must not fatal the whole block form.
            if ($builder->has($name)) {
                $builder->remove($name);
            }
        }
    }
}
