<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Form\Type\BreadcrumbItemType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * A breadcrumb trail: an ordered list of {label, url} steps. A step with an
 * empty url renders as plain text (the current page).
 */
#[AsContentBlock(priority: 20)]
class BreadcrumbBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'breadcrumb';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.breadcrumb.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M5 12h.01M12 12h.01M19 12h.01M8 9l3 3-3 3M15 9l3 3-3 3"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('items', LiveCollectionType::class, [
            'label' => 'cb_kit.block.breadcrumb.field.items',
            'translation_domain' => 'content_blocks_kit',
            'entry_type' => BreadcrumbItemType::class,
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
            'constraints' => [new Assert\Count(min: 1, max: 12)],
        ]);
    }

    protected function defaults(): array
    {
        return [
            'items' => [
                ['label' => 'Home', 'url' => '/'],
                ['label' => 'Current page', 'url' => ''],
            ],
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/breadcrumb/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
