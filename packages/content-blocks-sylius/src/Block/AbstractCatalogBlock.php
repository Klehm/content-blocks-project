<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\BlockType\BlockPurposeInterface;
use ContentBlocks\Sylius\Form\Type\CatalogCodeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A block showing catalog data. It stores codes and reads the catalog at
 * render time, so prices and stock are always the shop's current ones.
 *
 * @see docs/internals/sylius.md#catalog-blocks
 */
abstract class AbstractCatalogBlock extends AbstractBlockType implements
    BlockPreviewHintInterface,
    BlockPurposeInterface
{
    public function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('content_blocks_sylius.block.' . $this->getType() . '.label');
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksSyliusPlugin/block/' . $this->getType() . '.html.twig';
    }

    public function previewHint(array $data): ?BlockPreviewHint
    {
        return BlockPreviewHint::generic();
    }

    /** @param array<string, mixed> $options */
    protected function addProductField(
        FormBuilderInterface $builder,
        string $name,
        bool $multiple = false,
        array $options = [],
    ): void {
        $builder->add($name, CatalogCodeType::class, $options + [
            'label' => 'content_blocks_sylius.field.' . ($multiple ? 'products' : 'product'),
            'resource' => 'product',
            'multiple' => $multiple,
        ]);
    }

    /** @param array<string, mixed> $options */
    protected function addTaxonField(
        FormBuilderInterface $builder,
        string $name,
        bool $multiple = false,
        array $options = [],
    ): void {
        $builder->add($name, CatalogCodeType::class, $options + [
            'label' => 'content_blocks_sylius.field.' . ($multiple ? 'taxons' : 'taxon'),
            'resource' => 'taxon',
            'multiple' => $multiple,
        ]);
    }

    /**
     * Translated choices of a field: `choice_translation_domain` does not
     * inherit, so every choice field sets it.
     *
     * @param array<string, string> $choices
     *
     * @return array<string, mixed>
     */
    protected static function choiceOptions(string $label, array $choices): array
    {
        return [
            'label' => $label,
            'choices' => $choices,
            'choice_translation_domain' => 'messages',
        ];
    }
}
