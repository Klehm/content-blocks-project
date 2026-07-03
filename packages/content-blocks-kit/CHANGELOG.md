# Changelog

All notable changes to `klehm/content-blocks-kit` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

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
