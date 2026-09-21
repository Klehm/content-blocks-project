# Rendering — the pipeline and its seams

Integrator-facing version: the *Draft / publié* section of
[../../CLAUDE.md](../../CLAUDE.md), and [../guide/](../guide/) for the extension
points.

## What each mode renders

**PUBLIC** is the last published state and nothing else: only entities carrying
a `publishedAt` (Section, Column) or a `publishedData` (Block), at their
published positions, in their published columns, with their published settings.

**PREVIEW** merges the draft in, keeps soft-deleted entities with a marker,
orders by `previewPosition`, and embeds the overlay bridge so the admin window
can react to clicks inside the iframe.

Mode is auto-detected from the request — `cb_preview=1` *and* `canEdit()` — and
anything else falls through to PUBLIC. The single-block and single-section entry
points skip the heuristic and default to PREVIEW, because only the builder calls
them.

## The deleted flag is draft-only

`deleted` is an intent to remove at the next Publish, not a removal. The
templates prune a flagged subtree whenever they render without the chrome, which
is right for a chromeless preview and would be a leak in PUBLIC — so the
renderer only computes the flag in PREVIEW. This is the single rule that makes
the published render immutable across a whole builder session.

The same asymmetry explains `publishedColumnBuckets()`. A cross-column drag
writes the block's column FK immediately, because that FK *is* the draft
location — the builder, the preview and the Doctrine cascades all walk it.
`publishedColumnId` remembers where the block was published so PUBLIC can keep
showing it there. The method returns `[]` when no block in the area carries one,
which is the overwhelmingly common case; callers then read each column's own
collection, exactly as they did before the column could move.

## Why chrome is a separate flag from mode

`cb_chrome=0` renders draft content **without the editing chrome** — no
`builder.css`, no add tray, no section handle, no overlay script, soft-deleted
subtrees left out. It is what the page will look like once published, drawn from
unpublished data.

It exists for *readers* of a draft rather than editors of one: a review link, an
approval step, the translation workbench preview pane. There the builder
toolbars would be dead ends, because there is no builder around them.

Absent or any other value keeps the chrome, so every preview URL written before
the flag existed renders exactly as before. Chrome is only ever on in PREVIEW —
public pages have never had it.

## Why the pipeline takes a context object

The render methods used to take a bare `?RenderMode`. Adding a parameter to a
published interface breaks every implementor, so `RenderContext` carries the
inputs instead and can grow without another signature change.

Locale is the first passenger. Content translation lives in a satellite package,
but the seam it plugs into had to be frozen with the 1.0 contract.

Both properties are optional and null means *decide for me*: a null mode is
resolved from the request, a null locale defers to whatever the host considers
current. Setting a locale explicitly renders an area in that language regardless
of the request — a language switcher, a sitemap job, a transactional email.

Inside a resolver the mode is always concrete: the renderer materializes it
before running the pipeline, so branching on PREVIEW versus PUBLIC is safe there.
Materializing lazily also keeps the request-inspecting heuristic off the hot path
of the single-block and single-section entry points.

## Resolving what a block renders

`BlockDataResolverInterface` is the seam for **what** a block renders;
`BlockDecoratorInterface` is the seam for how its wrapper looks, and by contract
cannot touch `data`. Resolvers are autoconfigured, run in tag priority order, and
each receives what the previous one produced — a pipeline, not an accumulator,
because the payload is one value refined in turn.

`CoreBlockDataResolver` seeds it at priority 256: PREVIEW shows the in-flight
edit falling back to the published value, PUBLIC shows only what was published.
Everything registered afterwards transforms a payload rather than producing one.
A host wanting a different seeding rule registers at a higher priority and
ignores the incoming `$data`.

The motivating case is translation — merge the requested locale's field values
over the source payload — but the shape is general: placeholder tokens, computed
values, A/B variants.

Contract for an implementor:

- **return** the payload, do not mutate in place; returning `$data` unchanged is
  a valid no-op;
- stay side-effect free — this runs once per block on every public page render,
  possibly inside a warm cache.

## Replacing the renderer itself

