# Changelog

All notable changes to `klehm/content-blocks-kit` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-03

### Changed

- **BREAKING — `Block.data` key unification (road to v1.0).** The same concept
  was stored under different keys across blocks; the stable release reconciles
  them so the persisted schema is a coherent public contract. Renames:
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
