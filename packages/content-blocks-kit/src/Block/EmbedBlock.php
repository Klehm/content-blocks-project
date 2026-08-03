<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Responsive video embed (YouTube / Vimeo). The stored URL is normalized to an
 * embeddable player URL at render time by `cb_embed_url()`. iframes need a full
 * (re)load to init, so this block does not opt into preview hot reload.
 */
#[AsContentBlock(priority: 30)]
class EmbedBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'embed';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.embed.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m10 9 5 3-5 3z"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('url', UrlType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.embed.field.url',
                'translation_domain' => 'content_blocks_kit',
                'help' => 'cb_kit.block.embed.url_help',
                'default_protocol' => 'https',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 1024)],
            ])
            ->add('title', TextType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.embed.field.title',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ]);
    }

    protected function defaults(): array
    {
        return ['url' => '', 'title' => ''];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/embed/view.html.twig';
    }

    // Self-contained <iframe> markup: the third-party player boots inside the
    // frame, not on our page, so the swapped-in view needs no init pass — hot
    // reload is safe and spares a full preview-iframe reload on every edit.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