`BlockRendererInterface` is aliased to the shipped `BlockRenderer`; a host that
needs to wrap output, swap the mode heuristic or add caching re-aliases it,
typically decorating the default with `#[AsDecorator]`.

Prefer a data resolver when the goal is to change what a block renders rather
than how the tree is walked — a far smaller surface to own.

`renderBlock()` and `renderSection()` exist for the builder hot-swap path. Both
reuse the same Twig wrapper the full area render uses, so a hot-swapped fragment
is byte-for-byte identical to its in-page form: the block keeps its
`data-cb-block-id` marker, decorators and view template. The section case only
copies the wrapper attributes back, leaving the inner blocks and their JS state
untouched.

## Section decorators emit variables

`SectionDecoratorInterface` contributes classes, attributes and inline styles for
a section's outer markup. Decorations merge in service order, the built-in one
first, so a host extension can react to or override its output.

`StylingSectionDecorator` deliberately emits CSS custom properties that the
package stylesheet maps to real declarations, rather than writing the
declarations itself. Inline style cannot carry a media query, so the indirection
— decorator emits vars, stylesheet emits responsive rules — is the only clean
path to per-viewport section styling.

Section variables are namespaced `--cb-s-*` so they do not inherit into
descendant blocks, which read `--cb-b-*`.

Spacing variables (padding, margin, gap) go through `ViewportVars`, which drops
any value the stylesheet's chain already yields: mobile falls back to tablet,
tablet to desktop, desktop to `0` (gap: `1rem`, so desktop gap is always
emitted). A tablet `0` under a desktop `40` is therefore kept. Only the
variables go: an all-zero box still earns `--styled`, because that class is
what zeroes the host's or kit's own spacing. The elision leans on nothing
inheriting these variables from an ancestor, which holds as long as blocks do
not nest styled blocks.

The centered width mode is the same trick for a different reason: `--cb-row-max-w`
constrains the inner `.cb-row`, not the `<section>`, so the section background
still spans the viewport while its content stays capped. The decorator only has a
handle on the section element, so emitting a variable is how it reaches the row.
A missing key falls back to the configured default, so a centered section is
never accidentally uncapped; an explicit `0` is preserved and means *no cap*.

The default is bound to `content_blocks.section.default_max_width` and shared
with `CoreSectionDefaults`, so the form pre-fill and the rendered output read the
same number and move in lock-step when a host overrides the parameter.

### The settings shape

Under `$settings['styling']`:

- `padding`, `margin` — `{desktop, tablet, mobile}`, each
  `{top, right, bottom, left: int, linked: bool}`
- `gap` — the same three viewports, one px value each, set on the section and
  inherited by `.cb-row`
- `backgroundColor` — `#hex`
- `minHeight` — `{value: int, unit: 'px'|'vh'}`
- `verticalAlign` — `start | center | end`

Each responsive family falls back desktop → tablet → mobile in `styling.css`.

## Defaults and why they are stripped

Defaults are merged on form **load**, so widgets with no empty state — an HTML5
color or range input — open on a sensible value rather than a browser fallback
like `#000000`. Providers are autoconfigured and later ones win on conflict.

They are then stripped on the way **out**: `withoutDefaults()` removes
default-equal entries recursively, and drops a nested array that became empty, so
a section saved with a framework default gets no inline style for it. Only real
overrides reach the markup. Decoration sees the trimmed payload; the block type's
own view template still receives the untrimmed `$data`.

`backgroundColor` defaults to `''`, meaning no background. `PaletteColorType` has
a real *None* state, so — unlike the raw `<input type="color">` it replaced — an
untouched form no longer needs a sacrificial `#ffffff` to avoid persisting black.
**Upgrade note**: settings saved before that change may carry
`styling.backgroundColor = '#ffffff'`, which used to be stripped as default-equal
and now renders as an actual white background.

## Column presets are spans

`Column.preset` is `col-N`, a span on a 12-unit grid, and `layout.css` turns it
into `flex-grow: N` over a zero basis. The first version gave each preset a
`flex-basis` computed for its one known row: `col-4` meant a third of the row
minus two gaps. That breaks as soon as layouts are host config. `[4, 8]` mixes
two presets whose bases were each computed for a different row, and `col-3`
had no rule at all. With grow over a zero basis, the browser shares out the
row's real gap and any mix of spans keeps its ratio.

