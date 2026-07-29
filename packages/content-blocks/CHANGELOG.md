# Changelog

All notable changes to `klehm/content-blocks` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **BREAKING — styling viewport keys `d`/`t`/`m` → `desktop`/`tablet`/`mobile`.**
  The responsive styling sub-tree (`styling.padding`/`margin`/`gap`) now spells out
  the viewport keys in stored data (section `draft_settings`/`published_settings`
  and block `draft_data`/`published_data`). The emitted CSS custom-property names
  stay terse (`--cb-s-pad-d-t`, `--cb-gap-d`) — the decorators map long→short — so
  `styling.css` and any host CSS override are unaffected. Migrate existing rows with
  the reference migration `Version20260715130000` (both sandboxes; reversible).
- **BREAKING — config key `styles` → `section_styles`.** The section-style-presets
  config key now matches the emitted parameter (`content_blocks.section_styles`);
  host YAML under `content_blocks.styles:` must be renamed to `section_styles:`.
- **BREAKING — `ContentBlocks\Service\` is gone.** The six services that lived in
  that catch-all namespace moved next to the rest of their domain, where every
  other extension point already sat: `ContentAreaPublisher` → `ContentBlocks\Publishing\`,
  `SectionCloner` → `ContentBlocks\Section\`, `ContentAreaExporter`/`ContentAreaImporter`
  → `ContentBlocks\Transfer\`, `SectionTemplateSerializer`/`SectionTemplateInstantiator`
  → `ContentBlocks\SectionTemplate\` (joining `InstantiationResult`,
  `IncompatibleTemplateException` and `SectionTemplateManagerInterface`, which were
  already there). Class names, methods and behaviour are unchanged — a
  find-and-replace of `ContentBlocks\Service\` suffices, and a missed one fails
  loudly at container build. The matching `…Interface` classes follow the same
  mapping.
- **BREAKING — `ContentAreaImporterInterface::import()` returns an `ImportResult`,
  `SectionTemplateSerializerInterface::serialize()` a `SectionTemplateSnapshot`.**
  Both used to return bare arrays/ints. `$snapshot->payload` / `->blockTypes`
  replace `$serialized['payload']` / `['blockTypes']`; `$result->sectionCount`
  replaces the returned `int`. Only relevant if you call these services directly.
- **Both restore flows (area import, section-template insert) are now optimistic:
  they bring in everything this installation can use and report the rest.**
  A block whose type is not registered here is **skipped** — importing it would
  hand the editor an inert placeholder (no view template, no edit form), and
  nothing is lost since the JSON file / stored payload remains the archive:
  install the block type and re-import. A stored key no registered type declares
  is **kept** and merely reported — the block itself is usable, and the key may
  well be a field about to be added. In short, "compatible" is judged per block,
  not per key. Previously the import accepted any block type silently (producing
  unrenderable blocks) while the template insert refused the whole operation.
  The only hard stop left on content is a template that had blocks and kept none.
  Both flows report through their result object (`ImportResult`,
  `InstantiationResult`), which now share their vocabulary: `skippedBlockCount`,
  `skippedBlockTypes`, `unknownFields`.
- **The section-template picker warns instead of blocking when a template is
  partially usable.** A row is disabled only when nothing would come in (an
  unreadable payload envelope, or every one of its block types gone); otherwise
  it stays clickable and its tooltip spells out how many blocks will be skipped
  and of which types. The list response fields changed accordingly:
  `compatible`/`missingTypes` → `insertable`/`skippedTypes`.
- **Section-template payloads are validated against the envelope format they
  declare.** `SectionTemplateSerializer::FORMAT` was written into every stored
  payload since the feature shipped but never read back, so a payload written
  under an older structure would have been replayed blind. Such a template is now
  shown as unavailable in the library picker, and the instantiator refuses it with
  a dedicated `UnsupportedTemplateFormatException`
  — separate from `IncompatibleTemplateException`, whose `getMissingTypes()`
  would be empty and read as "nothing is missing". The format versions the
  payload *structure*, which the core owns; it says nothing about the shape of
  the block data inside, which belongs to the block types.
- **`section_styles[].settings` is now a typed config node** (was a free-form
  `variableNode`). Preset YAML is validated and self-documenting; the shape mirrors
  `SectionSettingsType`/`StylingType`. Unknown keys and bad viewport/align/unit
  values now fail at container build instead of silently persisting. Host presets
  using the old `d`/`t`/`m` viewport keys must be updated to `desktop`/`tablet`/`mobile`.

### Added

- **Host-owned content versioning (`content_blocks.content_version`).** The shape
  of stored block data is decided by the block types — the host's and the kit's —
  not by this package, so the schema generation of that content is now a host
  config value (int, default `1`). It is stamped onto `cb_content_area.content_version`
  as content is written (by the same onFlush listener that touches `updatedAt`),
  and onto `cb_section_template.content_version` when a snapshot is saved, so a
  host migration can target what predates a change of its own making:
  `WHERE content_version < N OR content_version IS NULL`.
  Read the area column as *"last written under version N"*, not *"conforms to
  version N"*: editing one block re-stamps the whole area while its other blocks
  keep whatever shape they had, so migrate before letting editors work on a new
  version. A section template carries no such caveat — a snapshot is frozen.
  `NULL` means "predates versioning" and is deliberately distinct from `0`.
  Export payloads carry the emitting app's `contentVersion` for information only;
  import ignores it and stamps the target with the local version, since a version
  number means something only inside the installation that issued it. Reference
  migration `Version20260729120000` in both sandboxes.
- **`ContentVersionUpgraderInterface` — what to do with content from an older
  schema generation.** Section templates are the one place where a stored version
  is comparable (the number came from this same installation), so that is where
  the seam applies: `supports(?int $stored, int $current)` is called once per row
  when listing the library, so the picker greys out what the host refuses instead
  of letting an editor click into an error, and `upgrade(array $payload, …)` runs
  on the way in. Upgrading is **transient** — what the host returns is
  instantiated, the stored row is untouched; a permanent rewrite is a migration.
  The shipped `DenyOnMismatchUpgrader` refuses a *known* mismatch and accepts
  `null`: every row written before versioning carries null, and refusing those
  would make a host's whole library unusable the day they upgrade. Import does
  not consult the seam — an imported payload's version belongs to the app that
  exported it; hosts controlling both ends can decorate
  `ContentAreaImporterInterface` instead.
- **`EnvelopeUpgraderInterface` + `EnvelopeUpgradeChain` — the package's own side
  of versioning.** A stored payload has two schemas with two owners: the content
  inside it is shaped by the block types (host territory, see above), the envelope
  around it belongs to this package. When a release changes that structure it can
  now ship an upgrade step alongside, and the chain walks a payload from the
  format it declares to the one the code reads — so old section templates and old
  export files keep working across a format bump. Both restore flows go through
  it, and the section-template picker treats "readable" as "reachable through the
  chain" rather than "exactly today's format".
  **The chain ships empty**: only one format of each kind exists so far, so every
  call is a no-op today. It exists now because the alternative — a bump condemning
  every stored payload — is what makes a bump unthinkable in the first place.
  Steps are autoconfigured (`content_blocks.envelope_upgrader`), so adding one is
  a class and nothing else; a test asserts exactly that.
- **`ContentVersionUpgraderInterface` — what to do with content from an older
  schema generation.** Section templates are the one place where a stored version
  is comparable (the number came from this same installation), so that is where
  the seam applies: `supports(?int $stored, int $current)` is called once per row
  when listing the library, so the picker greys out what the host refuses instead
  of letting an editor click into an error, and `upgrade(array $payload, …)` runs
  on the way in. Upgrading is **transient** — what the host returns is
  instantiated, the stored row is untouched; a permanent rewrite is a migration.
  The shipped `DenyOnMismatchUpgrader` refuses a *known* mismatch and accepts
  `null`: every row written before versioning carries null, and refusing those
  would make a host's whole library unusable the day they upgrade. Import does
  not consult the seam — an imported payload's version belongs to the app that
  exported it; hosts controlling both ends can decorate
  `ContentAreaImporterInterface` instead.
- **Per-block form extension API.** Hosts can now add fields to the edit form of
  one (or several) block types without subclassing. Implement
  `ContentBlocks\Form\Extension\BlockFormExtensionInterface` (`buildForm($builder, $data, $blockType)`)
  and tag it with `#[AsBlockFormExtension('button')]` (one type),
  `#[AsBlockFormExtension(['button', 'card'])]` (several) or `#[AsBlockFormExtension]`
  (global, every block). `BlockFormType` invokes every matching extension after the
  block's own `buildForm()`, in `priority` order. Keyed by block type **id** (string,
  not class) so it survives subclassing and matches the config keys. This replaces the
  `FormTypeExtension` + `instanceof`-guard workaround, which could not be scoped to one
  block. The added field's value round-trips into `Block.data` like any other (block
  data is not pruned); render it via a host block-template override. Autoconfigured, no
  wiring. The seam is not add-only: the builder is the block's own, so an extension may
  also `remove()` a field (its stored value is frozen, not deleted, and a POST still
  carrying it is dropped) or reorder fields by re-adding their child builders. See the
  [Add a field to a block](https://klehm.github.io/content-blocks-project/guide/recipes/add-block-field) recipe.
- **`BlockRendererInterface` — rendering override seam (road to v1.0).** The
  central `BlockRenderer` now implements a `BlockRendererInterface` (`render`,
  `resolveMode`, `renderBlock`, `renderSection`, and the `QUERY_PARAM` constant),
  aliased to the shipped implementation. Consumers (the Twig extension and the
  render controllers) type-hint the interface, so a host can now customize
  rendering — wrap output, add caching, swap the preview-mode heuristic — by
  decorating it (`#[AsDecorator(BlockRendererInterface::class)]`) or re-aliasing.
  Backward-compatible: the concrete `BlockRenderer` service id still resolves.
- **Interfaces for the six remaining core services (road to v1.0).** Following
  `BlockRendererInterface`, the services that carry the rest of the builder's core
  behaviour are now each an interface aliased to the shipped implementation, with
  every consumer type-hinting the interface: `ContentAreaPublisherInterface`,
  `SectionClonerInterface`, `ContentAreaExporterInterface`,
  `ContentAreaImporterInterface`, `SectionTemplateSerializerInterface` and
  `SectionTemplateInstantiatorInterface`. Hosts can now replace or decorate any of
  them (`#[AsDecorator(...)]`) instead of being stuck with a `final` class injected
  by concrete type. The payload-format constants moved onto the interfaces
  (`ContentAreaExporterInterface::FORMAT`, `SectionTemplateSerializerInterface::FORMAT`)
  and the importer now validates against the interface, so swapping the exporter no
  longer leaves the importer checking the shipped class. Backward-compatible: the
  concrete service ids still resolve, and `ContentAreaExporter::FORMAT` still reads
  the same value through inheritance.

### Fixed

- **Section-template insert warned about fields that were perfectly valid.**
  `SectionTemplateInstantiator` decided which stored keys a block could hold from
  `getDefaultData()` alone, so it flagged `styling` (added to every block form by
  `BlockFormType`, deliberately absent from `getDefaultData()`) and every field
  contributed by a host `BlockFormExtension`. Known keys are now the union of
  `getDefaultData()` and the children of the block's built edit form.
- **…and the warning never reached anyone anyway.** The builder wrote the message
  into the template picker's status line and then closed the picker, blanking the
  only element carrying it. The picker now stays open when there is something to
  report.

## [0.1.0-beta.7] - 2026-07-10

### Added

- **Semantic bundle configuration.** `ContentBlocksBundle` now exposes a config tree (`content_blocks:`) covering `section.default_width_mode` / `section.default_max_width` (previously parameter-only, still overridable via parameters), `palette` (named colors), `styles` (section style presets) and `upload` (dir, public prefix, max size, MIME allow-list). Config is the declarative shortcut; the provider interfaces remain the power-user path and both sources merge.
- **`PaletteColorType` — palette + custom color picker.** A dropdown of named project colors plus a "Custom…" option revealing a free color picker, storing a single `#hex` string (`''` = none). It replaces the raw `ColorType` for `backgroundColor` in `StylingType` (sections *and* blocks). Palette colors come from the `content_blocks.palette` config and/or `ColorPaletteProviderInterface` implementations (autoconfigured, aggregated by `ColorPaletteRegistry`). With no palette declared the dropdown still offers None / Custom…, giving the field a real empty state. Option `allow_custom: false` locks editors to the palette. The None / Custom… labels are pinned to the core `content_blocks` translation domain, so they resolve even when a host/kit block sets the field's `translation_domain` to its own catalog (otherwise they rendered as raw keys).
- **Section style presets can carry settings values.** `SectionStyle` gains an optional `settings` array (same shape as `draft_settings`). At render time the selected preset's settings apply *underneath* the section's own settings (`BlockRenderer`), the user's explicit values winning key-by-key; the sidebar prefills the styling fields from the preset. Presets can be declared in config (`content_blocks.styles`) or via `SectionStyleProviderInterface` (whose `cssClass` is now optional — settings-only presets are valid).
- **"Customize styling" switch on sections (progressive disclosure).** The section sidebar's styling fields now hide behind a `stylingCustom` switch: everyday editors only see the preset dropdown; flipping the switch reveals the full fields, prefilled from the preset. While off, the styling subtree is dropped on save so a later preset change never fights stale hidden values. Sections saved before the switch existed (styling values, no flag) are treated as customized.
- **`cb-condition` Stimulus controller.** Generic conditional-field visibility: tag any row with `data-cb-condition="field:value1|value2"` inside a `data-controller="cb-condition"` scope (checkboxes map to `true`/`false`; `field` alone means "non-empty"; instances nest, nearest scope wins). Clauses combine with **AND** via `;` (e.g. `size:custom;customHeightAuto:false`), each still **OR**-ing its values with `|`. The controller is also attached to the block edit-form root, so a `<select>` can gate sibling rows (e.g. the image size/width/height reveal). Drives the styling switch and the palette's custom picker; reusable in host block forms.
- **Upload brick moved into the core (from the kit).** `FileStorageInterface`, `LocalFileStorage`, `NullFileStorage` (now `ContentBlocks\Storage\*`), the `/_content-blocks/upload` endpoint (`ContentBlocks\Controller\UploadController`, size/MIME limits configurable via `content_blocks.upload.*`) and the `cb-file-upload` Stimulus controller now ship with the main package, plus a new `ImageUploadType` (file picker + preview around a hidden path input, rendered by the `cb_image_upload` form-theme widget). Setting `content_blocks.upload.dir` switches the storage alias to `LocalFileStorage`. `FileStorageAssetResolver` also moved into the core and is the default `AssetResolverInterface` alias.

### Changed

- **Default background is transparent.** `CoreStylingDefaults` / `CoreBlockStylingDefaults` now default `styling.backgroundColor` to `''` instead of `#ffffff` — the palette color field has a real empty state, so the historical white pre-fill (needed when the field was a raw `<input type="color">`) is gone, and picking White from the palette applies a real `#ffffff`. **Upgrade note:** settings saved earlier with `backgroundColor: '#ffffff'` used to be stripped as default-equal and now render an actual white background.
- **Section settings JSON is pruned before persisting.** `SectionSidebarController` now recursively drops empty leaves (`null`, `''`, `false`, empty arrays) from the saved settings; `0` is kept. Untouched styling fields no longer persist as `null` (they would mask a preset's values on the next prefill), and unchecked switches read as "off" by absence.

### Fixed

- **Section layout labels rendered raw keys.** The layout picker referenced `cb.section.layout.{full,two_cols,three_cols}` while the catalog still only defined the superseded `cb.styling.layout.*`, so the buttons showed their translation keys. The keys are realigned, and a new `TranslationCatalogTest` guards EN/FR key parity, non-blank values, and that UI-referenced keys resolve.

## [0.1.0-beta.6] - 2026-07-01

### Added

- **Symfony 8.1 and PHP 8.4 support.** The package already declared `symfony/* ^8.0` and `php >=8.2`, so both were resolvable; this release verifies it — the package resolves against Symfony 8.1 and the source carries no PHP 8.4 deprecations (no implicitly-nullable parameters). CI now runs the browser suite on a `PHP 8.4 · Symfony 8` leg in addition to the locked `PHP 8.3 · Symfony 7` baseline, so the newest stack is exercised end to end.
- **Symfony UX 3.x support.** `symfony/ux-live-component`, `symfony/ux-twig-component` and `symfony/stimulus-bundle` are now accepted as `^2.0 || ^3.0`. UX 3 requires PHP 8.4 + Symfony 7.4, so Composer only selects it on that stack and keeps UX 2 otherwise — a backward-compatible widening. The one Live Component uses only stable core APIs and none of UX 3's removed ones (the `csrf` argument on `AsLiveComponent`, `LegacyLivePropMetadata`, `ux_controller_link_tags()`).

## [0.1.0-beta.5] - 2026-06-16

### Fixed

- **`enable_replace` / `enable_import_export` set to `false` now actually hide their buttons.** The builder templates read these `ContentAreaType` flags with Twig's `|default(true)`, which treats a boolean `false` as "empty" and falls back to `true` — so a host passing `enable_replace: false` (or `enable_import_export: false`) could never turn the feature off. The templates now use the null-coalescing `?? true`, which only defaults when the value is undefined. Both flags still default to `true` for hosts that don't set them.

## [0.1.0-beta.4] - 2026-06-16

### Added

- **Host-provided builder topbar actions.** A new `topbar_actions` option on `ContentAreaType` lets the host app render its own buttons in the builder topbar (after the Import/Export button) without the bundle knowing what they do. Each entry is `['key' => …, 'label' => …, 'icon' => … (optional, may be inline SVG), 'title' => … (optional)]`. Clicking a button dispatches a single generic, bubbling `cb:builder:action` event carrying `detail.key` (plus `areaId` and the clicked `button`); the host adds one stable listener and filters on the key — no per-key event names. Note: the builder `<dialog>` is re-parented to `<body>`, so host listeners attach to `document`/`window` rather than the launcher element.

### Fixed

- **Typing a value into a range field no longer jumps.** The range widget's number input committed mid-keystroke: each input event reached autosave, whose debounce flushed a change on the still-focused field — clamping the partial value, snapping the slider, and triggering a Live morph between two keystrokes. `cb-range` now owns a local debounce for the typing path (emitting a single change once the editor pauses, `commitDelay` default 400 ms); a real change (blur/Enter) or a slider release commits immediately and cancels the pending one. The slider drag path is unchanged.

## [0.1.0-beta.3] - 2026-06-16

### Added

- **Discarding all changes now asks for confirmation.** The "Discard changes" topbar button threw away every unpublished draft edit at once, with no prompt and no recovery short of redoing the work — far more destructive than a single delete (which has its own one-click Undo). It now shows a native confirm dialog before reverting, mirroring the "Insert content" replace flow. The prompt text is translatable via the new `cb.builder.discard_confirm` key (FR + EN).

### Fixed

- **Drag & drop reorders no longer silently revert.** On pages with many elements, a section or block move could look like it applied but snap back to its original slot after a reload — intermittently, and more often the larger the page. The reorder/duplicate endpoints each rewrite a whole sibling set's draft position (`previewPosition`), and the builder fired these requests with no serialization: two overlapping requests read each other's pre-commit state and the later commit clobbered the earlier move (a lost update). Structural mutations now run one at a time, in submission order. Also fixed along the way: a cross-column block move re-indexed the source column from its *published* order instead of its draft order, reverting any unpublished reorder of the blocks left behind.

## [0.1.0-beta.2] - 2026-06-15

### Fixed

- **A wide column gap no longer stacks the columns.** The front-side column presets subtracted a hardcoded `1rem` from their `flex-basis`, so once a section's configured gap grew past `1rem` (e.g. `40px`) the columns no longer fit on one row and wrapped onto separate lines. The presets now reserve the section's actual gap (`--cb-gap-d`, with the tablet chain at the `≤768px` breakpoint), falling back to `1rem` for sections with no gap configured.

## [0.1.0-beta.1] - 2026-06-11

First **beta**: from this release on, the public API (entities, interfaces, endpoints, form types, Twig/Stimulus contracts) is considered frozen — further breaking changes bump to `0.2`. New infrastructure backs that promise: a CI matrix (PHP 8.2–8.4 × PHPUnit / Vitest / Playwright) now gates the package split and tag propagation, the AJAX controllers and the import/export pipeline are unit-tested, and Symfony Flex recipes are served from a self-hosted endpoint (see the README installation section).

### Changed

- **Packaging — the kit now declares a real version constraint.** `klehm/content-blocks-kit` required `klehm/content-blocks: "@dev"`, which made any tagged kit release uninstallable for stability-normal consumers; it now requires `^0.1`. Both packages alias `dev-master` to `0.1.x-dev` so monorepo path installs keep resolving.

### Added

- **Symfony Flex recipes.** Bundles, routes and documented config templates are applied automatically when installing via the self-hosted recipe endpoint (one `composer config` line — see README). Each package has its own independent recipe.
- **Deleting a block or section can be undone in one click.** Deletes are immediate (no confirm dialog) and the only recovery used to be Discard — which throws away the *whole* draft. After every delete, a snackbar ("Block deleted — Undo") now floats at the bottom of the shell for 6 seconds; clicking Undo flips the draft soft-delete flag back via the new `POST /_content-blocks/block/{id}/restore` / `POST /_content-blocks/section/{id}/restore` endpoints (CSRF + `AccessCheckerInterface` protected, like every mutation) and reloads the preview. The offer is single-slot (a newer delete replaces it) and is withdrawn on publish/discard, where it could no longer be honoured. Also fixed: a failed section delete used to reload the preview as if it had succeeded.
- **Save failures are now visible (and recoverable).** Every save path — structural ops (add/move/delete/duplicate, publish, discard, replace), block-form autosaves (Live Component), and the section-settings POST — now surfaces a persistent "not saved" banner in the topbar on failure (HTTP error or network loss); the banner clears on the next successful save. Previously failures were console-only: the editor had no way to know their edits were not stored. Three silent-failure bugs fixed along the way: a network error during a structural op threw an unhandled rejection; a network error during a Live Component save left the component permanently wedged (its request queue never drained, so no later save could run); and a failed autosave was treated as "already saved" by the dirty-detection baseline, so the unchanged value was never re-sent. After a failure, the editor's next interaction with the form retries the save.

## [0.1.0-alpha.24] - 2026-06-11

### Added

- **RangeType fields accept a precise typed value.** The range widget now renders an editable number input next to the slider, so the editor can enter a value finer than the slider's step (e.g. `345` on a step-10 slider). The number input is the submitted field and stays in two-way sync with the slider; its step defaults to `1` and is overridable per field via `attr.precise_step`.
- **Duplicating a block or section no longer reloads the preview.** The duplicate endpoints ship the copy's rendered markup when it's safe to hot-reload (the block — or every block in the section — opts into `supportsPreviewHotReload`), and the overlay drops the copy in place, right after its source, preserving sibling DOM + JS state. An unsafe copy still falls back to a full reload.
- **Topbar features are now toggled per field, via `ContentAreaType` options.** `enable_replace` (Insert content) and `enable_import_export` (Import / Export) both default to `true` and hide their topbar button + overlay when set to `false`: `$builder->add('contentArea', ContentAreaType::class, ['enable_import_export' => false])`.

### Changed

- **BREAKING — Import/Export is no longer toggled globally.** The `content_blocks.import_export.enabled[_default]` parameters, the `CONTENT_BLOCKS_IMPORT_EXPORT_ENABLED` env var, and the `cb_import_export_enabled` Twig global have been removed in favour of the per-field `enable_import_export` option above. The feature is now **UI-only**: the `GET …/export` / `POST …/import` routes no longer return 404 when "disabled" — they stay reachable and remain protected by `AccessCheckerInterface` + CSRF. Hosts that relied on the env/parameter to *close the endpoints* should gate them via their firewall or `AccessChecker` instead.

## [0.1.0-alpha.23] - 2026-06-10

### Added

- **Section style changes hot-reload in place.** Saving a section's settings (padding/margin/background/gap/alignment/width mode + column widths) now patches the section's wrapper class/style and its columns' class/style directly in the preview instead of reloading the whole iframe — the inner blocks (and their JS state) are left untouched, so it's always safe. New `BlockRenderer::renderSection()` + `GET /_content-blocks/section/{id}/render`; the overlay copies wrapper + column attributes, preserving the focus outline; the builder falls back to a full reload on any error.

### Fixed

- **Section styles no longer leak into blocks.** Section and block styling shared the same CSS custom property names (`--cb-pad-*`, `--cb-mar-*`, `--cb-bg`); since custom properties inherit, a section's padding/margin/background cascaded into its descendant blocks (e.g. a padding-top set on a section was inherited by the blocks inside it). Section vars are now namespaced `--cb-s-*` and block vars `--cb-b-*`, so each scope is independent. Non-shared vars are unchanged.

## [0.1.0-alpha.22] - 2026-06-10

### Fixed

- **Section vertical alignment now aligns the columns.** Previously the setting only applied `justify-content` to the section (a flex column), which has no visible effect unless the section also has a `min-height` leaving spare room — so on a normal multi-column section it did nothing. The vertical-align value is now also applied as `align-items` on the inner `.cb-row`, so columns align relative to each other (e.g. a short column centers against a taller sibling) with no `min-height` required. The min-height "hero" centering still works via the section's `justify-content`; the default (no alignment) keeps columns stretched to equal height.

## [0.1.0-alpha.21] - 2026-06-10

### Added

- **Configurable column widths per section.** Two- and three-column sections can now use unequal widths (e.g. 33/67, 40/60) via presets or free values that must sum to 100. Stored as a `columnWidths` CSV in the section settings JSON (no migration; rides the existing draft/publish/discard lifecycle) and rendered as per-column `flex-grow` weights (`.cb-col--weighted` + `--cb-col-grow`), with a clean fallback to equal widths when unset or malformed. The control offers preset buttons (with an active/selected state) plus a "Custom" toggle that reveals free per-column inputs; the active preset is reflected on open.
- **Responsive column gap per section.** A new "Column gap" control (Styling tab) sets the gap between columns per viewport (desktop / tablet / mobile), reusing the existing viewport-tabs UI. Emitted as `--cb-gap-{d,t,m}` on the section and applied to the inner row with the usual D→T→M fallback cascade; defaults to the framework 1rem when unset.

## [0.1.0-alpha.20] - 2026-06-10

### Changed

- **WYSIWYG preview: the "+ Block" pill no longer takes flow space.** It now floats absolutely, straddling the bottom border of its column (`margin: 0`), so the builder preview matches the production layout instead of being pushed around by the affordance. It's hidden by default and revealed only when useful — on an empty column or while the parent section is hovered. Its hover state keeps a white background (only the border/text turn accent blue).

### Added

- **Hover-revealed section handle.** A small tab pinned to a section's top-left corner appears on hover (even with the cursor over a block, since `:hover` bubbles) and selects the section + opens its settings — a dependable way to grab a section that's full of blocks, where a plain click would hit a block instead. It floats, so it never affects the production-matching flow. Covered by Playwright E2E.

## [0.1.0-alpha.19] - 2026-06-10

### Added

- **Keyboard shortcuts on the focused element.** With a section or block pinned (clicked) in the preview, `Delete` / `Backspace` deletes it — the same soft-delete intent as the toolbar × button — and `Escape` deselects it (retracts the pinned toolbar and closes the sidebar). Shortcuts are ignored while typing in a preview form field, during a drag, while the block-type popover is open, and when a modifier key is held. Covered by Playwright E2E.

## [0.1.0-alpha.18] - 2026-06-10

### Added

- **Duplicate button on LiveCollection entries.** Each collection card (tabs, FAQ entries…) now carries a duplicate button (⧉) next to the reorder controls; it inserts a copy of the entry right after the original via the new `duplicateCollectionItem` live action. Like a reorder, it's an in-place value change with no `childList` mutation the autosave observer could catch, so the action persists the draft itself and reloads the preview — the copy survives a full reload. Covered by Playwright E2E plus Vitest and PHPUnit unit tests.

## [0.1.0-alpha.17] - 2026-06-10

### Added

- **Block picker ordering.** `#[AsContentBlock]` now accepts a `priority` (higher appears first in the "+Bloc" grid); blocks sharing a priority keep their service-discovery order. The `BlockTypeCompilerPass` registers types via `findAndSortTaggedServices`, so the registry insertion order — which drives the grid — is now controllable. Defaults to `0`, so existing custom blocks are unaffected. The kit ships an explicit order: Title, Text, Rich text, Image, Tabs.
- **Block name in the edit sidebar.** The block edit form now shows a lightweight heading (the block's icon in an accent chip + its translated label) above the fields, so it's always clear which block is being edited.

### Changed

- **Adding a section auto-opens its settings sidebar.** Creating a section now focuses its settings panel immediately — parity with adding a block, which already opened its edit sidebar.

## [0.1.0-alpha.16] - 2026-06-09

### Added

- **Icon-grid block picker.** The "+Bloc" popover in the preview is now a 3-column grid of tiles (icon + label) with a themed hover state, replacing the plain scrolling text list — it scans far better as the number of registered block types grows. Block types expose an icon via the new `BlockTypeInterface::getIcon(): ?string` (return self-contained inline SVG using `currentColor`, or `null` for a generic fallback glyph). `AbstractBlockType` defaults to `null`, so existing custom blocks keep working unchanged. The kit blocks (Text, Title, Image, Rich text, Tabs) ship dedicated icons.

### Fixed

- **Themed radios and checkboxes now render their `checked` state.** The form theme's `radio_widget` / `checkbox_widget` routed the input through `form_widget_simple`, but `form_div_layout` emits `checked` from the radio/checkbox widget blocks themselves — so no themed radio or checkbox was ever marked checked (most visibly, the section settings "Largeur" / `widthMode` radios showed no selection despite a valid default). `checked` is now folded into the input attributes.

## [0.1.0-alpha.15] - 2026-06-09

### Added

- **Import / export can be toggled off.** The builder's Import/Export feature is now gated by `content_blocks.import_export.enabled` (ships `true`), overridable in one place: set the env var `CONTENT_BLOCKS_IMPORT_EXPORT_ENABLED` (`0`/`false`/empty → off, `1`/`true` → on) or the parameter directly. When off, the topbar button + overlay are hidden (via the new `cb_import_export_enabled` Twig global) **and** the `GET …/export` / `POST …/import` routes return 404 — the endpoints close, not just the UI.

## [0.1.0-alpha.14] - 2026-06-09

### Added

- **Configurable default section width.** New sections now inherit a project-wide default width mode instead of always starting `full`. Set `content_blocks.section.default_width_mode` (`full` | `centered`) — paired with the existing `content_blocks.section.default_max_width`, a host can make every new section centered at a chosen container width in one place. The shipped default stays `full`, so existing projects are unchanged. Wired through `CoreSectionDefaults` (form pre-fill), `SectionSettingsType` (radio pre-selection) and `BuiltInSectionDecorator` (render fallback) so all three move together.

### Changed

- **Centered sections keep a full-width background.** A `centered` section's max-width + centering now applies to the inner `.cb-row` (via a `--cb-row-max-w` custom property read in `layout.css`) instead of the `<section>` element itself. The section background therefore spans the full viewport width while its content stays contained — the standard full-bleed pattern. **Visual change for existing centered sections that have a background colour:** the colour now bleeds edge to edge instead of being capped to the container width.

### Fixed

- **`cb-form-row` wrapper was no longer applied.** The form theme's row block was named `form_row_render` — a name Symfony's `form_row()` never invokes (the rendered block has been `form_row` since Symfony ≥5.3), so the custom row markup was silently bypassed. Renamed to `form_row`, and folded in the native `widget_attr` (`aria-describedby` linking the help text) that the built-in `form_row` provides.

## [0.1.0-alpha.12] - 2026-06-08

### Added

- **Preview hot reload.** After an inline block edit the builder now refreshes just that block's markup in place instead of reloading the whole preview iframe — no flash, and the host page's scripts aren't re-run. A new `BlockTypeInterface::supportsPreviewHotReload()` (default `false` in `AbstractBlockType`) lets a block type opt in when its rendered *view* is self-contained (static or CSS-only); JS-dependent views keep the full reload. The decision is enforced server-side via `GET /_content-blocks/block/{id}/render`, which returns the single block's fragment (`{hotReload:true, html}`) or `{hotReload:false}`. Block deletes also drop the element in place (no reload — deleted blocks render hidden anyway), and the overlay dispatches a `cb:block:rendered` DOM event on each swapped block so JS-enhanced views can re-initialise idempotently.
- **Drag & drop reordering of LiveCollection items.** Collection fields (tabs, cards, FAQ…) edited in the sidebar can be reordered by dragging a handle or via keyboard up/down buttons. Works for any block with a `LiveCollectionType`, no host wiring. **Action required for upgrading hosts:** add `"@klehm/content-blocks/cb-collection-sort"` to `assets/controllers.json`.
- **Block fields grouped into sidebar tabs.** Fields are bucketed into tabs by a `data-cb-group` attribute, with a default "General" tab and a trailing "Style" tab; hidden tabs stay in the DOM so autosave and validation keep working across them. **Action required for upgrading hosts:** add `"@klehm/content-blocks/cb-tabs"` to `assets/controllers.json`.
- **`SeparatorType` form field.** A non-mapped pseudo-field rendering an `<hr>` in the sidebar so a block can visually group its fields, picked up by the generic form theme (`cb_separator_widget`) — no per-block `getFormTheme()` wiring needed.

### Fixed

- **Structural edits in a block form now autosave.** Adding or removing an item in a block that renders a `LiveCollectionType` (e.g. the kit's Tabs block) goes through a Live action that re-renders the sidebar **without** emitting any field `input`/`change` event — so `cb-autosave`'s field listeners missed it and the change was never persisted to the draft, leaving the preview stale (a removed item silently reappeared on the next edit). `cb-autosave` now also watches the form's node tree with a `MutationObserver`: any re-render that changes the form's serialized state triggers a save. The save stays idempotent — `_saveNow()` only POSTs when the serialized state actually differs from the last snapshot, so the morph caused by the save itself is a no-op and there is no loop.
- **Autosave no longer loops the upload on file fields.** Before saving, `cb-autosave` synthesises a `change` on the focused field to flush its value into the Live model binding. On an `<input type="file">` that re-triggered `cb-file-upload`, which re-uploaded the same file under a fresh random name, wrote a new hidden `src`, and fired another save — an infinite loop ("Uploading…" that never stopped after picking an image). File inputs are now skipped: their value is already committed via the hidden input the upload controller writes.
- **Builder close (×) button.** The launcher re-parents the `<dialog>` to `document.body` on connect, which moved the close button out of the launcher controller's scope so its action never bound (only the native Escape key closed the builder). The close action now lives on `cb-builder`, which stays inside the dialog and closes it directly.

### Changed

- **Builder preview spacing aligned with the production render.** Dropped builder-only column padding and section `padding-top` (the preview overlay markers don't exist in prod, so they skewed fidelity) and bumped the inline "+ Block" pill margin to compensate.

## [0.1.0-alpha.11] - 2026-05-19

### Added

- **JSON Import / Export.** A new "Import / Export" button sits in the builder topbar next to "Insert content" and opens an overlay panel exposing two flows: download the area as a self-contained JSON file (sections + columns + blocks + base64-encoded asset binaries, deduplicated by sha256), or upload a previously-exported file to replace the current draft (soft-delete + clone semantics, mirroring the existing replace-with flow — Publish commits the swap, Discard reverts). Endpoints are `GET /_content-blocks/area/{id}/export` and `POST /_content-blocks/area/{id}/import`, both CSRF + AccessChecker protected. The export walks block data recursively and rewrites any stored asset path to an `asset://{hash}` token; the importer re-uploads each blob and reverses the substitution. A new `ContentBlocks\Asset\AssetResolverInterface` abstracts asset I/O; the kit ships a default bridge over `FileStorageInterface`, so hosts already configured for image uploads get import/export for free.

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
