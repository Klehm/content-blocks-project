# Forms, defaults and styling

Integrator-facing versions: [../guide/custom-blocks.md](../guide/custom-blocks.md)
and [../guide/styling.md](../guide/styling.md).

## The block form is the whitelist

A block's `data` is never written raw. `BlockComponent::persistDraft()` submits
the form built by `buildForm()` and writes `$form->getData()` only on success,
which gives two guarantees for free:

- **Key whitelist** — a compound form maps only its declared children, so an
  unexpected key in the POST is dropped before it reaches `data`.
- **Value validation** — each field's `constraints` run on submit; a failure
  re-renders with errors and writes nothing. Nested collections validate through
  their `entry_type`.

There is deliberately no `sanitizeData()` / `getAllowedDataKeys()` hook: a custom
block secures its data purely by what it declares.

### Why the block form disables form-level CSRF

`BlockFormType` sets `csrf_protection => false`, which reads as a hole and is
not one. The form is only ever submitted through `BlockComponent`, a Live
Component, and three things already stand in front of it:

- the action endpoint requires an `Accept: application/vnd.live-component+html`
  header, which a cross-origin `<form>` cannot send — CORS blocks it;
- LiveProp values are signed with `kernel.secret` (HMAC checksum);
- authorization is enforced by `canEdit()` in `BlockComponent::save()`.

The reason it had to be turned off is narrower than the reason it is safe:
Symfony 7.2's stateless CSRF (token id `submit`) and the Live Component
hydrate/dehydrate cycle do not align, so the double-submit cookie and field token
mismatch on **every** save.

Revisit this if the form is ever rendered outside a Live Component — the whole
argument above collapses the moment a plain `<form>` can post to it.

## Why defaults are merged on form load

Both defaults providers — `BlockDataDefaultsProviderInterface` and its section
mirror `SectionSettingsDefaultsProviderInterface` — merge on **form load**, not
at render.

The reason is widgets with no "empty" state. A raw `<input type="color">` has no
way to say *nothing*, so an unset value shows the browser's own fallback
(`#000000`) and the editor sees a decision nobody made. Pre-filling the form
avoids that.

The mirror image, `withoutDefaults()`, strips default-equal entries again before
data flows to the decorator pipeline, so markup stays uncluttered when a user
saved values that happen to match the default.

Defaults merge recursively, so a provider can declare nested keys such as
`['styling' => ['backgroundColor' => '#eb0540']]`.

### The transparent-background default

`backgroundColor` defaults to `''` — no background — in both
`CoreBlockStylingDefaults` and `CoreStylingDefaults`.

`PaletteColorType` has a real "None" state, so the historical `#ffffff` pre-fill,
which existed only because a raw `<input type="color">` could not express
emptiness, is gone. Blocks and sections start transparent; picking White from the
palette applies a real `#ffffff`.

**Upgrade note:** a `#ffffff` already persisted now renders as a genuine white
background rather than as "unset".

## The styling data shape

`StylingBlockDecorator` reads the `styling` sub-form added by `BlockFormType` and
emits CSS custom properties plus utility classes that `styling.css` maps to real
properties. The section mirror is `StylingSectionDecorator`.

Under `$data['styling']`:

| Key | Shape |
|---|---|
| `padding`, `margin` | `{ desktop, tablet, mobile: BoxSpacing }` |
| `BoxSpacing` | `{ top, right, bottom, left: int, linked: bool }` |
| `backgroundColor` | `#hex` string |
| `maxWidth` | `{ value: int, unit: 'px' }` |
| `alignSelf` | `start` / `center` / `end`, honoured only when `maxWidth` is set |

Per-viewport overrides for padding and margin go through the same media-query
chain as sections. `maxWidth` and `backgroundColor` are not responsive.

## Why form extensions are a package seam

Every block is edited through the single `BlockFormType` — one form type, one
prefix, for all blocks. A stock Symfony `FormTypeExtension` matches by *class*,
so it would fire for every block and could not be scoped to one type. That is
why the package ships its own seam instead of leaning on the framework one.

An implementation carries `#[AsBlockFormExtension]` naming the block type **ids**
it targets, or `'*'` for all of them. Ids rather than classes, so the targeting
survives block subclassing and matches the config keys
(`content_blocks_kit.blocks.<type>`). Higher priority runs first, meaning its
fields appear earlier; equal priorities keep discovery order. The pairing and the
ordering are assembled at compile time by `BlockFormExtensionPass`.

Extensions run **after** the block's own `buildForm()`, so they can reference or
replace what it declared, and **before** `styling`, which `BlockFormType` appends
last so it always stays the final tab.

An added field round-trips like any other: the compound form maps its declared
children, so the value persists into `Block.data` as-is. Render it through a host
template override.

The seam is not add-only, because the builder handed over is the block's own:

- `$builder->remove('field')` drops a field. Its stored value is **frozen, not
  deleted** — the form's model data is the block's data array — and a POST still
  carrying the field is ignored, since only declared children map.
- Re-adding a child builder (`$b->add($b->get('url'))`) reorders the form, as
  children render in insertion order. Pass the *builder*, not the name, to keep
  the field's type, options and data.

## The cb_translatable option is a declaration

`TranslatableFieldTypeExtension` is a stock Symfony `FormTypeExtension` — not to
be confused with the per-block seam above — and its only job is to make the
`cb_translatable` option legal so a block can tag a field without Symfony
rejecting an undefined option.

The option carries **no behaviour**. The core never reads it at render or save
time, and with no translation package installed, tagging a field changes nothing.
It states that a field holds text an editor would want in another language;
`TranslatableFieldsInterface` reads the tags back.

