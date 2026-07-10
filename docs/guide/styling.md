---
title: Styling
---

# Styling sections and blocks

Each section's settings sidebar carries a **Styling** group with padding, margin (per viewport), background color, min-height and alignment. Block edit forms carry the same group with padding, margin, background color and max-width.

On sections, the styling fields sit behind a **"Customize styling" switch** (progressive disclosure): everyday editors only see the style-preset dropdown; flipping the switch reveals the full fields, prefilled from the selected preset. While the switch is off, the styling subtree is dropped on save so a later preset change never fights stale values.

These fields land in JSON under `settings.styling` for sections and `data.styling` for blocks. They are stored as-is — no DB migration; existing content keeps working untouched (sections saved before the switch existed are treated as customized).

## Color palette

Background colors use `PaletteColorType`: a dropdown of named project colors plus a **Custom…** option revealing a free color picker. It stores a plain `#hex` (`''` for none) so decorators and templates are unaffected. Declare the palette in config:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    palette:
        - { label: 'Primary', color: '#eb0540' }
        - { label: 'Dark',    color: '#252525' }
```

…or implement `ContentBlocks\Palette\ColorPaletteProviderInterface` (autoconfigured) for runtime palettes; both sources merge. With no palette declared, the dropdown still offers **None / Custom…** — which is what gives the field a real empty state (backgrounds now default to *transparent*; the old `#ffffff` pre-fill hack is gone).

::: warning Upgrade caveat
Because backgrounds now default to transparent (`''`), an existing `#ffffff` value already persisted will render a real white background. Watch for this when upgrading.
:::

`PaletteColorType` is reusable in your own block forms (option `allow_custom: false` locks editors to the palette).

## Section style presets

Presets are named styles offered in the section sidebar. Each carries a CSS class and/or **settings values** applied underneath the section's own settings at render time (the user's explicit values win key-by-key):

```yaml
content_blocks:
    styles:
        - name: boxed
          label: 'Boxed'
          css_class: 'my-section--boxed'
          settings:
              styling:
                  backgroundColor: '#f1f5f9'
                  padding: { d: { top: 40, right: 40, bottom: 40, left: 40 } }
        - name: airy            # settings-only preset (no class)
          label: 'Airy'
          settings:
              styling: { padding: { d: { top: 96, bottom: 96 } } }
```

…or implement `ContentBlocks\Section\SectionStyleProviderInterface` and return `SectionStyle` instances (the fourth constructor arg is the settings array).

## Conditional form fields (`cb-condition`)

The sidebar's show/hide logic is a generic Stimulus controller you can reuse in your own block forms: attach `data-controller="cb-condition"` on a container (form type `attr`) and tag rows with `row_attr` → `data-cb-condition="field:value1|value2"` (checkboxes match `true`/`false`; `field` alone means "non-empty"). Combine conditions with **AND** by separating clauses with `;` (e.g. `size:custom;customHeightAuto:false`); each clause still **OR**s its values with `|`. The field name matches the last bracket segment of the input's `name`.

At render time, two decorators (`StylingSectionDecorator`, `StylingBlockDecorator`) translate the values into **CSS custom properties** on the outer element, and a stylesheet shipped at `/_content-blocks/public/styling` maps those vars to real properties with `@media` rules for tablet (`max-width: 991px`) and mobile (`max-width: 575px`) — so per-viewport overrides actually work (inline `style` can't carry media queries).

The fallback chain inside each `@media` block is: mobile → tablet → desktop → 0. A viewport you leave blank inherits the next-wider one.

## Extending the Styling sub-form

The `StylingType` form holds the styling fields. Register a Symfony `FormTypeExtension` against it to inject (or override, by re-`add()`ing an existing name) fields without forking — they will render inside the sidebar's **Styling** group, for sections and blocks alike:

```php
use ContentBlocks\Form\Type\Styling\StylingType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;

final class ZIndexExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [StylingType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // `include_gap` is only true for sections — use it to gate
        // section-only fields.
        if ($options['include_gap']) {
            $builder->add('zIndex', IntegerType::class, ['required' => false]);
        }
    }
}
```

Pair it with a `SectionDecoratorInterface` reading `$settings['styling']['zIndex']` to emit the style. (For curated background colors, prefer the built-in `palette` config above.)

## Adding your own block decorator

Implement `ContentBlocks\Block\BlockDecoratorInterface` (mirror of `SectionDecoratorInterface`). It is auto-tagged with `content_blocks.block_decorator` when `autoconfigure: true` is on, and called for every block being rendered. Return a `BlockDecoration` (classes / inline styles / attributes) — the bundle merges all decorators' output into the block's outer `<div>`.
