<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Form\Type\ListItemType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * A styled list (bulleted, checkmarks or numbered) of short text items.
 */
#[AsContentBlock(priority: 40)]
class ListBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'list';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.list.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M9 6h11M9 12h11M9 18h11M5 6h.01M5 12h.01M5 18h.01"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('style', ChoiceType::class, [
                'label' => 'cb_kit.block.list.field.style',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('style'),
                'constraints' => [$this->choiceConstraint('style')],
            ])
            ->add('items', LiveCollectionType::class, [
                'label' => 'cb_kit.block.list.field.items',
                'translation_domain' => 'content_blocks_kit',
                'entry_type' => ListItemType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'button_add_options' => [
                    'label' => 'cb_kit.block.action.add_item',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--success'],
                ],
                'button_delete_options' => [
                    'label' => 'cb_kit.block.action.remove_item',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--danger'],
                ],
                'constraints' => [new Assert\Count(min: 1, max: 50)],
            ]);
    }

    protected function choiceFields(): array
    {
        return [
            'style' => [
                'cb_kit.block.list.style.bullet' => 'bullet',
                'cb_kit.block.list.style.check' => 'check',
                'cb_kit.block.list.style.numbered' => 'numbered',
            ],
        ];
    }

    protected function defaults(): array
    {
        return [
            'style' => 'bullet',
            'items' => [
                ['text' => 'List item'],
            ],
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/list/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
