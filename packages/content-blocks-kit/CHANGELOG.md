# Changelog

All notable changes to `klehm/content-blocks-kit` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **A subclassed block now receives its configuration.** `options`, `choices`
  and `defaults` were wired while the bundle registered its own block services —
  which meant they reached every block *except* the ones a host had extended,
  since extending one requires switching the kit's service off (two services
  cannot claim a single type id). The subclass came up autowired with the
  constructor defaults, and its whole YAML applied to nothing. Nothing said so:
  the config was valid, it just had no addressee. A compiler pass now hands the
  config to anything tagged as a block type whose class extends
  `AbstractKitBlock`, keyed by `getType()` — the same identity the config is
  keyed by. Whatever was set explicitly (the bundle's own registrations, a host
  wiring a block by hand) keeps the last word.

  This is what makes the RC2 `choices` map useful where it matters: adding a
  *field* still needs a subclass, and until now subclassing cancelled the
  configuration. A subclass that renames its type is configured under the new
  id, which falls out of keying on `getType()` rather than on the parent class.
- **`table` no longer starts on an alignment it refuses.** `defaults()` still
  returned `align: left` and `align: right` after the RC1 rename to
  `start`/`end` went through `TableColumnType` and the template: a new table
  opened with two columns its own `<select>` could not represent, the second
  rendering left while announcing right. Every block's coded defaults are now
  confronted with its own form's choice fields — collections walked through
  their `entry_type` — so the whole family of drifts fails a test instead of
  waiting to be seen.

## [1.0.0-RC2] - 2026-08-14

### Added

- **`choices` can now replace a block's choice set, not just narrow it.** The
  option reads its value in one of two shapes, told apart by whether you wrote a
  list or a map — no flag to set:

  ```yaml
  content_blocks_kit:
      blocks:
          button:
              choices:
                  size: [md, lg]                                  # list → restrict, as before
                  variant: { ghost: 'Ghost', primary: 'Primaire' } # map  → replace, and add
  ```

  Labels go through the field's translation domain, so a translation key is
  resolved and a literal string comes out as written. Validation accepts the
  **union** of the coded values and the configured ones: a value you added
  passes its own form, and content saved with a value you have since hidden
  stays valid.

  Restricting behaved this way already; what did not work — and failed silently,
  by falling back to the full coded set — was trying to *add* through the list
  form. That fallback is kept (an empty `<select>` is worse than an unfiltered
  one) but is now documented as the signal that you wanted the map form.

- **`AbstractKitBlock::describeConfigured()`**, the as-built counterpart of
  `describe()`. `content-blocks-kit:blocks` reads it for its human output, so
  the command now shows the block as *this app* configured it and names the
  fields that were overridden. The `--format=json` half stays on the coded
  schema, since it feeds the reference pages that describe the kit as shipped.

- **`cb_kit_token()`** Twig function, used by the kit's views wherever a choice
  value becomes a CSS class.

- **`IconProviderInterface` + `IconRegistry`** — icons are now contributable.
  An implementation returns `name => inner SVG markup` and is autoconfigured, so
  declaring the service is the whole opt-in; the glyph appears in the `icon`
  block's picker with no config at all, and reusing a shipped name replaces that
  glyph. The kit's wrapper (viewBox, sizing, `currentColor`) stays in charge, so
  a contributed icon looks like one of the family.

  This exists because `icon.name` was the one field where widening `choices`
  would have made things *worse*: a name with no glyph made `cb_kit_icon()`
  return nothing, the view's `{% if %}` fail, and the block render as literally
  no markup. Names and glyphs have to arrive together, which config alone cannot
  do. The view also falls back to a real glyph now, so stored data naming an
  icon that has since gone still renders something.

