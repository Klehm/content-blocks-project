<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\Kit\Form\Type\ButtonGroupItemType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * A row of buttons sharing one size and alignment, so they sit side by side
 * and wrap together. The `max_items` option caps how many (3 by default).
 */
#[AsContentBlock(priority: 54)]
class ButtonGroupBlock extends AbstractKitBlock implements BlockPreviewHintInterface
{
    public static function defaultOptions(): array
    {
        return ['max_items' => 3];
    }

    public static function getType(): string
    {
        return 'button_group';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.button_group.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="2" y="9" width="9" height="6" rx="3"/><rect x="13" y="9" width="9" height="6" rx="3"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('items', LiveCollectionType::class, [
                'label' => 'cb_kit.block.button_group.field.items',
                'translation_domain' => 'content_blocks_kit',
                'entry_type' => ButtonGroupItemType::class,
                'entry_options' => [
                    'label' => false,
                    'variant_choices' => $this->choices('variant'),
                    'variant_constraint' => $this->choiceConstraint('variant'),
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'button_add_options' => [
                    'label' => 'cb_kit.block.button_group.action.add',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--primary'],
                ],
                'button_delete_options' => [
                    'label' => 'cb_kit.block.action.remove_item',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--danger'],
                ],
                'constraints' => [new Assert\Count(min: 1, max: max(1, (int) $this->option('max_items')))],
            ])
            ->add('size', ChoiceType::class, [
                'label' => 'cb_kit.block.button.field.size',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('size'),
                'constraints' => [$this->choiceConstraint('size')],
            ])
            ->add('align', ChoiceType::class, [
                'label' => 'cb_kit.block.field.align',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('align'),
                'constraints' => [$this->choiceConstraint('align')],
            ])
            ->add('stackOnMobile', CheckboxType::class, [
                'label' => 'cb_kit.block.button_group.field.stack_on_mobile',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ]);
    }

    protected function choiceFields(): array
    {
        return [
            'variant' => [
                'cb_kit.block.button.variant.primary' => 'primary',
                'cb_kit.block.button.variant.secondary' => 'secondary',
                'cb_kit.block.button.variant.outline' => 'outline',
                'cb_kit.block.button.variant.link' => 'link',
            ],
            'size' => [
                'cb_kit.block.size.small' => 'sm',
                'cb_kit.block.size.normal' => 'md',
                'cb_kit.block.size.large' => 'lg',
            ],
            'align' => $this->alignChoices(),
        ];
    }

    protected function defaults(): array
    {
        return [
            'items' => [
                ['text' => 'Learn more', 'url' => '', 'variant' => 'primary', 'newTab' => false],
                ['text' => 'Contact us', 'url' => '', 'variant' => 'outline', 'newTab' => false],
            ],
            'size' => 'md',
            'align' => 'start',
            'stackOnMobile' => false,
        ];
    }

    /** The labels side by side, as the row reads. */
    public function previewHint(array $data): ?BlockPreviewHint
    {
        $labels = [];
        foreach (self::previewItems($data) as $item) {
            $text = self::previewString($item, 'text');
            if ($text !== null && trim($text) !== '') {
                $labels[] = trim($text);
            }
        }

        return BlockPreviewHint::button($labels === [] ? null : implode(' · ', $labels));
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/button_group/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
