<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Fixtures;

use ContentBlocks\BlockType\AbstractBlockType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A block with one of each shape the translation layer has to handle: a plain
 * tagged field, a tagged textarea, an untagged enum, and a collection whose
 * entries are partly tagged.
 */
final class TranslatableFixtureBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'fixture';
    }

    public static function getLabel(): string
    {
        return 'Fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('heading', TextType::class, [
                'label' => 'Heading',
                'required' => false,
                'cb_translatable' => true,
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Body',
                'required' => false,
                'cb_translatable' => true,
            ])
            // Same in every language — deliberately untagged.
            ->add('align', ChoiceType::class, [
                'required' => false,
                'choices' => ['start' => 'start', 'center' => 'center'],
            ])
            ->add('items', CollectionType::class, [
                'entry_type' => TranslatableFixtureItemType::class,
                'allow_add' => true,
            ]);
    }

    public function getDefaultData(): array
    {
        return ['heading' => '', 'body' => '', 'align' => 'start', 'items' => []];
    }
}

final class TranslatableFixtureItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Item label',
                'required' => false,
                'cb_translatable' => true,
            ])
            ->add('url', UrlType::class, [
                'label' => 'Item link',
                'required' => false,
                'cb_translatable' => true,
            ])
            // An uploaded asset: not text, deliberately untagged.
            ->add('src', TextType::class, ['required' => false]);
    }
}