- **`asset:<path>` in the rich-text editor options.** A versioned asset's URL
  carries a digest — `/assets/styles/wysiwyg-8f3a2c.css` — that no static YAML
  file can spell and that changes on every build, and `asset()` only exists in
  Twig. Any string in `options` may now be written `asset:<path>` and is
  resolved through the host's asset packages, at any depth: `script_url`,
  `style_url`, and anything inside `config` — `content_css` above all, since an
  editing surface is supposed to look like the published page. A value without
  the prefix is untouched; an `asset:` value with no asset packages configured
  raises rather than emitting a URL that would 404 inside the editor chrome.
  Needs `symfony/asset` — AssetMapper does not pull it in on its own.
- **`options.script_url` / `options.style_url`**, replacing `cdn_url` /
  `cdn_style_url`, which read as "another CDN" while their whole point was
  self-hosting. The old names still work, and lose to the new ones when both
  are set.
- **A `cb-rich-text:configure` event**, fired on the field wrapper by both
  editor controllers once the config is merged and before the editor is
  created. `detail.config` is the live object, so a host adds what JSON cannot
  carry — a `setup` callback and the custom buttons it registers, a plugin
  instance, a stylesheet list only a bundler knows — from its own admin entry,
  with no controller to fork and no editor adapter to write. The two existing
  protections still apply after it: the autosave write-back runs before a
  host's `setup`, and the upload adapter is appended after a replaced plugin
  list.

### Changed

- **Kit views no longer re-list their coded choice values.** Seven templates
  each carried an inline whitelist (`variant in ['primary', 'secondary',
  'outline', 'link'] ? variant : 'primary'`) that had to be kept in step with
  `choiceFields()` by hand — and that quietly swapped a configured value back
  for the coded default, making the config above pointless. They now pass the
  stored value through `cb_kit_token()`, which checks its *shape* (a single
  `[A-Za-z0-9_-]` token) rather than its membership in a list. A malformed value
  still falls back; an unknown-but-valid one renders, which is what gives the
  host's CSS something to hook onto.

  Two deliberate exceptions:

  - **`title.tag` stays closed.** It becomes the HTML element, so widening it
    would let configuration decide what markup the kit emits. Adding a tag means
    overriding that template.
  - **Values that drive behaviour land on a fallback branch** — the `gallery`
    slider carries a Stimulus controller, `list` renders `<ol>` only for
    `numbered`, `alert` glyphs come from the kit's own icon set. They keep their
    class, so they are still stylable, but going further needs a view override.

- **`image` sizes now carry a class**, `cb-kit-image--size-<value>`, alongside
  the alignment one. Additive for the coded sizes; the point is that a size
  added through config maps to no preset pixel width, so without a class it
  reached the markup as nothing at all — offered in the picker, invisible on the
  page. Every one of the kit's 18 choice fields is now covered by a test that
  renders an added value, so the reference table in the docs cannot drift.

- **A block whose default is no longer offered starts on the first value that
  is.** Previously a host who replaced `variant` without also setting
  `defaults.variant` got every new button on the kit's coded default — a value
  absent from their own dropdown and unstyled on the page. This only moves a
  default that the resolved choice set does not contain, so a block whose config
  still includes its default is untouched.

### Fixed

- **BREAKING (markup) — the `tabs` block no longer inlines its own CSS.** It was
  the one block that shipped a `<style>` tag per instance, with hardcoded colors
  and an active-tab rule keyed on the instance's random id — specificity (1,4,0)
  under a name a host could not even predict, so retheming it meant
  `!important`. The styling moved into `kit.css` next to every other block, and
  the markup became `radio → label → panel` repeated inside a wrapping flex row,
  which reduces the whole behaviour to two static rules
  (`:checked + label`, `:checked + label + panel`) with class-only specificity.
  What changes for a host: the classes are now `cb-kit-tabs`, `cb-kit-tabs__tab`
  and `cb-kit-tabs__panel` (they were `cb-block-tabs*`), the `cb-block-tabs__nav`
  and `cb-block-tabs__panels` wrappers are gone, and the block reads the
  `--cb-kit-tabs-*` tokens documented with the rest. Keyboard navigation
  improves on the way through: the radios stay focusable, so a tab set is
  operable with the arrow keys and the focus ring is drawn on the label.

