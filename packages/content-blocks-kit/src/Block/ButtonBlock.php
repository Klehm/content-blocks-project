<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Standalone call-to-action button: label + link + a style/size/alignment
 * choice, optional full-width and new-tab.
 */
#[AsContentBlock(priority: 55)]
final class ButtonBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'button';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.button.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="8" width="18" height="8" rx="4"/><path d="M12 8v8"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('text', TextType::class, [
                'label' => 'cb_kit.block.button.field.text',
                'translation_domain' => 'content_blocks_kit',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('href', UrlType::class, [
                'label' => 'cb_kit.block.button.field.href',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'default_protocol' => null,
                'constraints' => [new Assert\Length(max: 1024)],
            ])
            ->add('variant', ChoiceType::class, [
                'label' => 'cb_kit.block.button.field.variant',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('variant'),
                'constraints' => [$this->choiceConstraint('variant')],
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
            ->add('fullWidth', CheckboxType::class, [
                'label' => 'cb_kit.block.button.field.full_width',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ])
            ->add('newTab', CheckboxType::class, [
                'label' => 'cb_kit.block.button.field.new_tab',
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
            'text' => 'Learn more',
            'href' => '',
            'variant' => 'primary',
            'size' => 'md',
            'align' => 'start',
            'fullWidth' => false,
            'newTab' => false,
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/button/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
