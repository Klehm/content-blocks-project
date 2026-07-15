<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Form\Type\PaletteColorType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A horizontal rule with a line style and an optional palette color.
 */
#[AsContentBlock(priority: 25)]
class DividerBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'divider';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.divider.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M4 12h16M8 8l4-4 4 4M8 16l4 4 4-4"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('style', ChoiceType::class, [
                'label' => 'cb_kit.block.divider.field.style',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('style'),
                'constraints' => [$this->choiceConstraint('style')],
            ])
            // Stores a plain '#hex' ('' = the stylesheet's default border color).
            ->add('color', PaletteColorType::class, [
                'label' => 'cb_kit.block.divider.field.color',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ]);
    }

    protected function choiceFields(): array
    {
        return [
            'style' => [
                'cb_kit.block.divider.style.solid' => 'solid',
                'cb_kit.block.divider.style.dashed' => 'dashed',
                'cb_kit.block.divider.style.dotted' => 'dotted',
            ],
        ];
    }

    protected function defaults(): array
    {
        return [
            'style' => 'solid',
            'color' => '',
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/divider/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
