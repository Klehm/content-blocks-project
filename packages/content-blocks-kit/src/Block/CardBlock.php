<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Form\Type\CardItemType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * A set of cards (image, title, text, optional button) laid out as a grid of N
 * columns or a stacked list. Great for feature/service tiles.
 *
 * The column-count field reveals only for the grid layout (cb-condition).
 */
#[AsContentBlock(priority: 65)]
class CardBlock extends AbstractKitBlock
{
    public static function defaultOptions(): array
    {
        return ['max_columns' => 4];
    }

    public static function getType(): string
    {
        return 'card';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.card.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="4" width="8" height="16" rx="1"/><rect x="13" y="4" width="8" height="16" rx="1"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('layout', ChoiceType::class, [
                'label' => 'cb_kit.block.card.field.layout',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('layout'),
                // The columns row (below) reveals only for the grid layout,
                // gated by the block edit form's cb-condition scope.
                'constraints' => [$this->choiceConstraint('layout')],
            ])
            ->add('columns', ChoiceType::class, [
                'label' => 'cb_kit.block.card.field.columns',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('columns'),
                'row_attr' => ['data-cb-condition' => 'layout:grid'],
                'constraints' => [$this->choiceConstraint('columns')],
            ])
            ->add('items', LiveCollectionType::class, [
                'label' => 'cb_kit.block.card.field.items',
                'translation_domain' => 'content_blocks_kit',
                'entry_type' => CardItemType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'button_add_options' => [
                    'label' => 'cb_kit.block.action.add_item',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--primary'],
                ],
                'button_delete_options' => [
                    'label' => 'cb_kit.block.action.remove_item',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--danger'],
                ],
                'constraints' => [new Assert\Count(min: 1, max: 30)],
            ]);
    }

    protected function choiceFields(): array
    {
        // `columns` is capped by the `max_columns` option, so it is computed
        // rather than a static map — hence choiceFields() is instance-level.
        $columnChoices = [];
        foreach (range(2, max(2, (int) $this->option('max_columns'))) as $n) {
            $columnChoices[(string) $n] = $n;
        }

        return [
            'layout' => [
                'cb_kit.block.card.layout.grid' => 'grid',
                'cb_kit.block.card.layout.list' => 'list',
            ],
            'columns' => $columnChoices,
        ];
    }

    protected function defaults(): array
    {
        return [
            'layout' => 'grid',
            'columns' => 3,
            'items' => [
                ['src' => '', 'title' => 'Card title', 'content' => 'Card text', 'url' => '', 'buttonText' => ''],
            ],
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/card/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
