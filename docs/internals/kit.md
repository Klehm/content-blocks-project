# Kit — the block library and its config surface

Integrator-facing version: the *content-blocks-kit* section of
[../../CLAUDE.md](../../CLAUDE.md), and `content-blocks-kit:blocks`, which
documents the whole surface from the code.

## Three levers, one source of truth

`AbstractKitBlock` gives a host three things, all wired from
`content_blocks_kit.blocks.<type>`:

| Lever | Does | Read through |
|---|---|---|
| `options` | block-level knobs (`max_columns`) | `option()`, merged over `defaultOptions()` |
| `choices` | restricts or reorders a `ChoiceType` | `choices()`, declared once in `choiceFields()` |
| `defaults` | overrides the block's initial data | the final `getDefaultData()` |

`choiceFields()` is the single declaration each choice map has. `choices()`,
`choiceConstraint()` and `describe()` all read it, which is what lets
`content-blocks-kit:blocks` document a block without the docs drifting from
`buildForm()`.

The bundle injects the merged option set and the raw choice/default overrides as
constructor arguments at registration time, so at runtime a block reads a
fully-resolved surface with no null-coalescing. All three arguments default to
empty, so `new SomeBlock()` still works — a unit test or the doc command gets the
coded surface.

Overrides are restricted to keys the block declares, so a typo in host config can
never leak a stray key into stored block data.

## Why the constraint is a union

`choiceConstraint()` validates against the **union** of the coded value set and
the resolved one, and both halves earn their place:

- the **coded** set is kept so narrowing the picker never invalidates content
  already stored with a now-hidden value;
- the **resolved** set is added so a value the host introduced through config
  survives its own form — without it, `choices` could offer a value the validator
  would then reject.

A host's `value: label` map is converted with a loop rather than `array_flip()`,
because that map is arbitrary input: values are cast to string (YAML happily
hands over integers), and two values sharing a label would silently collapse, so
the second is disambiguated instead of lost.

### Defaults are pulled back into the offered set

A host that replaces `variant` without also setting `defaults.variant` would
otherwise have every new button start on the kit's coded default — a value their
config just removed, absent from the dropdown and unstyled on the page.
`reconcileChoiceDefaults()` falls back to the first offered value, so the two
halves of the config agree on their own.

It only ever moves a default that is **not** on offer, so a block whose config
still contains its default is untouched.

## Disabling, and why html_raw is off

Disabling a block **un-registers its service**, so it never reaches the registry
or the picker.

`html_raw` renders unescaped markup (`{{ html|raw }}`), so it trusts its editors.
Keeping it out of the picker until a host consciously sets
`content_blocks_kit.blocks.html_raw.enabled: true` is the safe default — hence
`DEFAULT_DISABLED`.

Every other block omitted from config defaults to enabled with its coded options.

`resolveBlocks()` is pure — no container — so the gating and option-merge logic
is unit-testable. Choice and default overrides pass through it raw, because they
are consumed inside the block against its coded schema.

`KitBlockConfigPass` exists because the loop in `loadExtension()` only reaches
the blocks this bundle registers. The pass reaches the ones a host *subclassed*,
which are precisely the ones that had to switch the kit's own service off to
exist.

## Blocks are autonomous

No Tailwind, no Bootstrap, no LiipImagine, no icon font. Markup is neutral
`cb-kit-*` styled by a single `kit.css` served at a public route.

Colour fields across the kit — title, text, icon, divider, and the rich-text
swatches — all draw on the **core** palette through `PaletteColorType`, so a
project sets its colours once. Each stores a plain `#hex`, with `''` meaning
*inherit*, so a view template never needs to know a palette exists.

