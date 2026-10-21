<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Form\Type;

use ContentBlocks\Sylius\Entity\ContentPageTranslation;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Title, slug and metadata of a page in one locale. */
final class ContentPageTranslationType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'sylius.ui.title'])
            ->add('slug', TextType::class, [
                'label' => 'sylius.ui.slug',
                'required' => false,
                'help' => 'content_blocks_sylius.ui.slug_help',
            ])
            ->add('metaTitle', TextType::class, [
                'label' => 'content_blocks_sylius.ui.meta_title',
                'required' => false,
            ])
            ->add('metaDescription', TextareaType::class, [
                'label' => 'sylius.ui.meta_description',
                'required' => false,
            ])
            ->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
                $translation = $event->getData();
                if (
                    $translation instanceof ContentPageTranslation
                    && trim((string) $translation->getSlug()) === ''
                    && trim((string) $translation->getTitle()) !== ''
                ) {
                    $slug = (new AsciiSlugger())->slug((string) $translation->getTitle());
                    $translation->setSlug(strtolower($slug->toString()));
                }
            });
    }

    public function getBlockPrefix(): string
    {
        return 'content_blocks_sylius_content_page_translation';
    }
}
