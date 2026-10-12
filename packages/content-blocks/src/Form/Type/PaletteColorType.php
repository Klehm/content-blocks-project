<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\CssColor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Palette dropdown plus a "Custom…" picker, storing one CSS colour ('' for
 * none). A drop-in {@see ColorType} replacement with a real empty state.
 *
 * @see docs/internals/forms.md#palettecolortype-and-its-empty-state
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

        $swatch = fn (string $value): ?string => $this->palette?->swatch($value) ?? CssColor::swatch($value);
        $palette = static fn (array $choices): array => [
            'required' => false,
            'label' => false,
            'placeholder' => false,
            'choices' => $choices,
            // One swatch per colour, themed by `cb_palette_swatches_widget`.
            'expanded' => true,
            'block_prefix' => 'cb_palette_swatches',
            // Pinned to the core domain, not the field's: a kit block
            // points elsewhere and these keys would render raw.
            'choice_translation_domain' => 'content_blocks',
            // Expose each colour's hex on its <option> so themes can paint
            // a swatch; empty for None / Custom.
            'choice_attr' => static fn (string $value): array => ($hex = $swatch($value)) !== null
                ? ['data-color' => $hex]
                : [],
        ];

        $builder
            ->add('palette', ChoiceType::class, $palette($choices))
            ->add(self::CUSTOM, ColorType::class, [
                'required' => false,
                // Enforces #rrggbb: the value lands in a style attribute.
                'html5' => true,
                'label' => false,
                'row_attr' => ['data-cb-condition' => 'palette:' . self::CUSTOM],
            ])
            ->setDataMapper($this)
            // A stored value the custom picker cannot show (a theme variable
            // since dropped from the palette) stays on offer as itself.
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($palette, $choices): void {
                $kept = $this->keptAsIs($event->getData());
                if ($kept !== null) {
                    $withKept = array_merge(
                        array_diff($choices, [self::CUSTOM]),
                        [$kept => $kept],
                        array_intersect($choices, [self::CUSTOM]),
                    );
                    $event->getForm()->add('palette', ChoiceType::class, $palette($withKept));
                }
            })
            // Without this Form::viewToNorm() collapses '' to null, and
            // consumers would have to handle both.
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
            // uses this instead.
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

        if ($this->keptAsIs($hex) !== null) {
            $forms['palette']->setData($hex);
            $forms[self::CUSTOM]->setData(null);

            return;
        }

        $forms['palette']->setData(self::CUSTOM);
        $forms[self::CUSTOM]->setData($hex);
    }

    /** A non-hex colour outside the palette, which only a choice can hold. */
    private function keptAsIs(mixed $value): ?string
    {
        $value = CssColor::safe($value);
        if ($value === null || str_starts_with($value, '#')) {
            return null;
        }
        $known = $this->palette?->getHexes() ?? [];

        return \in_array(mb_strtolower($value), $known, true) ? null : $value;
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
