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
 * A textarea mounted by whichever WYSIWYG editor the host selected.
 *
 * The block hands over its resolved options and nothing else; picking the
 * adapter out of the registry and asking it what the browser needs happens
 * here, at view-building time, because "how is this field rendered" is a view
 * concern — and because it keeps `RichTextBlock` constructible with no
 * arguments, the way every other kit block is.
 *
 * The rendered markup lives in `@ContentBlocksKit/form/rich_text_theme.html.twig`
 * (`cb_rich_text_widget`), the same split the core uses for
 * `ImageUploadType`/`cb_image_upload_widget`.
 *
 * The textarea itself stays in the DOM under every editor — it is what the
 * Live Component binds to, and what a failed editor load falls back to.
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
