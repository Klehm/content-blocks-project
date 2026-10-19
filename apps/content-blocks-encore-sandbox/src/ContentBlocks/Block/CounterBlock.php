<?php

declare(strict_types=1);

namespace App\ContentBlocks\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockAssets;
use ContentBlocks\BlockType\BlockAssetsInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Encore sandbox's demo of BlockAssetsInterface: the counter's JS and CSS
 * are an Encore entry (webpack.config.js), linked with its runtime and shared
 * chunk only where a counter renders. Pinned by e2e-encore/block-assets.spec.js
 */
#[AsContentBlock(priority: 40)]
final class CounterBlock extends AbstractBlockType implements BlockAssetsInterface
{
    public function getType(): string
    {
        return 'counter';
    }

    public function getLabel(): string
    {
        return 'Compteur';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('value', IntegerType::class, [
                'label' => 'Valeur',
                'data' => $data['value'] ?? 0,
                'constraints' => [new Assert\Range(min: 0, max: 1_000_000_000)],
            ])
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'required' => false,
                'data' => $data['label'] ?? '',
            ])
            ->add('animate', CheckboxType::class, [
                'label' => 'Animer à l\'affichage',
                'required' => false,
                'data' => $data['animate'] ?? true,
            ]);
    }

    public function getDefaultData(): array
    {
        return ['value' => 0, 'label' => '', 'animate' => true];
    }

    public function getViewTemplate(): ?string
    {
        return 'block/counter.html.twig';
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }

    public function assets(array $data): BlockAssets
    {
        // An Encore entry: its runtime, shared chunk and CSS come with it.
        return ($data['animate'] ?? true)
            ? new BlockAssets(entries: ['blocks/counter'])
            : new BlockAssets(stylesheets: ['build/blocks/counter.css']);
    }
}
