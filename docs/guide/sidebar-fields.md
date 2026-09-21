# Laying out sidebar fields

The block and section sidebars grow with every field a host adds. Four form options arrange what you add without touching a template: a **tab**, a collapsible **panel**, **icon buttons** for a choice, and a **tooltip** for the long help. They work on any field you add to a block form, to `SectionSettingsType` or to `StylingType`, and the core's own fields use them too.

```php
$builder->add('cornerMark', ChoiceType::class, [
    'required' => false,
    'choices' => ['Top left' => 'tl', 'Top right' => 'tr', 'Bottom left' => 'bl', 'Bottom right' => 'br'],
    'cb_group' => 'Decoration',          // its own tab
    'cb_panel' => 'Ornaments',           // a collapsible panel in that tab
    'cb_icons' => ['tl' => 'corner-tl', 'tr' => 'corner-tr', 'bl' => 'corner-bl', 'br' => 'corner-br'],
    'cb_icon_layout' => 'grid',          // a 2×2 grid of icon buttons
    'cb_icon_columns' => 2,
    'help' => 'Where the mark sits.',
    'cb_help_tooltip' => 'Drawn in the palette colour of the section, 24 px from its edges.',
]);
```

None of this changes what the field stores: a field in a panel or drawn as icons submits, validates and autosaves exactly like it did before.

## Tabs

A field's `cb_group` is the label of the tab it appears in. Fields sharing a label share a tab.

| Sidebar | Tabs, in order | A field without `cb_group` |
|---|---|---|
| Block | *General*, your tabs, *Style* | lands in *General* |
| Section | *Structure*, your tabs, *Style* | lands in *Structure* |

