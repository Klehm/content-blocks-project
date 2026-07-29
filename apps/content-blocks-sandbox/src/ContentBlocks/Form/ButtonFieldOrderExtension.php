<?php

declare(strict_types=1);

namespace App\ContentBlocks\Form;

use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Reference example — **reordering** a block's edit form.
 *
 * A form has no "set order" API: children render in insertion order, so
 * reordering means re-adding them in the order you want. Re-adding the child
 * *builder* (not the field name) keeps its type, options, data and constraints
 * — nothing has to be re-declared, so this stays correct when the kit changes
 * a field's type.
 *
 * Here the button block leads with the link instead of the label: `url` first,
 * then `text`, then everything else in its original order.
 *
 * Two things to keep in mind:
 * - the sidebar groups fields into tabs by `data-cb-group`, so ordering only
 *   shows within a tab (this app's SEO fields stay in their own tab);
 * - the styling sub-form is added by BlockFormType *after* every extension has
 *   run, so it is always the last tab whatever an extension does.
 *
 * Runs last (`priority: -1000`) so it also sees the fields other extensions
 * added and can place them.
 */
#[AsBlockFormExtension('button', priority: -1000)]
final class ButtonFieldOrderExtension implements BlockFormExtensionInterface
{
    /** Fields pulled to the top, in this order; the rest keeps its order. */
    private const FIRST = ['url', 'text'];

    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $this->moveToFront($builder, self::FIRST);
    }

    /**
     * Rebuild the children list as [$names…, …the rest], skipping names that
     * are not present (another extension may have removed one — see
     * ButtonFieldRemovalExtension).
     *
     * @param list<string> $names
     */
    private function moveToFront(FormBuilderInterface $builder, array $names): void
    {
        $present = array_values(array_filter($names, static fn (string $n): bool => $builder->has($n)));
        $rest = array_values(array_diff(array_keys($builder->all()), $present));

        foreach ([...$present, ...$rest] as $name) {
            $child = $builder->get($name);   // keeps type + options + data
            $builder->remove($name);
            $builder->add($child);           // re-added at the end
        }
    }
}
