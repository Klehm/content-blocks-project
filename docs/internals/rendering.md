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
