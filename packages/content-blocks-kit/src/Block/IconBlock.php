<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Kit\Icon\IconRegistry;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A single icon from the resolved {@see IconRegistry}, with a size, an optional
 * palette color and alignment. No external icon library needed.
 */
#[AsContentBlock(priority: 45)]
class IconBlock extends AbstractKitBlock
{
    /**
     * @param array<string, mixed>        $options
     * @param array<string, list<string>> $choiceOverrides
     * @param array<string, mixed>        $defaultOverrides
     */
    public function __construct(
        array $options = [],
        array $choiceOverrides = [],
        array $defaultOverrides = [],
        private readonly IconRegistry $icons = new IconRegistry(),
    ) {
        parent::__construct($options, $choiceOverrides, $defaultOverrides);
    }

    public static function getType(): string
    {
        return 'icon';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.icon.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('name', ChoiceType::class, [
                'label' => 'cb_kit.block.icon.field.name',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('name'),
                'choice_translation_domain' => false,
                'constraints' => [$this->choiceConstraint('name')],
            ])
            ->add('color', PaletteColorType::class, [
                'label' => 'cb_kit.block.icon.field.color',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ])
            ->add('size', RangeType::class, [
                'label' => 'cb_kit.block.icon.field.size',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'empty_data' => '48',
                'attr' => ['min' => 16, 'max' => 128, 'step' => 4],
            ])
            ->add('align', ChoiceType::class, [
                'label' => 'cb_kit.block.field.align',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('align'),
                'constraints' => [$this->choiceConstraint('align')],
            ]);
    }

    protected function choiceFields(): array
    {
        return [
            // Labels are the icon names themselves (choice_translation_domain
            // is false on the field). The set comes from the registry, so a
            // host's IconProviderInterface shows up here with no config at
            // all; `choices` then restricts or reorders what it produced.
            'name' => $this->icons->choices(),
            'align' => $this->alignChoices(),
        ];
    }

    protected function defaults(): array
    {
        return [
            'name' => 'star',
            'color' => '',
            'size' => 48,
            'align' => 'center',
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/icon/view.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
