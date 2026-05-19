# Changelog

All notable changes to `klehm/content-blocks` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0-alpha.10] - 2026-05-19

### Added

- **Range slider form widget.** `cb_form_theme.html.twig` now defines a `range_widget` block that renders `<input type="range">` with min / max bounds underneath the track and a live numeric readout positioned at the row's top-right (visually aligned with the label). A new `cb-range` Stimulus controller keeps the `<output>` in sync as the user drags, since browsers don't auto-bind `<output>` to `<input type="range">`. The row gets a `cb-form-row--range` modifier (detected via `block_prefixes`, since `type` is only set inside the widget block) so it establishes the positioning context for the absolutely-placed readout. **Action required for upgrading hosts:** add `"@klehm/content-blocks/cb-range"` to `assets/controllers.json`.

### Changed

- **Live-collection cards now stack one per row.** `.cb-form-collection` switched from `repeat(auto-fill, minmax(140px, 1fr))` to a single-column grid — multi-item blocks like gallery / accordion are easier to read at typical sidebar widths than when cards squeeze side-by-side.

## [0.1.0-alpha.9] - 2026-05-19

### Added

- **Live-collection form theme.** `LiveCollectionType` fields rendered through the builder (gallery, accordion, list, info-card…) now lay out as a CSS grid of cards instead of a vertical stack of fieldsets — overriding `live_collection_widget` and `live_collection_entry_row` in the bundled `cb_form_theme.html.twig`. Each entry is wrapped in a `.cb-form-collection__item` card with the delete button rendered as a small absolute "×" in the corner; the add button spans full width with a dashed border. Applies automatically to every block whose form uses a `LiveCollectionType` — no per-block form theme needed.

### Changed

- **Type-picker popover scrolls past 160 px.** `.cb-overlay-popover` now caps at `max-height: 160px` with `overflow: auto`, so the section / block type list stays usable when many types are registered.

### Fixed

- **Sidebar no longer freezes on a stale form when its target element is deleted.** Removing a block or section from the iframe now clears the focused-element sidebar instead of leaving the previously-rendered form attached to a non-existent id.

## [0.1.0-alpha.7] - 2026-05-19

### Added

- **Block horizontal alignment (`styling.alignSelf`).** Blocks gain a `start`/`center`/`end` choice rendered as a three-bar text-align icon in the styling sidebar. Output as the CSS variable `--cb-align-self` on the block wrapper. Only meaningful when the block has a `maxWidth` cap — otherwise the block stretches to fill the column and align-self has no visible effect — so the row is hidden until a `maxWidth` value is entered. Visibility is driven by the new `cb-block-styling-form` Stimulus controller, which listens for `input` / `change` events on `[name$="[maxWidth][value]"]` and toggles the row's `hidden` attribute. **Action required for upgrading hosts:** add `"@klehm/content-blocks/cb-block-styling-form"` to `assets/controllers.json`.
- **`CoreSectionDefaults` provider (mirror of `CoreStylingDefaults` for top-level section settings).** Seeds `maxWidth = 1320` so a freshly-created **Centered** section always presents a sensible cap in the form and renders with that cap when no explicit value is saved. Bound to a new container parameter `content_blocks.section.default_max_width` and shared via a targeted `bind('int $defaultMaxWidth', …)` with `BuiltInSectionDecorator`, `CoreSectionDefaults` and `SectionSettingsType` so the form pre-fill, the placeholder and the rendered fallback all read the same number — overriding the parameter in one place keeps them in lock-step.
- **README section on customizing default values.** Documents the parameter-based override path (`content_blocks.section.default_max_width`) and the provider-based path (`SectionSettingsDefaultsProviderInterface`, `BlockDataDefaultsProviderInterface`), including how the form pre-fill / renderer fallback / default-stripping pipeline fit together.

### Changed

