<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock]
final class RichTextBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'rich_text';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.rich_text.label', [], 'content_blocks_kit');
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        // The TextareaType is enhanced client-side by the cb-tinymce
        // Stimulus controller (declared in the form theme). When JS is
        // disabled, the user still gets a plain textarea fallback.
        $builder->add('content', TextareaType::class, [
            'label' => 'cb_kit.block.rich_text.field.content',
            'translation_domain' => 'content_blocks_kit',
            'required' => false,
            'attr' => ['rows' => 10],
        ]);
    }

    public function getDefaultData(): array
    {
        return ['content' => ''];
    }

    public function getFormTheme(): ?string
    {
        return '@ContentBlocksKit/form/rich_text_theme.html.twig';
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/rich_text/view.html.twig';
    }
}
