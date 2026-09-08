<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

interface BlockTypeInterface
{
    /**
     * Unique identifier of the block type (e.g. "text", "title", "image").
     */
    public static function getType(): string;

    /**
     * A plain string when already translated, or a TranslatableInterface when
     * the key lives in a custom domain.
     *
     * @see docs/internals/blocks.md#labels-and-icons-cross-a-trust-boundary
     */
    public static function getLabel(): string|TranslatableInterface;

    /**
     * Self-contained inline SVG using `currentColor`, or null for a generic
     * icon. **Injected as-is into the picker DOM** — trusted code only.
     *
     * @see docs/internals/blocks.md#labels-and-icons-cross-a-trust-boundary
     */
    public static function getIcon(): ?string;

    /**
     * Builds the Symfony Form for this block type.
     * Called by BlockFormType to render the edit form.
     *
     * @param array<string, mixed> $data
     */
    public function buildForm(FormBuilderInterface $builder, array $data): void;

    /**
     * Default data on creation.
     *
     * @return array<string, mixed>
     */
    public function getDefaultData(): array;

    /**
     * Custom Twig form theme for the edit form.
     * Return null to use the default form rendering.
     */
    public function getFormTheme(): ?string;

    /**
     * The block's view, for the public page and the preview alike. Null
     * renders nothing; there is no generic fallback.
     *
     * @see docs/internals/blocks.md#the-view-template-contract
     */
    public function getViewTemplate(): ?string;

    /**
     * Whether the builder may refresh this block's preview in place. About the
     * rendered view, not the edit form.
     *
     * @see docs/internals/blocks.md#preview-hot-reload-is-opt-in
     */
    public function supportsPreviewHotReload(): bool;
}