- **Centered sections without an explicit `maxWidth` now fall back to the configured default** (1320 px out of the box) instead of being rendered uncapped. The literal value `0` is preserved as an explicit opt-out, distinct from a missing key.
- **Section sidebar title removed.** `sidebar_section.html.twig` no longer renders the `cb.section.settings.title` heading — the sidebar context is already clear from the focused-section outline, and dropping the redundant title gives the form a few extra pixels of vertical room.
- **Block-form `styling` sub-form now opts into `include_align_self`** (alongside the existing `include_max_width`). Section settings keep the previous flag set untouched.

## [0.1.0-alpha.6] - 2026-05-18

### Changed

- **Sidebar tabs replaced by two stacked groups.** The section-settings and block-edit sidebars no longer hide their fields behind General / Styling tabs — both groups are now rendered in sequence under small uppercase labels (`.cb-sidebar__group-title`) and the sidebar content scrolls when they overflow. The `cb-sidebar-tabs` Stimulus controller is removed (CSS / package manifests / `controllers.json` entries cleaned up). **Action required for upgrading hosts:** drop the `cb-sidebar-tabs` line from `assets/controllers.json`.
- **Section settings: `maxWidth` field hidden unless `widthMode = centered`.** The initial visibility is driven by Twig (`hidden` attribute set server-side based on the form's current `widthMode`) so there's no flash of misplaced field on first paint; `cb-section-settings-form` keeps it in sync with the live radio value via a `change` listener on the form.

### Fixed

- **No-op autosaves and the iframe reloads they triggered are now suppressed.** `cb-autosave` snapshots the form's serialized state (`new URLSearchParams(new FormData(form))`, sorted) at connect and after every save, and skips the trigger when nothing has changed since the last snapshot. A typical edit no longer produces two POSTs (one from the input-debounce, one from the subsequent `focusout` / `change`) followed by two iframe reloads — the second event is deduped away.

## [0.1.0-alpha.5] - 2026-05-18

### Added

- **Permanent sidebar with autosave (Elementor-like layout).** The builder shell now renders the sidebar as a fixed left column instead of a floating panel that animated in/out. The sidebar always shows either an empty-state hint with the three "Add a section" shortcuts, or the editor for the focused block/section. A floating toggle chip on the right edge collapses the column to a 32 px stub when the user wants a wider preview; collapsed state is persisted in `localStorage`.
- **Auto-save for block & section forms (`cb-autosave` Stimulus controller).** Edits are persisted automatically: 250 ms debounce on `input`, immediate save on `change` / `focusout` / `Enter` (single-line inputs). The controller dispatches a synthetic `change` on the focused field before clicking the hidden in-form save trigger so Live Component model bindings flush their value first. Multi-line targets (textarea, contenteditable) keep their native Enter behavior. The companion `cb-block-edit-keys` controller is removed — its keyboard role is folded into `cb-autosave`.
- **`BlockDataDefaults` defaults system (block-side mirror of `SectionSettingsDefaults`).** Hosts can implement `BlockDataDefaultsProviderInterface` (auto-tagged) to seed initial block form data; defaults equal to the saved value are stripped before the decorator pipeline so they don't leak as inline styles. Ships with `CoreBlockStylingDefaults` defaulting `styling.backgroundColor` to `#ffffff` — same compromise as the section-side `CoreStylingDefaults` (white treated as "no override" to work around `<input type="color">`'s lack of an empty state).
- **Outline preservation across iframe reloads.** Autosave-triggered preview reloads no longer drop the blue selection outline. After the iframe's `load` event, `cb-builder` posts `cb:focus:block` / `cb:focus:section` to the overlay, which re-pins the focus on the matching `[data-cb-block-id]` / `[data-cb-section-id]` element.
- **Click-to-edit in the preview.** Clicking a block or section inside the iframe now both pins the outline and opens the corresponding sidebar editor — the dedicated Edit (✎) / Settings (⚙) toolbar buttons are removed in favor of this direct affordance. Drag, move, duplicate and delete remain on the floating toolbar.
- **Empty-state sidebar partial.** New `@ContentBlocks/builder/sidebar_empty.html.twig` renders a hint + three add-section buttons whenever no element is focused; the iframe's `cb-add-section-tray` is unchanged.

