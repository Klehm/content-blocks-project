<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Symfony FormType that manages a ContentArea entity.
 *
 * Usage in any form:
 *     $builder->add('contentArea', ContentAreaType::class);
 *
 * This renders a hidden field holding the ContentArea ID.
 * The Live Component (ContentAreaBuilder) provides the actual editing UI.
 * A new ContentArea is created automatically if none exists yet.
 */
final class ContentAreaType extends AbstractType implements DataTransformerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => false,
            'data_class' => null,
        ]);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'content_area';
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $contentArea = $form->getData();

        if (!$contentArea instanceof ContentArea || $contentArea->getId() === null) {
            $contentArea = new ContentArea();
            $this->em->persist($contentArea);
            $this->em->flush();
            $form->setData($contentArea);
        }

        $view->vars['content_area'] = $contentArea;
        $view->vars['content_area_id'] = $contentArea->getId();
        $view->vars['value'] = $contentArea->getId();
    }

    /** @param ContentArea|null $value */
    public function transform(mixed $value): mixed
    {
        if ($value instanceof ContentArea) {
            return $value->getId();
        }

        return null;
    }

    /** @param int|string|null $value */
    public function reverseTransform(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            $contentArea = new ContentArea();
            $this->em->persist($contentArea);
            $this->em->flush();

            return $contentArea;
        }

        return $this->em->find(ContentArea::class, (int) $value);
    }
}
