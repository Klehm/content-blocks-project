<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A textarea mounted by whichever editor the host selected, resolved here at
 * view-building time so `RichTextBlock` stays constructible with no arguments.
 *
 * @see docs/internals/kit.md#rich-text-one-payload-several-editors
 */
final class RichTextEditorType extends AbstractType
{
    public function __construct(
        private readonly RichTextEditorRegistry $editors,
    ) {
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $editorOptions = $options['editor_options'];
        $name = (string) ($editorOptions['editor'] ?? 'tinymce');

        $view->vars['cb_editor'] = $this->editors->get($name)->buildView($editorOptions);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'attr' => ['rows' => 10],
            // The `rich_text` block's resolved option set: which editor, how
            // it is loaded, whether uploads are wired, host init overrides.
            'editor_options' => [],
        ]);
        $resolver->setAllowedTypes('editor_options', 'array');
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cb_rich_text';
    }
}