It is `setDefined()` rather than `setDefault()` on purpose: an untagged field then
leaves no entry in the resolved options at all, so a reader can tell *not tagged*
from *tagged false* without every form in the app carrying the key.

The convention ships at 1.0 rather than with the translation package, so host and
kit blocks tag identically from day one — a package arriving later would find a
field set nobody had annotated.

**What to tag**: prose (headings, body copy, labels, alt text, captions) and link
targets, since a localized site routinely points at `/fr/contact`. **What not
to**: enums, colors, sizes and ids, which are the same in every language; a
locale payload carrying an untagged field is ignored at render.

Uploaded assets are deliberately left untagged by the kit: swapping a visual per
locale is an asset-management decision, not a text translation. Tagging one later
is purely additive, so a host that wants it can add the tag through a block form
extension.

## PaletteColorType and its empty state

A dropdown of the project palette plus a *Custom…* option revealing a free
picker. It stores a single `#hex` string (`''` for none), so it is a drop-in
replacement for Symfony's `ColorType` — decorators and view templates keep
reading a `#hex` unchanged.

The point of it is the **real empty state**. A raw `<input type="color">` always
carries a value, which is what forced the historical `#ffffff` default; *None* is
what lets the styling defaults be transparent instead. With an empty palette the
dropdown still renders None / Custom…, so the type degrades to "a ColorType with
an off switch".

Three details that look incidental and are load-bearing:

- **`choice_translation_domain` is pinned to `content_blocks`.** The None and
  Custom… labels are core translation keys, so they must resolve in the core
  domain — not the field's `translation_domain`, which a host or kit block may
  point at its own catalog, where those keys do not exist and would render as raw
  keys. Palette entry labels are literal strings, so `trans()` passes them
  through untouched. (A child form does not inherit `translation_domain` when
  `ChoiceType` resolves `choice_translation_domain`; left at its null default the
  labels would fall back to the `messages` domain.)
- **A view transformer pins the empty state to `''`.** Without one,
  `Form::viewToNorm()` collapses `''` to null, and consumers would have to handle
  both.
- **`empty_data` is `''`** for the same reason one level up: when every child is
  empty Symfony bypasses the data mapper entirely.

Showing and hiding the custom picker is driven by the generic `cb-condition`
Stimulus controller attached to the compound root, so no custom form-theme block
is needed and the type renders correctly in the section sidebar and the block
edit form alike.

## Where an extension lands in the sidebar

`SectionSettingsType` is extended the standard Symfony way, and the extra field's
value lands in the section's `draft_settings` JSON unchanged. To act on it at
render time, register a `SectionDecoratorInterface` that reads it.

Which **tab** a field appears in is decided by which type you extend:

| Extend | Field appears in |
|---|---|
| `SectionSettingsType` | the *General* tab |
| `StylingType` (or a sub-type) | the *Styling* tab, sections and blocks alike |

`StylingType` is one compound type serving both sections and blocks; the
irrelevant fields are gated by boolean options (`include_min_height`,
`include_alignment`, `include_max_width`) rather than by two near-identical
types.

`alignSelf` is block-only and only meaningful once `maxWidth` is set — without
one the block stretches to fill its column and `align-self` has no visible
effect. The row is hidden until then by `cb-block-styling-form`.

`stylingCustom` is the progressive-disclosure switch. Off keeps the everyday UX
to a single preset dropdown, and **drops the `styling` subtree on save**, so
switching presets never fights stale field values. On reveals the fields
pre-filled from the preset, which then refine it — user values winning key by key
at render.

`columnWidths` is a CSV of percentages summing to 100, stored in one hidden
field. The visible presets and number inputs are rendered by the sidebar template
and kept canonical by `cb-section-settings-form`.

The `maxWidth` placeholder is kept in sync with the configured default even
though `CoreSectionDefaults` normally pre-fills the field, so the hint a user sees
after clearing it never lies.

### Why untouched fields are pruned on save

`SectionSidebarController::normalize()` recursively drops null, `''`, `false`
and arrays left empty after pruning, before the settings reach the JSON column.
`0` is kept — zero padding is a decision.

This is not tidiness. Untouched styling fields submit as **nulls**, and
persisting a null would mask a preset's value on the next sidebar prefill: the
merge order is defaults ← preset ← current, so an explicit null "wins" over the
preset it was supposed to fall through to.

Unchecked checkboxes — `stylingCustom`, the spacing *linked* toggles — prune the
same way, and their absence reads as false everywhere the settings are consumed.
That is also why turning `stylingCustom` off can drop the whole `styling`
subtree without leaving a marker behind.

### The responsive styling sub-types

`BoxSpacingType` is four sides plus a `linked` flag. The flag is *persisted* so
reopening the sidebar restores the UX state; the link sync itself is a Stimulus
concern (`cb-spacing-link`), not a server one.

`ResponsiveBoxSpacingType` and `ResponsiveLengthType` hold desktop / tablet /
mobile under fixed keys. The viewport switcher shows one set at a time but the
form always submits all three, and an unset tablet or mobile inherits from the
next-wider value through CSS variable cascading at render — so empty viewports
are not a bug.

## Block decorators

`BlockDecoratorInterface` is the block-side mirror of
`SectionDecoratorInterface`: autoconfigured, called for every block being
rendered, and returning a `BlockDecoration` of classes, attributes and inline
styles derived from the block's data. Decorations are immutable and merged in
service order.

`BlockRestoreTally` is plumbing rather than a seam — it exists so the two replay
flows (area import, section-template insert) accumulate the same facts under the
same names instead of threading four by-reference parameters through their
builders. What reaches the caller is the flow's own result object.
