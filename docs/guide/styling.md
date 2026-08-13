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
    section_styles:
        - name: boxed
          label: 'Boxed'
          css_class: 'my-section--boxed'
          settings:
              styling:
                  backgroundColor: '#f1f5f9'
                  padding: { desktop: { top: 40, right: 40, bottom: 40, left: 40 } }
        - name: airy            # settings-only preset (no class)
          label: 'Airy'
          settings:
              styling: { padding: { desktop: { top: 96, bottom: 96 } } }
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

## Theming the builder chrome

Everything above styles **content**. The builder's own UI — topbar, sidebars, form widgets, preview overlay — is styled separately, from a single set of design tokens in `assets/styles/tokens.css`. Every rule in `admin.css`, `forms.css` and `builder.css` reads `var(--cb-*)` rather than a color literal, so restyling the tool is a matter of redeclaring tokens:

```css
/* your admin stylesheet, loaded after the package's */
.cb-shell,
.cb-launcher,
.cb-builder-dialog {
    --cb-accent-rgb: 124, 58, 237;   /* drives the accent and all its alpha variants */
    --cb-accent-strong: #6d28d9;
    --cb-radius: 10px;
}
```

The token names are public surface — they are covered by the package's semver guarantee like any other API.

| Group | Tokens |
|---|---|
| Surfaces | `--cb-surface`, `--cb-panel`, `--cb-panel-2`, `--cb-panel-3`, `--cb-field-bg` |
| Lines | `--cb-line`, `--cb-line-strong`, `--cb-line-hover` |
| Text | `--cb-text`, `--cb-text-2`, `--cb-muted`, `--cb-faint`, `--cb-on-solid`, `--cb-on-solid-rgb`, `--cb-inverse-surface` |
| Accent | `--cb-accent-rgb`, `--cb-accent`, `--cb-accent-strong`, `--cb-accent-darker`, `--cb-accent-soft`, `--cb-accent-soft-rgb`, `--cb-accent-bg` |
| Status | `--cb-danger`, `--cb-danger-rgb`, `--cb-danger-strong`, `--cb-success`, `--cb-success-rgb`, `--cb-success-strong`, `--cb-warning`, `--cb-badge-bg`, `--cb-badge-fg` |
| Shadow | `--cb-shadow-rgb` |
| Type | `--cb-font-mono`, `--cb-caption-size`, `--cb-caption-size-sm`, `--cb-caption-tracking` |
| Geometry | `--cb-radius`, `--cb-radius-sm`, `--cb-radius-xs` |
| Layout | `--cb-sidebar-width` (340px), `--cb-sidebar-collapsed-width` (32px) |

### The form alias layer

`forms.css` declares a second, narrower set that points at the tokens above. Redeclare only these to make the builder's inputs differ from the rest of the chrome — useful when the host's admin has a strong form style of its own and you would rather not fight it.

| Group | Tokens |
|---|---|
| Color | `--cb-form-bg`, `--cb-form-bg-soft`, `--cb-form-bg-disabled`, `--cb-form-text`, `--cb-form-text-muted`, `--cb-form-placeholder`, `--cb-form-accent` |
| Borders & focus | `--cb-form-border`, `--cb-form-border-hover`, `--cb-form-border-focus`, `--cb-form-focus-ring` |
| Status | `--cb-form-danger`, `--cb-form-danger-ring`, `--cb-form-success` |
| Type | `--cb-form-font-size`, `--cb-form-font-size-sm`, `--cb-form-font-size-xs`, `--cb-form-line-height`, `--cb-form-label-color` |
| Metrics | `--cb-form-control-height`, `--cb-form-gap`, `--cb-form-radius`, `--cb-form-radius-sm` |

The label typography tokens used in the sentence-case recipe below — `--cb-form-label-transform`, `--cb-form-label-font`, `--cb-form-label-size`, `--cb-form-label-tracking` — belong to this layer too.

Three details worth knowing:

- **Alpha variants come from the `*-rgb` tokens.** Rules build translucency with `rgba(var(--cb-accent-rgb), 0.25)`, so overriding `--cb-accent-rgb` moves the solid color *and* every focus ring, hover tint and shadow that derives from it. Override `--cb-accent` alone and the alphas stay behind.
- **The `--cb-form-*` layer is an alias layer, not a second palette.** Each token defaults to one of the chrome tokens above, so overriding `--cb-accent-rgb` alone already moves the form accent with it. Reach for the form tokens when you want the inputs to *diverge* from the chrome, not to restate it.
- **Captions are the only text the builder sets in its own font.** Field labels, group titles and the small hints inside a control render in `--cb-font-mono`, uppercase — a label names a control, and the difference in case and family is what lets the eye skip them when scanning a settings column for a value. Body text and headings inherit the host's font.

  The caption scale is two sizes, not three. A group heading is the *same* size as the field labels under it and outranks them by weight and color (`700` / `--cb-text-2` against `500` / `--cb-muted`); `--cb-caption-size-sm` is reserved for captions subordinate to a single control, like the side names in the box-spacing grid. Stacking a third size on top of uppercase + mono + tracking only makes every caption shout at a slightly different volume.

  Checkbox and radio labels are deliberately left in the body font: those are the sentence the user reads to decide, not a name for a control. A standalone checkbox therefore renders *without* a row-level label, since it would repeat that sentence as a caption — while a radio group keeps its row label, which names the group rather than an option.

  To put labels back in sentence case:

  ```css
  .cb-shell {
      --cb-form-label-transform: none;
      --cb-form-label-font: inherit;
      --cb-form-label-size: 0.8125rem;
      --cb-form-label-tracking: normal;
  }
  ```

::: info Reskinning the admin never restyles content
The preview iframe renders the host's public page with the kit's stylesheet. Its colors come from `content_blocks.palette` and the block styling settings documented above — a separate system on purpose, so changing the tool's accent never moves a button on the published page.
:::

A handful of colors live inside `data:` URIs (the select chevron, the checkbox tick) because custom properties cannot reach inside one. They are commented in place; adjust them by hand if a reskin moves far from the shipped palette.
