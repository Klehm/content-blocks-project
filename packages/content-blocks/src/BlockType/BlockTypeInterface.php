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
     * Label displayed in the UI. Return a plain string for
     * already-translated labels, or a TranslatableInterface (typically
     * Symfony's TranslatableMessage) when the label key lives in a custom
     * translation domain — the renderer will translate it at the boundary
     * before exposing it to the front (popover, JSON endpoints).
     */
    public static function getLabel(): string|TranslatableInterface;

    /**
     * Builds the Symfony Form for this block type.
     * Called by BlockFormType to render the edit form.
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
     * Custom Twig template for the block preview in admin.
     * Return null to use the default generic preview (key: value list).
     *
     * Template receives: { data, block, blockType }
     */
    public function getViewTemplate(): ?string;

    /**
     * Whether the builder may refresh this block's preview in place (hot
     * reload) instead of reloading the whole iframe after an edit.
     *
     * This is about the *rendered view*, not the edit form: return true only
     * when the view template produces self-contained markup that works as
     * soon as it is inserted into the DOM (static HTML, CSS-only behaviour).
     * Return false when the view needs a JavaScript init pass to function
     * (a carousel, a map, a third-party widget bootstrapped on load) — the
     * builder will fall back to a full iframe reload so that init runs again.
     *
     * Blocks that ship a little view JS but want hot reload can return true
     * and (re)initialise idempotently from the `cb:block:rendered` DOM event
     * the overlay dispatches on the freshly-swapped element.
     */
    public function supportsPreviewHotReload(): bool;
}