`col-12` keeps `flex-basis: 100%`, so a lone full-width column still wraps
whatever follows it.

Breakpoints only set `flex-basis`, never `flex-grow`, so the column-width
weights (`.cb-col--weighted`) keep their ratio inside a row. At tablet width,
`col-6` goes to half-width only in a row that also holds narrower spans
(`:has()`). Otherwise a weighted two-column row would fall back to 50/50,
which it did not do before.

## Columns and tabs

A section's columns are no longer fixed by its layout. The section sidebar adds,
names and removes them (`ColumnsController`), and the `display` section setting
shows them side by side (`grid`), one at a time (`tabs`) or as collapsible
panels (`accordion`). The layout name
stays what the section was created from, and nothing renders from it but a CSS
class.

**Every mutable column field has a draft twin**, like the rest of the model.
`preset` is the draft and `published_preset` what the page renders, because
adding a column re-spans the others (three `col-6` become `col-4`), and writing
that to a field the public render reads would move the live page before
Publish. `draft_settings` / `published_settings` hold the name. A column
published before the twin existed has no `published_preset`: the render falls
back to `preset`, and `setPreset()` pins the old value on the first change, so
an un-backfilled database is safe too.

**Adding or removing resets the spans to equal.** An uneven `[4, 8]` becomes
`[4, 4, 4]` with a third column. Guessing how to split a custom layout is worse
than a visible reset, and undo restores the presets, which the structure
snapshot records. A delete is the draft flag, blocks included: the column
leaves the builder, stays on the public page until Publish, and comes back
with Discard or undo. The last live column cannot be deleted, and a section
holds at most `ColumnsController::MAX_COLUMNS` (20).

**Tabs are CSS only, with no script on the public page.** The section renders
one radio per column, then a nav of `<label>`s, then the row, as siblings, so
`radio:nth-of-type(n):checked ~ .cb-row > .cb-col:nth-of-type(n)` opens a panel
and `~ .cb-tabs__nav > :nth-child(n)` marks its tab. Those rules are written
out to 20, the column cap. Being a sibling of the row, the nav needs the row's
sizing too: the centered cap (`--cb-row-max-w`) and a fixed full width, since a
vertical alignment makes the section a flex column where it could shrink. A radio group also gives arrow-key navigation for
free. Ids derive from the section id rather than a random suffix, because the
public render must be byte-stable (see the immutability test).

**The accordion is CSS only too, and needs no rule per index.** Each column
is preceded, inside the row, by a checkbox and its header `<label>`, so
`toggle:checked + .cb-accordion__header + .cb-col` opens that panel whatever
the count. Checkboxes rather than radios: panels open independently, and a
radio could never be closed again. The row turns `display: block` so the
section's column gap does not open between a header and its panel. The
interleaving is safe because everything that walks columns selects
`[data-cb-column-id]`, never the row's children by position.

**One panel at a time uses radios, and a radio cannot be unchecked.** So the
row starts with a hidden "none" radio of the same group, and each panel gets a
second header labelled for it. `radio:checked + header` hides the opening label
and shows its twin, so clicking the open header checks "none" and the panel
closes. The builder overlay skips `.cb-accordion__none` when it lines inputs up
with columns. The open index is compared with `is same as`: Twig's `0 == null`
is true, which checked the first panel of every section meant to start closed.

In the builder the same markup is live, and three things keep it usable:

- A **deleted column keeps its place** in the DOM (hidden), so indices still
  line up; the first *live* tab is the one opened.
- A **full preview reload would reopen the first tab**, so the overlay stores
  the open columns per section in `sessionStorage` (a list, for the
  accordion) and restores them on load.
- **Focusing a block opens its tab or panel** (`revealTab`). The tree panel and the
  sidebar can select a block that sits in a closed tab.

