<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionCollection;
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
 * FormType managing a ContentArea: `$builder->add('contentArea', self::class)`
 * renders a hidden id field, and the builder supplies the editing UI.
 *
 * @see docs/guide/concepts.md#contentareatype-lifecycle for the GET/submit
 *      contract — no DB write on GET, transient area on submit
 *
 * @implements DataTransformerInterface<mixed, mixed>
 */
final class ContentAreaType extends AbstractType implements DataTransformerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?BuilderActionCollection $builderActions = null,
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
            // UI-only, both of these: the endpoints stay reachable and
            // AccessChecker-protected whatever the topbar shows.
            'enable_replace' => true,
            'enable_import_export' => true,
            // Entries of ['key', 'label', 'icon'?, 'title'?]; clicking one
            // dispatches `cb:builder:action` carrying detail.key.
            'topbar_actions' => [],
        ]);
        $resolver->setAllowedTypes('enable_replace', 'bool');
        $resolver->setAllowedTypes('enable_import_export', 'bool');
        $resolver->setAllowedTypes('topbar_actions', 'array');
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
        $view->vars['enable_replace'] = $options['enable_replace'];
        $view->vars['enable_import_export'] = $options['enable_import_export'];
        // Providers only speak about an area that exists, and the "save first"
        // placeholder has no builder to hang a menu off anyway.
        $view->vars['topbar_actions'] = $isPersisted && $this->builderActions !== null
            ? $this->builderActions->forArea($contentArea, $options['topbar_actions'])
            : array_map(
                static fn (mixed $a) => $a instanceof BuilderAction ? $a : BuilderAction::fromArray($a),
                array_values($options['topbar_actions']),
            );
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
