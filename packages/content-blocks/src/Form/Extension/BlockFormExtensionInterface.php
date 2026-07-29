<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * Host seam to add fields to the edit form of one (or several) block types
 * without subclassing the block.
 *
 * Because every block is edited through the single {@see \ContentBlocks\Form\Type\BlockFormType}
 * (one form type / prefix for all blocks), a stock Symfony `FormTypeExtension`
 * cannot be scoped to one block — it matches by class and fires for every block.
 * This interface is the ContentBlocks-native answer: tag an implementation with
 * {@see AsBlockFormExtension} declaring which block type ids it targets (or a
 * global `'*'`), and {@see BlockFormType} calls it — after the block's own
 * `buildForm()` — for every matching block.
 *
 * The added field's value round-trips like any other: the compound block form
 * maps its declared children, so the extension field's value persists into
 * `Block.data` as-is (block data is never pruned). Render it via a host block
 * template override.
 *
 * The builder is the block's own, so the seam is not add-only:
 * - `$builder->remove('field')` drops a field. Its stored value is *frozen*,
 *   not deleted (the form's model data is the block's data array), and a POST
 *   still carrying the field is ignored — only declared children map.
 * - re-adding child builders (`$b->add($b->get('url'))`) reorders the form,
 *   since children render in insertion order; passing the builder rather than
 *   the name keeps the field's type, options and data.
 * The `styling` sub-form is appended by BlockFormType after every extension
 * has run, so it always stays last.
 *
 * Example — add a `rel` field to the button block only:
 *
 *     #[AsBlockFormExtension('button')]
 *     final class ButtonRelExtension implements BlockFormExtensionInterface
 *     {
 *         public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
 *         {
 *             $builder->add('rel', TextType::class, [
 *                 'required' => false,
 *                 'data' => $data['rel'] ?? '',
 *             ]);
 *         }
 *     }
 */
interface BlockFormExtensionInterface
{
    /**
     * Add fields to the block's edit form builder.
     *
     * @param array<string, mixed> $data      effective block data (draft or published, defaults backfilled)
     * @param string               $blockType the id of the block being edited (e.g. `button`) — set when the
     *                                         extension targets several types and needs to branch on which one
     */
    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void;
}
