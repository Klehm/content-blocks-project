<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Form\Type;

use ContentBlocks\Form\Type\ContentAreaType;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentPageTranslation;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\EventSubscriber\AddCodeFormSubscriber;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Bundle\ResourceBundle\Form\Type\ResourceTranslationsType;
use Sylius\Resource\Translation\Provider\TranslationLocaleProviderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Valid;

/** The admin form of a {@see ContentPage}. */
final class ContentPageType extends AbstractResourceType
{
    /** @param list<string> $validationGroups */
    public function __construct(
        string $dataClass,
        array $validationGroups,
        private readonly TranslationLocaleProviderInterface $locales,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->addEventSubscriber(new AddCodeFormSubscriber(null, ['label' => 'sylius.ui.code']))
            ->add('enabled', CheckboxType::class, [
                'label' => 'sylius.ui.enabled',
                'required' => false,
            ])
            ->add('channels', ChannelChoiceType::class, [
                'label' => 'sylius.ui.channels',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('translations', ResourceTranslationsType::class, [
                'label' => false,
                'entry_type' => ContentPageTranslationType::class,
                'constraints' => [new Valid()],
            ])
            ->add('contentArea', ContentAreaType::class, ['label' => false])
            // Every admin locale gets a tab; one left blank is not a page.
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
                $page = $event->getData();
                if (!$page instanceof ContentPage) {
                    return;
                }
                foreach ($page->getTranslations() as $translation) {
                    if (
                        $translation instanceof ContentPageTranslation
                        && $translation->getLocale() !== $this->locales->getDefaultLocaleCode()
                        && self::isBlank($translation)
                    ) {
                        $page->removeTranslation($translation);
                    }
                }
            });
    }

    public function getBlockPrefix(): string
    {
        return 'content_blocks_sylius_content_page';
    }

    private static function isBlank(ContentPageTranslation $translation): bool
    {
        foreach ([
            $translation->getTitle(),
            $translation->getSlug(),
            $translation->getMetaTitle(),
            $translation->getMetaDescription(),
        ] as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
