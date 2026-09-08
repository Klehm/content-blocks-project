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
