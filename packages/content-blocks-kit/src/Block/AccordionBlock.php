<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Form\Type\AccordionItemType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * A collapsible list of title/content panels — great for FAQs. Rendered with
 * native <details>/<summary>, so it needs zero JavaScript and stays accessible.
 */
#[AsContentBlock(priority: 50)]
final class AccordionBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'accordion';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.accordion.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 9h16M10 15l2 2 2-2"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('exclusive', CheckboxType::class, [
                'label' => 'cb_kit.block.accordion.field.exclusive',
                'translation_domain' => 'content_blocks_kit',
                'help' => 'cb_kit.block.accordion.exclusive_help',
                'required' => false,
            ])
            ->add('items', LiveCollectionType::class, [
                'label' => 'cb_kit.block.accordion.field.items',
                'translation_domain' => 'content_blocks_kit',
                'entry_type' => AccordionItemType::class,
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
                'constraints' => [new Assert\Count(min: 1, max: 30)],
            ]);
    }

    public function getDefaultData(): array
    {
        return [
            'exclusive' => false,
            'items' => [
                ['title' => 'Question', 'content' => 'Answer'],
            ],
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/accordion/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