`ImageBlock` renders a plain `<img>` at the chosen display size and pulls no
image-processing dependency. A host wanting server-side resizing aliases
[`ImageUrlResolverInterface`](assets.md#the-image-seam-ships-a-passthrough)
rather than overriding a template.

`TitleBlock` decouples **visual size from semantic element**: an editor can emit
a correct `<h2>` that looks like an h1. The size drives a `cb-kit-title--h*`
class, the tag drives the element.

The icon picker's labels are the icon names themselves
(`choice_translation_domain: false`), and the set comes from the registry — so a
host's `IconProviderInterface` appears with no config at all, and `choices` then
restricts or reorders what it produced. Adding a glyph is a service declaration;
`choices` alone cannot do it, because it filters a set rather than extending one.

## Icons are added, not filtered

The kit ships `IconSet`, a fixed list of 23 glyphs, and that used to be the
whole story: `choices` could narrow it and nothing could widen it. Naming an
icon the kit does not have produced an **empty block** — `cb_kit_icon()`
returned nothing and the view rendered none of its markup — which is a worse
failure than any other choice field in the kit, and the only one config could
reach.

So icons come from `IconProviderInterface` instead: a provider supplies the
glyph *and* its name in one place, which is the only way the picker and the page
can agree. `choices` keeps its usual meaning on top — restrict or reorder what
the registry ended up with.

A provider returning a name the kit already ships **replaces** that glyph, which
is how a host swaps the shipped look without touching the block or its template.

`IconRegistry` resolves one set that feeds both the picker and `cb_kit_icon()`,
so a name that can be chosen is always a name that can be drawn. `IconSet`'s
static API is untouched and still describes the shipped glyphs; anything at
runtime should read the registry.

Providers return **inner** SVG markup only, drawn on a 24×24 viewBox. The
wrapper `<svg>` — sizing, `currentColor`, stroke width — is the kit's.

## Rich text: one payload, several editors

`editor` is a **display-time choice**. The stored payload is the same HTML under
every editor, so flipping it does not touch a single row.

The field is a plain `<textarea>` enhanced client-side by whichever editor's
Stimulus controller the adapter names. With JS disabled — or an editor that fails
to load — the user still gets a textarea holding the same HTML.

`script_url` / `style_url` exist to self-host the same build (an air-gapped
admin, a strict CSP). `asset:<path>` resolves through the host's asset packages,
which is what a versioned filename needs. The older `cdn_url` / `cdn_style_url`
names are still honoured but read as "another CDN", which is not what
self-hosting is.

Options are passed to the adapter **resolved**, not raw, so a block built outside
the bundle's merge still hands over a complete set.

### Assets, and the asset: prefix

Editor knobs live under `content_blocks_kit.blocks.rich_text.options`: `cdn`,
`script_url`, `style_url`, `uploads`, and a `config` merged over the adapter's
coded init config in the browser.

**Any string in there may be written `asset:<path>`** and is resolved through the
host's asset packages — the only way a static YAML file can name a versioned
asset, whose URL carries a digest nobody can spell out by hand. It works at any
depth, since TinyMCE's `content_css` takes a list as readily as a single file and
an editor's config is free-form beyond that.

A **missing resolver throws** rather than emitting the path untouched: a silent
passthrough would ship a 404 into the editor chrome, where it reads as "my styles
are ignored" and not as "this needs configuring".

What cannot travel this way is **code** — `setup`, a custom button's `onAction`.
JSON has no function type, so those belong to the `cb-rich-text:configure` event
the controllers fire before init.

`cdn: false` empties both asset URLs rather than dropping them: the controller
reads *no URL* as "the host bundled the editor, expect the global to be there".

`config` is cast to an object at the top level only, so an empty config reads as
`{}` rather than `[]` while nested lists — a spelled-out toolbar — keep their
array shape. Encoding degrades to an empty payload rather than throwing, since an
unencodable value would otherwise blow up rendering the whole sidebar.

The editor's swatches read the **same** host palette as `PaletteColorType`, so
the two cannot drift apart.

CKEditor 5 needs a stylesheet next to its script, which TinyMCE does not — hence
the second asset URL. Both are pinned to one version, because the factory
signature changed in 48 (`create({attachTo})` supersedes `create(element)`) and
the controller picks its call shape from `window.CKEDITOR_VERSION` so an older
self-hosted build still boots.

## Preview hints in the kit

Fourteen blocks implement [`BlockPreviewHintInterface`](blocks.md#preview-hints-and-why-they-stay-tiny);
`icon`, `table` and `html_raw` stay deliberately as named tiles.

Three decisions worth keeping:

- **`ImageBlock` passes the storage path**, so the tile shows the actual
  picture — the single biggest win of drawing the poster in the DOM rather than
  rasterising it. An image still uploading has none, and `image()` degrades to a
  labelled tile.
- **`RichTextBlock` strips tags** rather than rendering them. A tile shows a line
  of plain copy, and injecting editor markup into the admin's DOM is not
  something a preview should ever do.
- **`EmbedBlock` returns a generic tile**, because a thumbnail would mean calling
  the provider, which a list endpoint has no business doing.

`EmbedBlock` *does* opt into preview hot reload, which looks wrong for an iframe
and is not: the third-party player boots inside the frame, not on our page, so
the swapped-in view needs no init pass. See
[blocks.md](blocks.md#preview-hot-reload-is-opt-in).

## Why views check a token shape, not a value list

The kit's views used to inline a whitelist per field —
`variant in ['primary', 'secondary', 'outline', 'link'] ? variant : 'primary'`.
That made a value a host added through `choices` **unrenderable**: the picker
offered it, the editor saved it, and the template quietly swapped it back for the
coded default. The list also had to be kept in step with `choiceFields()` by
hand, in ten places.

What those whitelists were really protecting is narrower than a value list. The
value is interpolated into `class="cb-kit-btn--{{ variant }}"`, and `Block.data`
is not necessarily well-formed — it may predate the field, come from an import,
or have been hand-edited. Twig escapes the quotes, so this was never an
injection; but a value carrying a space would silently become a second class, and
an empty one would leave a dangling `cb-kit-btn--`.

So the rule is a **shape** check, not a membership one: keep the value when it
reads as a single class token, fall back otherwise. A configured value passes —
the host writes the matching CSS — and a malformed one never reaches the markup.

The token pattern is deliberately permissive within one token (letters, digits,
`_`, `-`): every value the kit codes and every value a host is likely to add,
while excluding whitespace, quotes and angle brackets.

`cb_kit_icon()` emits **safe HTML**, and that is a statement about the wrapper,
which the kit writes, and about the shipped glyphs, which are kit-authored. A
contributed icon's inner markup is trusted the way a host's own template is: it
comes from their PHP, not from an editor's input.

## The doc command reads the code

`content-blocks-kit:blocks` prints each block's three levers straight from
`describe()`, so the output cannot drift from what `buildForm()` offers. Its JSON
mode feeds the docs generator, flattening each choice field to its ordered value
list plus the default — the `*` marker of the text output, made explicit.

It prefers the **registered** instance over a bare one, because the whole reason
a host runs it after editing config is to find out whether what they wrote took
effect. For the same reason, overridden fields are named explicitly rather than
left to be inferred.

Long choice lists (the icon set) are truncated in the table to stay readable.
