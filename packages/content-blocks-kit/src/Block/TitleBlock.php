<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock]
final class TitleBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'title';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.title.label', [], 'content_blocks_kit');
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('text', TextType::class, [
                'label' => 'cb_kit.block.title.field.text',
                'translation_domain' => 'content_blocks_kit',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('tag', ChoiceType::class, [
                'label' => 'cb_kit.block.title.field.tag',
                'translation_domain' => 'content_blocks_kit',
                'choices' => [
                    'H1' => 'h1',
                    'H2' => 'h2',
                    'H3' => 'h3',
                    'H4' => 'h4',
                    'H5' => 'h5',
                    'H6' => 'h6',
                    'span' => 'span',
                    'p' => 'p',
                ],
                'constraints' => [new Assert\Choice(choices: ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'p'])],
            ]);
    }

    public function getDefaultData(): array
    {
        return [
            'text' => '',
            'tag' => 'h2',
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/title/view.html.twig';
    }
}