To join a core tab rather than open one, use its key: `SectionSettingsType::TAB_STRUCTURE` or `TAB_STYLING`. The *Style* tab is always last. On a block it holds the styling sub-form; on a section it holds the preset, the *Customize styling* switch and the styling sub-form. To add a field there, extend `StylingType` rather than giving it `cb_group`: see [Styling → Extending the styling sub-form](./styling.md#extending-the-styling-sub-form).

The label is a translation key of the `content_blocks` domain, a plain string (a missing key prints as is), or a `TranslatableInterface` for your own domain. The older `'attr' => ['data-cb-group' => 'SEO']` is still read, and the option wins when both are set.

The sidebar remembers the last tab used for each kind of sidebar (one memory for sections, one per block type) for the browser session, so an editor going from section to section stays on *Style*.

## Panels

A field's `cb_panel` gathers it into a collapsible panel, labelled like a tab. A panel appears where its first field would, so the order of the form still decides the layout; fields without a panel render flat in between.

The core's own panels:

| Where | Panel | Fields |
|---|---|---|
| Section, *Structure* | Columns | add, name and remove columns |
| | Layout | the display per screen, then what it gates: slider and accordion options, reverse on mobile, column widths |
| | Width | width mode, max width |
| | Advanced | CSS classes |
| `StylingType` (sections and blocks) | Spacing | padding, margin, column gap |
| | Background | colour, image, its size and position, the veil |
| | Size and alignment | min height, max width, alignments |

A field of yours can join one of these by using the same key, `StylingType::PANEL_BACKGROUND` for instance, or open a panel of its own.

**A field and the fields it gates share a panel.** If a value shows, hides or restricts another field — the display and the slider options, the width mode and the max width, the image and its position — the two belong in the same panel, or at the very least in the same tab. An editor should never have to switch tab to see the consequence of what they just picked. The core follows the rule, and a host field conditioned by a core one (through `data-cb-condition`) should join that field's panel.

**One panel open at a time.** The panels of a tab share a `name` attribute, which makes the browser's own `<details>` close the open one when another opens, with no script involved (Chrome 120, Safari 17.2, Firefox 130; an older browser opens them independently). The first panel is open by default; a panel holding an invalid field opens instead, and the sidebar remembers the last panel opened, like the tab.

To let several panels stay open, turn the option off on the form that holds them. A form type extension does it for the whole sidebar, since the setting carries down to every sub-form:

```php
final class IndependentPanels extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [SectionSettingsType::class, BlockFormType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('cb_panels_exclusive', false);
    }
}
```

**A closed panel sums itself up.** Its header shows what its fields hold, read off the form — `40 · 12 · 0 · 12` for a padding, the chosen option of a select or an icon choice, the name of an uploaded file — and a dot when anything is set. There is nothing to configure: the summary follows the fields as they are edited.

## Icon choices

`cb_icons` draws a `ChoiceType` as a row or grid of icon buttons. It maps each choice **value** to the **name** of an icon:

```php
'cb_icons' => ['' => 'auto', 'start' => 'valign-start', 'center' => 'valign-center', 'end' => 'valign-end'],
```

- The native radios (or checkboxes, with `'multiple' => true`) stay in the page, each covering its button invisibly: keyboard navigation, form submission, autosave and validation behave as for any choice field. Setting `cb_icons` implies `'expanded' => true`.
- Options appear in the order of `cb_icons`, so a grid reads the way you list it. A choice left out of the map still renders, after the others, as a text button (a whole row in a grid); so does one mapped to an icon no provider draws, in its place. The placeholder of a non-required field is the value `''`.
- The choice's label becomes the button's tooltip and its text for screen readers.

| Option | Default | |
|---|---|---|
| `cb_icons` | `null` | value ⇒ icon name; `null` leaves the field alone |
| `cb_icon_layout` | `'row'` | `'row'`, a segmented control, or `'grid'` |
| `cb_icon_columns` | `null` | columns of a grid (3 when not set) |
| `cb_icon_labels` | `false` | shows the label under (grid) or beside (row) each icon |

### The shipped icons

The core draws 78 icons on a 20 × 20 grid in `currentColor`, so they follow the sidebar's theme:

| Family | Names |
|---|---|
| Default | `auto` |
| Position (3 × 3) | `pos-tl` `pos-tc` `pos-tr` `pos-ml` `pos-mc` `pos-mr` `pos-bl` `pos-bc` `pos-br` |
| Corners | `corner-tl` `corner-tr` `corner-bl` `corner-br` |
| Sides | `side-top` `side-right` `side-bottom` `side-left` `side-all` |
| Rounded corners | `round-tl` `round-tr` `round-bl` `round-br` |
| Alignment | `halign-start` `halign-center` `halign-end` `halign-justify`, `valign-start` `valign-center` `valign-end` `valign-stretch` |
| Media | `fit-cover` `fit-contain` `fit-fill` `fit-none`, `ratio-1-1` `ratio-4-3` `ratio-16-9` `ratio-3-4` `ratio-21-9` |
| Layout | `dir-row` `dir-column` `dir-wrap`, `width-full` `width-centered`, `media-left` `media-right` `media-top` |
| Display and screens | `display-grid` `display-tabs` `display-accordion` `display-slider`, `viewport-desktop` `viewport-tablet` `viewport-mobile` |
| Effects | `shadow-none` `shadow-sm` `shadow-md` `shadow-lg`, `line-solid` `line-dashed` `line-dotted` `line-double`, `radius-none` `radius-sm` `radius-lg` `radius-full`, `overlay-none` `overlay-solid` `overlay-gradient` |
| Directions | `arrow-n` `arrow-ne` `arrow-e` `arrow-se` `arrow-s` `arrow-sw` `arrow-w` `arrow-nw` |

Some choices need no icon: a size (S, M, L) or a case (Aa, AA) reads better as its text. Leave those values out of `cb_icons` and they render as text buttons.

### Your own icons

Implement `ContentBlocks\Icon\UiIconProviderInterface`; with `autoconfigure: true` nothing else is needed. Return the **inner** SVG markup, drawn on the same grid:

```php
use ContentBlocks\Icon\UiIconProviderInterface;

final class DecorIcons implements UiIconProviderInterface
{
    public function getIcons(): array
    {
        return [
            'hatching' => '<path d="M4 16 16 4M4 10l6-6M10 16l6-6"/>',
            // Same name as a core icon: yours is drawn instead.
            'corner-tl' => '<path d="M3 11V3h8" stroke-width="2.4"/>',
        ];
    }
}
```

The markup is printed as is, so it must come from your code, never from an editor or the database. The registry wraps it in an `<svg viewBox="0 0 20 20">` with a 1.5 stroke. In your own templates, the Twig function prints the same element, or nothing for an unknown name:

```twig
<button type="button" title="Hatching">{{ cb_ui_icon('hatching') }}</button>
```

## Long help

A long help pushes every field below it down the sidebar. `cb_help_tooltip` keeps the `help` line short and puts the rest behind an **(i)** button after it, shown on hover and on keyboard focus:

```php
'help' => 'Refines or replaces the preset.',
'cb_help_tooltip' => 'Off: the preset applies as-is. On: the fields below refine or replace it.',
```

It is translated like `help`, in the field's translation domain.

## Your own tests

The core's fields carry their tab, panel and icons as view variables set by their parent type, so a test building `BlockFormType`, `SectionSettingsType` or `StylingType` with a bare `Forms::createFormFactoryBuilder()` keeps working. Your own fields that use the options need the two extensions registered in that factory:

```php
Forms::createFormFactoryBuilder()
    ->addTypeExtension(new \ContentBlocks\Form\Extension\SidebarLayoutTypeExtension())
    ->addTypeExtension(new \ContentBlocks\Form\Extension\IconChoiceTypeExtension())
    // …
```

A working example of every option lives in the sandbox: `apps/content-blocks-sandbox/src/ContentBlocks/Form/SectionOrnamentExtension.php`, covered by `assets/test/e2e/sidebar-layout.spec.js`.
