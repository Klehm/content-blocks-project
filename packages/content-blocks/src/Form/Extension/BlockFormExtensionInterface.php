<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * Host seam to add fields to the edit form of one or several block types
 * without subclassing. Tag it with {@see AsBlockFormExtension}.
 *
 * @see docs/internals/forms.md#why-form-extensions-are-a-package-seam
 */
interface BlockFormExtensionInterface
{
    /**
     * Add fields to the block's edit form builder.
     *
     * @param array<string, mixed> $data      effective, defaults backfilled
     * @param string               $blockType id of the block being edited, to
     *                                        branch on when targeting several
     */
    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void;
}
