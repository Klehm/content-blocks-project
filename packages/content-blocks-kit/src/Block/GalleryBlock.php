<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Form\Type\Styling\BoxSpacingType;
use ContentBlocks\Kit\Form\Type\GalleryItemType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * A gallery of images shown as a responsive grid (N columns) or a one-row
 * horizontal slider (arrows via the cb-gallery controller). Each image has an
 * optional caption and click-through link; a common object-fit and per-corner
 * radius apply to all tiles.
 *
 * Option `max_columns` (config) caps the column choices offered.
 */
#[AsContentBlock(priority: 60)]
final class GalleryBlock extends AbstractKitBlock
{
    public static function defaultOptions(): array
    {
        return ['max_columns' => 6];
    }

    public static function getType(): string
    {
        return 'gallery';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.gallery.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>'
            . '<rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('layout', ChoiceType::class, [
                'label' => 'cb_kit.block.gallery.field.layout',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('layout'),
                'constraints' => [$this->choiceConstraint('layout')],
            ])
            ->add('columns', ChoiceType::class, [
                'label' => 'cb_kit.block.gallery.field.columns',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('columns'),
                'constraints' => [$this->choiceConstraint('columns')],
            ])
            ->add('fit', ChoiceType::class, [
                'label' => 'cb_kit.block.image.field.fit',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('fit'),
                'constraints' => [$this->choiceConstraint('fit')],
            ])
            ->add('borderRadius', BoxSpacingType::class, [
                'label' => 'cb_kit.block.image.field.border_radius',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ])
            ->add('items', LiveCollectionType::class, [
                'label' => 'cb_kit.block.gallery.field.items',
                'translation_domain' => 'content_blocks_kit',
                'entry_type' => GalleryItemType::class,
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
                'constraints' => [new Assert\Count(min: 1, max: 60)],
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
                'cb_kit.block.gallery.layout.grid' => 'grid',
                'cb_kit.block.gallery.layout.slider' => 'slider',
            ],
            'columns' => $columnChoices,
            'fit' => [
                'cb_kit.block.image.fit.cover' => 'cover',
                'cb_kit.block.image.fit.contain' => 'contain',
            ],
        ];
    }

    protected function defaults(): array
    {
        return [
            'layout' => 'grid',
            'columns' => 3,
            'fit' => 'cover',
            'borderRadius' => ['linked' => true],
            'items' => [
                ['src' => '', 'alt' => '', 'caption' => '', 'link' => ''],
            ],
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/gallery/view.html.twig';
    }

    // The grid is CSS-only; the slider's arrows are (re)wired idempotently by
    // the cb-gallery controller on connect, so hot reload is safe.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
