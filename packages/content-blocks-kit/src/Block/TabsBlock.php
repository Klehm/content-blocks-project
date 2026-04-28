<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Form\Type\TabEntryType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

#[AsContentBlock]
final class TabsBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'tabs';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.tabs.label', [], 'content_blocks_kit');
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
                'attr' => ['class' => 'btn btn-sm btn-outline-success'],
            ],
            'button_delete_options' => [
                'label' => 'cb_kit.block.tabs.remove_tab',
                'translation_domain' => 'content_blocks_kit',
                'attr' => ['class' => 'btn btn-sm btn-outline-danger'],
            ],
            'constraints' => [
                new Assert\Count(min: 1, max: 20),
            ],
        ]);
    }

    public function getDefaultData(): array
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
}
