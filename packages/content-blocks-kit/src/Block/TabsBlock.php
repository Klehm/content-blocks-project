<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Form\Type\TabEntryType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

#[AsContentBlock(priority: 60)]
final class TabsBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'tabs';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.tabs.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        // Tabbed panel.
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" '
            . 'stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/>'
            . '<path d="M3 11h18"/><path d="M7 7V5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('tabs', LiveCollectionType::class, [
            'entry_type' => TabEntryType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'button_add_options' => [
                'label' => 'cb_kit.block.tabs.add_tab',
                'translation_domain' => 'content_blocks_kit',
                'attr' => ['class' => 'cb-form-btn--success'],
            ],
            'button_delete_options' => [
                'label' => 'cb_kit.block.tabs.remove_tab',
                'translation_domain' => 'content_blocks_kit',
                'attr' => ['class' => 'cb-form-btn--danger'],
            ],
            'constraints' => [
                new Assert\Count(min: 1, max: 20),
            ],
        ]);
    }

    protected function defaults(): array
    {
        return [
            'tabs' => [
                ['title' => 'Tab 1', 'content' => ''],
            ],
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/tabs/view.html.twig';
    }

    // CSS-only tabs (radio + sibling selectors), no JS — safe to hot-reload.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
