<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Schema\FormSchemaMapper;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Marks the fields an AI action can fill, so the sidebar draws its button:
 * prose (`cb_translatable` text), HTML, and the `alt` beside an image.
 *
 * @see docs/guide/ai.md#which-fields-get-a-button
 */
final class AiFieldTypeExtension extends AbstractTypeExtension
{
    public const OPTION = 'cb_ai';
    public const OFF_ATTR = 'data-cb-ai';

    public const TEXT = 'text';
    public const HTML = 'html';
    public const ALT = 'alt';

    public function __construct(
        private readonly ?AiFeatures $features = null,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // null: decided from the field; false: never.
        $resolver->setDefault(self::OPTION, null);
        $resolver->setAllowedValues(self::OPTION, [null, false, self::TEXT, self::HTML, self::ALT]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // The opt-out a type can set without the option being defined.
        if (($options['attr'][self::OFF_ATTR] ?? null) === 'off') {
            return;
        }

        $kind = $options[self::OPTION] ?? $this->inferred($form, $options);
        if (!\is_string($kind) || !$this->offered($kind)) {
            return;
        }

        $image = null;
        if ($kind === self::ALT) {
            $image = $this->imageSibling($form);
            if ($image === null || $view->parent === null) {
                return;
            }
            $view->vars['cb_ai_image'] = $view->parent->vars['id'] . '_' . $image;
        }

        $view->vars['cb_ai'] = $kind;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function inferred(FormInterface $form, array $options): ?string
    {
        if ($form->getName() === 'alt' && $this->imageSibling($form) !== null) {
            return self::ALT;
        }
        // Tagged translatable means prose an editor writes, not a URL or a
        // CSS class: the fields worth rewriting.
        if (($options[TranslatableFieldTypeExtension::OPTION] ?? false) !== true) {
            return null;
        }

        // A link may be tagged too (a localized URL), but it is not prose.
        if (FormSchemaMapper::is($form, UrlType::class) || FormSchemaMapper::is($form, EmailType::class)) {
            return null;
        }

        return FormSchemaMapper::is($form, TextType::class) || FormSchemaMapper::is($form, TextareaType::class)
            ? self::TEXT
            : null;
    }

    private function offered(string $kind): bool
    {
        if ($this->features === null) {
            return false;
        }
        if ($kind === self::ALT) {
            return $this->features->isEnabled(AiAction::ALT_TEXT);
        }

        foreach (AiAction::TEXT as $action) {
            if ($this->features->isEnabled($action)) {
                return true;
            }
        }

        return false;
    }

    /** The image field beside this one, by name. */
    private function imageSibling(FormInterface $form): ?string
    {
        foreach ($form->getParent() ?? [] as $name => $sibling) {
            if ($sibling !== $form && FormSchemaMapper::is($sibling, ImageUploadType::class)) {
                return (string) $name;
            }
        }

        return null;
    }
}
