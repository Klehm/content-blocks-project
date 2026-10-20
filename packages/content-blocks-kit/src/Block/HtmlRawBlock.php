<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockPurposeInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Raw HTML escape hatch — renders the stored markup verbatim. Authored by
 * trusted editors (all mutations pass the host's canEdit() gate).
 */
#[AsContentBlock(priority: 5, category: 'advanced')]
class HtmlRawBlock extends AbstractKitBlock implements BlockPurposeInterface
{
    public function getPurpose(): string
    {
        return 'Raw HTML inserted as is, for markup no other block can produce. Avoid it for ordinary content.';
    }

    public function getType(): string
    {
        return 'html_raw';
    }

    public function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.html_raw.label', [], 'content_blocks_kit');
    }

    public function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="m7 8-4 4 4 4M17 8l4 4-4 4M14 4l-4 16"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('html', TextareaType::class, [
            'cb_translatable' => true,
            'label' => 'cb_kit.block.html_raw.field.html',
            'translation_domain' => 'content_blocks_kit',
            'required' => false,
            // `data-cb-ai`: markup that runs as is is not a model's to rewrite.
            'attr' => ['rows' => 10, 'spellcheck' => 'false', 'style' => 'font-family: monospace;', 'data-cb-ai' => 'off'],
            'help' => 'cb_kit.block.html_raw.help',
            'help_translation_parameters' => [],
        ]);
    }

    protected function defaults(): array
    {
        return ['html' => ''];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/html_raw/view.html.twig';
    }
}