A tab title renders through `ColumnSettingsResolverInterface` (autoconfigured,
empty by default), which is how the i18n package puts it in the page's locale.
See [i18n.md](i18n.md#tab-titles-are-translated-beside-the-column).

`patchSection` swaps the radios and the nav, or re-inserts each toggle and
header before its column, along with the classes. It keeps what was open when
those columns are still there, so switching an accordion to tabs keeps the open
panel as the open tab, and a rename or a display toggle updates in place. Adding or removing a column changes the row itself, so it
reloads the preview and remounts the sidebar.

## Display per viewport

`display` is the desktop value. `displayTablet` and `displayMobile` are
optional, and a missing one inherits the viewport above it. They are flat keys
rather than a `{desktop, tablet, mobile}` map so that every section, preset and
layout written before them stays valid as it is.

**A narrower viewport may only become more compact.** `SectionDisplay::resolve()`
applies the rule, and a value it refuses falls back to the viewport above
rather than failing:

| Above | Allowed below |
|---|---|
| grid, slider | grid, slider, accordion |
| tabs | tabs, accordion |
| accordion | accordion |

The rule exists for the markup, not for taste. Grid and slider are the same
HTML, and so cost nothing to swap. Tabs put their radios and nav *beside* the
row, so tabs below a grid would ship both structures to every visitor. Tabs
turning into an accordion reuse the tab radios: the row only gains one
`<label for>` header per column, and panel visibility is the tabs rule
unchanged — hence one panel always open there, whatever `accordionSingle` says.
Grid or slider turning into an accordion ship the accordion toggles and
headers, hidden until their range.

**Classes.** A uniform section keeps its old markup byte for byte:
`cb-section--display-{d}`. A section that changes gets
`cb-section--responsive` plus `cb-section--t-{t}` and `cb-section--m-{m}`, both
always, resolved. `layout.css` then scopes each mode to a range:

- slider: `display-slider:not(.responsive)` everywhere; otherwise
  `responsive.display-slider` above 768px, `t-slider` from 541 to 768px,
  `m-slider` up to 540px. Disjoint ranges, so nothing has to be undone.
- accordion: sticky downwards, so `display-accordion` everywhere,
  `t-accordion` up to 768px, `m-accordion` up to 540px.
- tabs: unconditional; an accordion range hides the nav.

Accordion toggles and headers are `display: none` outside an accordion range,
which also keeps an invisible checkbox out of the tab order. The tab panels
match `.cb-col:nth-of-type(n)` rather than `:nth-child(n)`, because the
accordion headers interleaved in the row are labels, not divs.

**A column is a region everywhere** once an accordion appears at any viewport,
since its `aria-labelledby` must point at a header that exists. Harmless on
desktop, where the region is merely named.

## The slider

A slide is a column, like a tab or a panel, so a slide holds any blocks. The
row scrolls with `scroll-snap`; `--cb-slides-d/t/m` (1 to 6) set how many
columns show, and the width reuses the row gap. **Swiping needs no script.**

`sliderControls` (`both`, `arrows`, `dots`, `none`), `sliderAutoplay` (seconds,
0 is off) and `sliderLoop` travel in `data-cb-slider` with translated labels.
`assets/slider.js` builds the controls, and is linked by the area template only
when a section needs it — always in the builder, where a display can change
under the editor. A module script runs once per URL, so two areas on a page
still load it once.

The script never decides whether a section is a slider: CSS does, and it reads
the answer back (`scroll-snap-type` on the row). The breakpoints therefore live
in one file. A `ResizeObserver` catches a range change; a `MutationObserver`
catches a section inserted or patched by the builder. Autoplay rewinds at the
end, pauses on hover, focus and a hidden tab, and is off under
`prefers-reduced-motion` and in the builder preview. Loop means the arrows
rewind; an infinite loop would clone slides.

## Order per viewport

Sections and blocks can be reordered for tablet and mobile. Columns cannot
yet: the preview has no column drag at all. `reverseOnMobile` still covers the
usual case, and applies only where mobile resolves to a grid.

**Each element stores its own rank**: `_order: {tablet?, mobile?}` in a
section's settings or a block's data (`_` is the reserved prefix). A list of ids
on the parent was the alternative. Every copy path — clipboard, template,
import, duplicate — would have had to rewrite those ids, and sections would
have needed settings on `ContentArea`.

**The renderer turns ranks into a full sequence** (`ViewportOrder`), because a
rank alone cannot place a sibling that has none. Ranked siblings sort by rank,
then desktop order; each unranked one is inserted right after its desktop
predecessor. So a duplicate lands after its source, a pasted block after its
anchor, a new block after the last one, with nothing to rewrite on creation.
Mobile builds on the tablet sequence when tablet has one. The result is
`--cb-order-t` / `--cb-order-m` on every sibling of a group that has a rank,
and nothing on the others.

`layout.css` applies `order` to `.cb-content-area > .cb-section` and
`.cb-col > .cb-block` in the two ranges. The area only becomes a flex column
(`cb-content-area--ordered`) when a section carries a rank, because a flex
parent stops vertical margins collapsing and every other page must keep its
spacing. The accordion's open panel is a flex column like a tab panel, so block
order holds there too.

**In the builder the viewport decides what a drag means**, read by the overlay
through `matchMedia`, not from the topbar button. On desktop a drag moves the
element; on tablet or mobile it posts the new visual sequence to
`POST /area/{id}/viewport-order` and nothing moves in the DOM. A block leaving
its column is refused there: CSS cannot express it. A block moved to another
column on desktop loses its ranks. Any hot insert or move in a group that
carries ranks reloads the preview, since the sequence of its siblings changed.

The history field is `order`, read and written inside `settings` or `data`, so
an undo carries the ranks alone and never a whole payload.

## Style presets as a base layer

A `SectionStyle` carries a `cssClass`, a `settings` map, or both — a class-only
preset (pure CSS) and a settings-only preset (pure values) are equally valid.

The settings apply *underneath* the section's own: the preset is the base and the
user's explicit values win key by key. With *Customize styling* off, the saved
settings hold no `styling` subtree at all, so the preset applies untouched.

Presets merge by `name` across providers, later ones winning, and reach the
registry through a tagged iterator so host and package providers surface
together.

## Cloning a section

`SectionClonerInterface` backs both the section duplicate flow and the area-level
replace-content flow. The returned copy is:

- **unattached** — no ContentArea, no `previewPosition` of its own (columns and
  blocks keep theirs); the caller decides where it goes;
- **born as a draft** — every mutable value lands in the draft slots, so the copy
  shows up as an unpublished change like any other edit;
- **draft-wins** — where the source has an in-flight edit, the copy carries it
  rather than the last published value, because the in-flight edit is the better
  evidence of intent;
- **pruned** — soft-deleted descendants are skipped.

Nothing is persisted or flushed; that is the caller's call.

### Why the clone notification is an observer

`SectionCloner` copies a block's `data` wholesale, so anything stored *inside*
`Block.data` rides along for free. Anything stored **beside** a block — a
satellite package's own table keyed by `block_id` — does not, and had no way to
learn about the copy: `cloneSection()` returns the new Section and the
source→copy correspondence is discarded inside the walk.

Translation is the first case (per-locale values live in their own table, and
duplicating a section must duplicate them), but the shape is general: per-block
analytics, A/B variants, review state.

The obvious alternative — return the correspondence alongside the copy — changes
a published interface's signature and breaks every implementor. A tagged
collection is additive: the cloner gains a constructor dependency, the interface
never moves, and an installation with no observers behaves exactly as before.

Contract for an observer:

- called once per copied block, **during** the walk, so `$copy` is not yet
  persisted and **has no id** — attach to the object, not to an identifier;
- the caller persists `$copy` after the walk, so an observer writing its own rows
  against it must let that flush commit both; do not flush here;
- stay cheap: this runs inside duplicate, paste and replace-content, all
  interactive.

The collection is a constructor default rather than a required argument so
building a cloner by hand — which tests and host scripts do — stays a
no-argument call.

`ColumnCloneObserverInterface` is the same seam one level up, for rows stored
beside a column (a translated tab title). Same contract, called once per
copied column, before that column's blocks.