## [1.0.0-RC1] - 2026-08-13

The first release candidate for 1.0. The public surface is frozen as
described in the [backward compatibility](https://klehm.github.io/content-blocks-project/guide/backward-compatibility)
page: what is listed there is stable until 2.0, and anything marked
`@internal` is outside the promise. Report anything this candidate
breaks before the stable tag.

### Changed

- **Subclassing a kit block is now a documented contract, not a happy accident.**
  The 17 block classes are non-final on purpose: when a host needs one extra
  field, a different default or a narrower choice set, the supported route is to
  disable the kit's service (`blocks.<type>.enabled: false`), subclass the block
  and keep its `getType()`. `AbstractKitBlock`'s `choiceFields()`, `defaults()`
  and `describe()` are covered by semver as extension points. Note
  `getDefaultData()` is `final` — it merges `defaults()` with the host's
  `blocks.<type>.defaults` config, so new keys go in `defaults()`.
- **The kit's seven `--cb-kit-*` content tokens are documented** with their
  defaults and the zero-specificity `:where()` scope that makes them overridable
  from a host stylesheet without `!important`. They style the published page and
  are unrelated to the `--cb-*` tokens that theme the builder chrome.
- **BREAKING — `Block.data` key unification (road to v1.0).** The same concept
  was stored under different keys across blocks; they are reconciled here so the
  persisted schema is a coherent public contract. Renames:
  - link / URL is now **`url`** everywhere: `image.link`, `gallery` item `link`,
    `button.href`, `card` item `buttonUrl` → `url`.
  - `card` item `buttonLabel` → **`buttonText`**.
  - `alert.message` → **`content`**.
  - `tabs.tabs` (collection wrapper) → **`items`** (consistent with every other
    collection block).
  - `table` column `align` values `left`/`right` → **`start`**/**`end`** (matching
    the `start/center/end` vocabulary used by image/button/icon; `center` unchanged).

  Existing rows must be migrated — see the reference Doctrine migration
  `Version20260715120000` in both sandboxes (decode → rename → encode, reversible,
  handles nested collection items). Host `content_blocks_kit.blocks.<type>.defaults`
  / `choices` config keyed by an old field name must be updated to the new name.

  Accepted (documented) exceptions, not renamed: `title` block keeps `text` for its
  heading content (composite blocks use `title` for a sub-heading — different role);
  `icon.size` stays an integer (px) while `size` elsewhere is an enum; `src`/`fit`
  keep their conventional HTML/CSS spellings.

### Added

- **Every image the kit renders goes through the core's
  `ImageUrlResolverInterface`.** `image`, `gallery` items and `card` media now
  resolve their source through `cb_image()` instead of printing it raw, so a
  host that aliases the interface (CDN, LiipImagine…) gets `srcset`/`sizes` in
  all three without overriding a template. The `image` block passes the display
  width it already computed — the preset (sm=400, md=800, lg=1200) or the custom
  width, plus the pinned height when there is one; the fluid views pass none and
  leave `sizes` to the resolver.

  With no resolver wired the default is passthrough, so **the rendered markup is
  unchanged** — no `srcset`, no `sizes`, the stored source as-is. The kit gains
  no dependency: the seam and its default live in `klehm/content-blocks`.
- **`rich_text` picks its editor: TinyMCE (default) or CKEditor 5.** One block,
  a pluggable adapter — not one block per editor. Whichever editor runs, the
  block stores the same `{ content: "<html>" }`, so the editor is a display-time
  choice and switching it is a config change rather than a data migration.
  Encoding a vendor name in `cb_block.type` would have made it one, and would
  have asked the person writing the page to answer a question that belongs to
  whoever installed the site.

  ```yaml
  content_blocks_kit:
      blocks:
          rich_text:
              options:
                  editor: ckeditor   # tinymce (default) | ckeditor | a host's own
                  cdn: true          # false → the host bundles the editor itself
                  cdn_url: null      # or point at a self-hosted build
                  uploads: true      # image button wired to the builder endpoint
                  config: {}         # merged over the coded init config, host wins
  ```

  Existing installs are unaffected: the defaults are the behaviour that shipped
  before, down to the same TinyMCE CDN URL.

  - **Images go through the builder's own endpoint.** Both editors upload to
    `/_content-blocks/upload` with the builder's CSRF token, so a picture pasted
    into rich text lands in the same storage, under the same size cap and MIME
    allow-list, as one added through the `image` block. TinyMCE gets an
    `images_upload_handler` plus a file picker; CKEditor gets a custom upload
    adapter mapping the endpoint's `{ url }` onto the `{ default }` it expects.
  - **Loading the editor is the host's call.** CDN by default so a fresh install
    works with nothing to build; `cdn_url` to self-host the same build under a
    strict CSP; `cdn: false` to bundle it and let the kit load nothing. If the
    global is missing, the block says so precisely in the console and leaves the
    plain textarea holding the HTML — the content stays editable either way.
  - **`options.config` merges over the coded init config** in the browser:
    objects key by key, arrays and scalars replaced. Two things survive the
    merge on purpose, because losing them costs data rather than looks — the
    write-back that keeps autosave in sync (a host `setup` callback runs after
    it, not instead of it), and the upload adapter, which outlives a replaced
    plugin list.
  - **A third editor is a service away.** `RichTextEditorInterface`
    (autoconfigured, resolved through `RichTextEditorRegistry`) plus a Stimulus
    controller makes Quill, Trix or an in-house editor selectable as
    `options.editor: <name>`; `AbstractRichTextEditor` hands over the shared
    option handling. The form theme renders whatever controller and values an
    adapter names, so it never learns any editor's name.

  Hosts selecting CKEditor must enable the new `cb-ckeditor` controller in
  `assets/controllers.json` (Flex writes it on install).

- **Translatable fields declared across the block set.** 29 fields are tagged
  `cb_translatable` (the core's convention): prose — headings, body copy, item
  labels, alt text, captions — and link targets, since a localized site
  routinely points at `/fr/contact`. Enums, colors, sizes and uploaded assets
  are deliberately left untagged: they are the same in every language, and
  swapping an image per locale is an asset decision rather than a translation.

  Purely declarative. The kit gains no behaviour from it and no rendered output
  changes; it means a satellite translation package finds an annotated field set
  on day one instead of 17 blocks nobody marked up.

## [0.1.0-beta.7] - 2026-07-10

### Added

- **Twelve new self-contained blocks:** `button`, `gallery` (grid + slider),
  `card` (grid + list), `list` (bullet/check/numbered), `icon` (shipped icon
  set), `alert`, `divider`, `accordion` (native `<details>`, zero JS), `table`,
  `embed` (YouTube/Vimeo via `cb_embed_url()`), `breadcrumb`, `html_raw`. All
  render neutral `cb-kit-*` markup — no Tailwind/Bootstrap/LiipImagine/icon
  library — styled by a single shipped stylesheet served at the public route
  `content_blocks_kit_asset_css` (include it once in the host layout).
- **`image` gains sizing controls:** size preset (small/medium/large/full) or a
  custom width + optional exact height with object-fit, plus alignment, link,
  caption and per-corner radius. Custom fields reveal via the core
  `cb-condition` controller. Still a plain `<img>` (no image-processing dep).
- **Host-configurable per-block surface:** `content_blocks_kit.blocks.<type>.{enabled,
  options, choices, defaults}`. `enabled:false` un-registers the service (never in
  the picker); `options` are block-level knobs (e.g. `gallery`/`card` `max_columns`);
  `choices` is a per-field allow-list that restricts/reorders a `ChoiceType`
  (falls back to the full set if empty/all-invalid, and validation keeps the full
  coded superset so restricting the picker never invalidates stored data);
  `defaults` overrides a block's initial field values. `AbstractKitBlock` declares
  the coded schema once (`choiceFields()` / `defaults()`), consumed in `buildForm()`
  via `choices()` / `choiceConstraint()`; gating + merge live in the pure,
  unit-tested `resolveBlocks()`.
- **`content-blocks-kit:blocks [type]` command** documents every block's options,
  choice fields (default marked) and data defaults, read from `describe()` so it
  never drifts from the code.
- **`html_raw` is disabled by default** (`ContentBlocksKitBundle::DEFAULT_DISABLED`).
  It renders `{{ html|raw }}` and so trusts its editors — opt in explicitly with
  `content_blocks_kit.blocks.html_raw.enabled: true`.
- **`title` and `text` gain a palette text color** (core `PaletteColorType`), the
  same named colors as icon/divider and the TinyMCE swatches. `title` also gets a
  **visual size decoupled from the semantic tag** — a `<h2>` can render at h1 size.
- **`IconSet`** — ~24 inline SVG icons shipped with the kit (`cb_kit_icon()`),
  and **`EmbedExtension`** (`cb_embed_url()`, YouTube/Vimeo).
- Kit now has its own PHPUnit + Vitest setup (path repository to the sibling
  core so it tests against the monorepo's current core), gating the split.

### Fixed

- **The bundle could not boot on a Webpack Encore host.** `prependExtension()`
  registered `assets/` under `framework.asset_mapper.paths` unconditionally, which
  *enables* a component the kit never required — `FrameworkExtension` then threw at
  container build. Now guarded by `class_exists()`, same as the core bundle. Encore
  hosts link the kit's assets as a local npm package
  (`@klehm/content-blocks-kit@file:vendor/klehm/content-blocks-kit/assets`) and read
  `cb-tinymce` / `cb-gallery` from `assets/package.json` through
  `@symfony/stimulus-bridge`.

### Changed

- **TinyMCE bridge hardened** (`cb-tinymce`): re-parents the aux popup/modal
  container into the builder `<dialog>` (top layer), keeps `data-live-ignore` +
  `editor.save()`-before-bubble autosave sync, adds a drag-resize status bar,
  and seeds the color swatches from the ContentBlocks palette (via the core
  `cb_color_palette()` Twig function), falling back to a standard web palette.
- Color fields on kit blocks use the core `PaletteColorType` (palette dropdown
  + custom picker), storing a plain `#hex`.
- **Upload brick moved to the main package.** `ContentBlocks\Kit\Storage\*` (FileStorageInterface, LocalFileStorage, NullFileStorage), the kit `UploadController`, `FileStorageAssetResolver` and the `cb-file-upload` Stimulus controller now live in `klehm/content-blocks` (`ContentBlocks\Storage\*`, `ContentBlocks\Controller\UploadController`, `ContentBlocks\Asset\FileStorageAssetResolver`). Update your service wiring to the new namespaces — or drop it entirely in favor of the `content_blocks.upload` bundle config — and move the `cb-file-upload` entry in `assets/controllers.json` from `@klehm/content-blocks-kit` to `@klehm/content-blocks`. The kit's `config/routes.php` is kept as a no-op so existing route imports don't break.
- **`ImageBlock` uses the core `ImageUploadType`.** The hidden `src` field + `image_theme.html.twig` form-theme override are replaced by the main package's upload widget; the kit no longer ships a form theme for the image block.

## [0.1.0-alpha.12] - 2026-06-08

### Changed

- **Kit blocks opt into preview hot reload.** `TextBlock`, `TitleBlock`, `ImageBlock`, `RichTextBlock` and `TabsBlock` now return `true` from `supportsPreviewHotReload()` — their views are static or CSS-only, so the builder refreshes them in place after an edit instead of reloading the whole preview iframe. The upload widget and TinyMCE run in the edit form, never in the rendered view, so `image` / `rich_text` qualify too. Requires `klehm/content-blocks >= 0.1.0-alpha.12`.

## [0.1.0-alpha.11] - 2026-05-19

### Added

- **`FileStorageInterface` gains `isStoredPath`, `read`, and `uploadFromString` methods.** Powers the new JSON Import / Export flow in `klehm/content-blocks` — the exporter detects asset references inside block data, embeds them as base64, and the importer re-materializes them on the host's storage. `LocalFileStorage` and `NullFileStorage` ship with implementations.
- **`FileStorageAssetResolver`.** A bridge that adapts `FileStorageInterface` to the new `ContentBlocks\Asset\AssetResolverInterface` in the main package, auto-aliased so import / export works out of the box for any host that already wires up file uploads.

### ⚠ Breaking changes

- Custom implementations of `FileStorageInterface` must add `isStoredPath`, `read`, and `uploadFromString`. Return `false` / `null` / throw a `LogicException` respectively if your backend does not support these — the main package degrades gracefully (export will skip assets, import will refuse base64 payloads loudly).

## [0.1.0-alpha.10] - 2026-05-19

### Added

- **ImageBlock: width and height range sliders.** Two new `RangeType` fields (0–1200 px, step 10) sit under "Alternative text" in the block sidebar. The view template applies them as HTML `width` / `height` attributes and an inline `style` when non-zero, falling back to the image's natural size when both are 0. Sliders use the companion `klehm/content-blocks` range widget — bounds visible underneath the track, live numeric readout next to the label.

## [0.1.0-alpha.9] - 2026-05-19

Version bump only — no functional changes in `klehm/content-blocks-kit`. The companion `klehm/content-blocks` package adds a cards-grid form theme for `LiveCollectionType` fields (so multi-item blocks like gallery / accordion render as a grid rather than a stacked fieldset), makes the type-picker popover scroll past 160 px, and fixes the sidebar staying attached to a deleted element. See the `content-blocks` CHANGELOG for details.

## [0.1.0-alpha.7] - 2026-05-19

Version bump only — no functional changes in `klehm/content-blocks-kit`. The companion `klehm/content-blocks` package adds a horizontal-align option for blocks (revealed when a `maxWidth` is set), wires a configurable section `maxWidth` default (1320 px, exposed as `content_blocks.section.default_max_width`), and drops the redundant section-sidebar title. See the `content-blocks` CHANGELOG for the upgrade notes — including the new `cb-block-styling-form` controller to register in `assets/controllers.json`.

## [0.1.0-alpha.6] - 2026-05-18

Version bump only — no functional changes in `klehm/content-blocks-kit`. The companion `klehm/content-blocks` package replaces the sidebar tabs with two always-visible groups, dedupes no-op autosaves (no more redundant iframe reloads), and conditionally hides the section `maxWidth` field. See the `content-blocks` CHANGELOG for the upgrade notes.

## [0.1.0-alpha.5] - 2026-05-18

Version bump only — no functional changes in `klehm/content-blocks-kit`. The companion `klehm/content-blocks` package ships a substantial builder UI refactor (permanent sidebar, autosave, removal of the `horizontalAlign` styling option, outline preservation across iframe reloads). Tags are kept in sync across the two packages for monorepo coherence; see the `content-blocks` CHANGELOG for the upgrade notes.

## [0.1.0-alpha.4] - 2026-05-18

### Fixed

- **Twig override priority for the `@ContentBlocksKit` namespace.** The bundle no longer manually registers its `templates/` path under its own Twig namespace — this was duplicating Symfony's `AbstractBundle` auto-detection and inserting the vendor path with higher priority than the host app's `templates/bundles/ContentBlocksKitBundle/` override directory, effectively disabling the standard override mechanism. Override directories now work as documented.
