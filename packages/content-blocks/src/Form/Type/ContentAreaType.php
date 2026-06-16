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
            // Whether the builder topbar shows the "Insert content" (replace)
            // button and its overlay. UI-only: the replace endpoints stay
            // reachable (and AccessChecker-protected) regardless. Defaults to
            // true so existing integrations keep the button.
            'enable_replace' => true,
            // Whether the builder topbar shows the Import/Export button and its
            // overlay. UI-only as well: the export/import endpoints stay
            // reachable (AccessChecker + CSRF protected). The host wires its own
            // strategy (per-form here, or a firewall/AccessChecker server-side).
            'enable_import_export' => true,
            // Host-provided extra topbar buttons. Each entry is an associative
            // array: ['key' => 'save-as-model', 'label' => 'Save as model',
            // 'icon' => '💾' (optional, may be inline SVG), 'title' => '…'
            // (optional, defaults to label)]. Clicking a button dispatches a
            // single generic `cb:builder:action` event carrying detail.key — the
            // host listens once and filters on the key. Labels/icons are the
            // host's responsibility (already translated, trusted markup).
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
        $view->vars['topbar_actions'] = $options['topbar_actions'];
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
