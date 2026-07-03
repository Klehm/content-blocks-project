<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Form\Type\TableColumnType;
use ContentBlocks\Kit\Form\Type\TableRowType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * A simple data table: columns (label + alignment) and rows of cells. Cells are
 * mapped to columns by position at render time, so the table stays rectangular
 * even if a row has fewer/more cells than columns.
 */
#[AsContentBlock(priority: 15)]
final class TableBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'table';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.table.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M10 4v16M15 4v16"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('striped', CheckboxType::class, [
                'label' => 'cb_kit.block.table.field.striped',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ])
            ->add('columns', LiveCollectionType::class, [
                'label' => 'cb_kit.block.table.field.columns',
                'translation_domain' => 'content_blocks_kit',
                'entry_type' => TableColumnType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'button_add_options' => [
                    'label' => 'cb_kit.block.table.action.add_column',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--success'],
                ],
                'button_delete_options' => [
                    'label' => 'cb_kit.block.action.remove_item',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--danger'],
                ],
                'constraints' => [new Assert\Count(min: 1, max: 12)],
            ])
            ->add('rows', LiveCollectionType::class, [
                'label' => 'cb_kit.block.table.field.rows',
                'translation_domain' => 'content_blocks_kit',
                'entry_type' => TableRowType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'button_add_options' => [
                    'label' => 'cb_kit.block.table.action.add_row',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--success'],
                ],
                'button_delete_options' => [
                    'label' => 'cb_kit.block.action.remove_item',
                    'translation_domain' => 'content_blocks_kit',
                    'attr' => ['class' => 'cb-form-btn--danger'],
                ],
                'constraints' => [new Assert\Count(min: 1, max: 200)],
            ]);
    }

    public function getDefaultData(): array
    {
        return [
            'striped' => true,
            'columns' => [
                ['label' => 'Name', 'align' => 'left'],
                ['label' => 'Value', 'align' => 'right'],
            ],
            'rows' => [
                ['cells' => [['content' => 'Row 1'], ['content' => '—']]],
            ],
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/table/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
