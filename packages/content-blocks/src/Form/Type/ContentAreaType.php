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
 * This renders a hidden field holding the ContentArea ID. The Live Component
 * (ContentAreaBuilder) provides the actual editing UI.
 *
 * Lifecycle:
 * - On a GET request, no DB writes happen. If the parent entity has no
 *   ContentArea yet, the widget renders a "save first" placeholder.
 * - On submit, reverseTransform() persists a new ContentArea (without flush);
 *   the host controller's flush — or the parent's `cascade: ['persist']` —
 *   commits everything together.
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
        $isPersisted = $contentArea instanceof ContentArea && $contentArea->getId() !== null;

        $view->vars['content_area'] = $isPersisted ? $contentArea : null;
        $view->vars['content_area_id'] = $isPersisted ? $contentArea->getId() : null;
        $view->vars['value'] = $isPersisted ? $contentArea->getId() : '';
        $view->vars['is_pending'] = !$isPersisted;
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
            // Queue for write but let the host controller's flush — or the
            // parent entity's `cascade: ['persist']` — actually commit.
            $this->em->persist($contentArea);

            return $contentArea;
        }

        return $this->em->find(ContentArea::class, (int) $value);
    }
}
