<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Palette\ColorPaletteRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Palette-aware color field: a dropdown of the project palette (declared via
 * bundle config or {@see \ContentBlocks\Palette\ColorPaletteProviderInterface})
 * plus a "Custom…" option that reveals a free color picker.
 *
 * It stores a single `#hex` string ('' for none), so it is a drop-in
 * replacement for Symfony's plain {@see ColorType} — decorators and view
 * templates keep reading a `#hex` unchanged. Crucially, unlike a raw
 * `<input type="color">` (which always carries a value), this type has a
 * real empty state ("None"), which is what lets the styling defaults be
 * transparent instead of the historical `#ffffff` hack.
 *
 * With an empty palette the dropdown still renders None / Custom…, i.e. the
 * type degrades to "a ColorType with an off switch".
 *
 * Show/hide of the custom picker is driven by the generic `cb-condition`
 * Stimulus controller attached to the compound root — no custom form-theme
 * block is needed, so the type renders correctly in the section sidebar and
 * the block edit form alike.
 */
final class PaletteColorType extends AbstractType implements DataMapperInterface
{
    private const CUSTOM = 'custom';

    public function __construct(
        private readonly ?ColorPaletteRegistry $palette = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = ['cb.styling.palette.none' => ''];
        $paletteChoices = $this->palette?->getChoices() ?? [];
        $choices = array_merge($choices, $paletteChoices);
        if ($options['allow_custom']) {
            $choices['cb.styling.palette.custom'] = self::CUSTOM;
        }

        $builder
            ->add('palette', ChoiceType::class, [
                'required' => false,
                'label' => false,
                'placeholder' => false,
                'choices' => $choices,
                // The None / Custom… option labels are core translation keys
                // (cb.styling.palette.*), so they must resolve in the core
                // `content_blocks` domain — NOT the field's translation_domain,
                // which a host/kit block may set to its own catalog (e.g. a kit
                // block passes 'content_blocks_kit', where those keys don't
                // exist → they'd render as raw keys). Palette entry labels are
                // literal strings, so trans() passes them through untouched.
                // (A child form doesn't inherit translation_domain when
                // ChoiceType resolves choice_translation_domain — left to its
                // null default the labels fall back to the `messages` domain.)
                'choice_translation_domain' => 'content_blocks',
                // Expose each palette hex on its <option> so themes can
                // paint a swatch; empty for None / Custom.
                'choice_attr' => static fn (string $value): array => str_starts_with($value, '#')
                    ? ['data-color' => $value]
                    : [],
            ])
            ->add(self::CUSTOM, ColorType::class, [
                'required' => false,
                'label' => false,
                'row_attr' => ['data-cb-condition' => 'palette:' . self::CUSTOM],
            ])
            ->setDataMapper($this)
            // Without a view transformer Form::viewToNorm() collapses '' to
            // null; pin the empty state to '' so consumers always deal with
            // a string ('' = no color), never null.
            ->addViewTransformer(new CallbackTransformer(
                static fn (mixed $value): mixed => $value,
                static fn (mixed $value): string => \is_string($value) ? $value : '',
            ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            // When every child is empty Symfony bypasses the data mapper and
            // uses empty_data — pin it to '' so the stored empty state is
            // always the empty string, never null.
            'empty_data' => '',
            'attr' => ['data-controller' => 'cb-condition'],
            'translation_domain' => 'content_blocks',
            // Set to false to lock editors to the palette (no free picker).
            'allow_custom' => true,
        ]);
        $resolver->setAllowedTypes('allow_custom', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'cb_palette_color';
    }

    /**
     * Stored hex string -> {palette, custom} children.
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $forms = iterator_to_array($forms);
        $hex = \is_string($viewData) ? trim($viewData) : '';

        if ($hex === '') {
            $forms['palette']->setData('');
            $forms[self::CUSTOM]->setData(null);

            return;
        }

        // Palette membership is case-insensitive, but the choice value must
        // be the canonical hex as declared, or the <option> won't select.
        $index = array_search(mb_strtolower($hex), $this->palette?->getHexes() ?? [], true);
        if ($index !== false) {
            $forms['palette']->setData(($this->palette?->all() ?? [])[$index]->color);
            $forms[self::CUSTOM]->setData(null);

            return;
        }

        $forms['palette']->setData(self::CUSTOM);
        $forms[self::CUSTOM]->setData($hex);
    }

    /**
     * {palette, custom} children -> stored hex string.
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $forms = iterator_to_array($forms);
        $palette = $forms['palette']->getData();
        $custom = $forms[self::CUSTOM]->getData();

        if ($palette === self::CUSTOM) {
            $viewData = \is_string($custom) && $custom !== '' ? $custom : '';
        } elseif (\is_string($palette) && $palette !== '') {
            $viewData = $palette;
        } else {
            $viewData = '';
        }
    }
}