### Changed

- **Iframe reload after save is debounced 500 ms.** Autosave can fire several `cb:*:saved` events per second; the shell now coalesces them so the preview only re-renders once after the user pauses. Structural ops (add / delete / move / duplicate) still reload synchronously.
- **Sidebar grid sizing driven by a CSS custom property.** `--cb-sidebar-width` lives on `.cb-shell` and is read by `.cb-shell__main`'s `grid-template-columns`. The resize handle on the sidebar's right edge writes the same property, persisted in `localStorage`.
- **`min-height` setting on sections no longer shadowed in the preview.** `[data-cb-section-id]` in `builder.css` now uses `min-height: var(--cb-min-h, 60px)` so a user-set value wins over the builder's 60 px guide for empty sections.
- **Color picker initial value for blocks defaults to `#ffffff`** (pre-populated through `BlockDataDefaults` so the native `<input type="color">` doesn't surprise users with `#000000` on a fresh block form).

### Removed

- **`horizontalAlign` section styling.** The option was silently inert: the current full-fill column presets (`col-12`, two `col-6`, three `col-4`) sum to 100 % of the row so `justify-content` had no slack to distribute. Removed from `StylingType`, `StylingSectionDecorator`, `styling.css`, the form theme widget, and the EN / FR translations.
- **Sidebar Save / Close buttons.** Save is gone (autosave replaces it); the header X is gone (sidebar is permanent — collapse via the floating chip instead). The launcher's `confirm_close` prompt is also gone since autosave makes the "unsaved changes" scenario impossible.
- **`cb-block-edit-keys` Stimulus controller.** Its Enter-to-save / Escape-to-cancel mapping is folded into `cb-autosave` (Enter → save) and the cancel path no longer applies. **Action required for upgrading hosts:** replace the `cb-block-edit-keys` entry in `assets/controllers.json` with `cb-autosave`.

### Fixed

- **`backgroundColor` on a block emits no inline style when left at the default.** `BlockRenderer::buildBlockList()` now strips default-equal entries via `BlockDataDefaults::withoutDefaults()` before handing data to the decorator pipeline — same treatment sections already had.

## [0.1.0-alpha.4] - 2026-05-18

### Fixed

- **Twig override priority for the `@ContentBlocks` namespace.** The bundle no longer manually registers its `templates/` path under its own Twig namespace — this was duplicating Symfony's `AbstractBundle` auto-detection and inserting the vendor path with higher priority than the host app's `templates/bundles/ContentBlocksBundle/` override directory, effectively disabling the standard override mechanism. Override directories now work as documented.

### Changed

- **Refactor: extract section / column / block render into dedicated templates for granular overrides.** `@ContentBlocks/render/content_area.html.twig` no longer renders sections, columns and blocks inline — each level now lives in its own template and is included from the parent with `with_context = false`. Markup, CSS classes and `data-cb-*` attributes are unchanged.
  - New override points exposed under `templates/bundles/ContentBlocksBundle/render/` in the host app:
    - `section.html.twig` — receives `section: Section`, `isPreview: bool`
    - `column.html.twig` — receives `column: Column`, `isPreview: bool`
    - `block.html.twig` — receives `block: Block`, `isPreview: bool`
  - **Breaking for forks of `content_area.html.twig`:** if your host app previously copied the entry-point template to customise a sub-level (a `<section>`, a `<div class="cb-col">`, etc.), re-target your override to the new dedicated template rather than maintaining a full copy of `content_area.html.twig`.

## [0.1.0-alpha.2] - 2026-05-13

Initial alpha. See `git log` for the per-commit history prior to this changelog.
