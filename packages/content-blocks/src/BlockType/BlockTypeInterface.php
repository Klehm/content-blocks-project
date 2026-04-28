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
}
