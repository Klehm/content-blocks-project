<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A colored callout (info / success / warning / error) with an optional title,
 * a message and a type-matched glyph (shipped inline — no icon library).
 */
#[AsContentBlock(priority: 35)]
final class AlertBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'alert';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.alert.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M10.3 3.6 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.6a2 2 0 0 0-3.4 0z"/>'
            . '<path d="M12 9v4M12 17h.01"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'cb_kit.block.alert.field.type',
                'translation_domain' => 'content_blocks_kit',
                'choices' => [
                    'cb_kit.block.alert.type.info' => 'info',
                    'cb_kit.block.alert.type.success' => 'success',
                    'cb_kit.block.alert.type.warning' => 'warning',
                    'cb_kit.block.alert.type.error' => 'error',
                ],
                'constraints' => [new Assert\Choice(choices: ['info', 'success', 'warning', 'error'])],
            ])
            ->add('title', TextType::class, [
                'label' => 'cb_kit.block.alert.field.title',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'cb_kit.block.alert.field.message',
                'translation_domain' => 'content_blocks_kit',
                'attr' => ['rows' => 3],
                'constraints' => [new Assert\NotBlank()],
            ]);
    }

    public function getDefaultData(): array
    {
        return [
            'type' => 'info',
            'title' => '',
            'message' => '',
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/alert/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
