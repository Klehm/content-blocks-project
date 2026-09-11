# Changelog

All notable changes to `klehm/content-blocks-i18n` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Translations now travel with an export.** `TranslationTransferExtension`
  rides the core's new `ContentAreaTransferExtensionInterface`, writing an
  `extensions."content-blocks/i18n"` fragment that holds each block's values and
  staleness digests per locale, and replaying it on import.

  Until now a translated page exported to JSON came back structurally identical
  and entirely untranslated, with no warning — the side-table schema carries its
  rows through cloning and insert-content, but nothing had taught the transfer
  flow. Images referenced only by a translated rich-text value were missing from
  the export for the same reason; they are embedded now, through the core's
  shared asset seam.

  Three behaviours worth knowing:

  - **Digests are carried, never recomputed.** A digest asserts "translated from
    *this* source", which only the side that translated it can know; re-hashing
    on arrival would report every stale field as up to date.
  - **Every locale is imported**, configured in the target installation or not —
    a row for an unconfigured locale renders nothing, and dropping it would
    destroy content that one config line makes usable.
  - **Rows land in the draft**, like every other write here: the imported page
    and its translations go live together at the next Publish.

  Nothing to wire, and nothing changes for an installation without this package:
  the core skips a fragment no extension claims.

## [1.0.0-RC5] - 2026-09-10

### Added

- **Translated values now count as asset references.** The core's new asset
  sweep (`content-blocks:assets:gc`) deletes uploaded files nothing points at,
  and a translated rich-text value is a row in a *separate table* carrying its
  own `<img src="/uploads/…">`. An image uploaded while writing the German
  version of a page is referenced by no block's data anywhere, so without this
  the sweep would have deleted it and emptied the German page.

  `TranslationAssetReferenceProvider` reports both slots — published values are
  on the public site now, draft values are one Publish away. Nothing to wire:
  it is registered by the bundle and picked up through the core's autoconfigured
  `AssetReferenceProviderInterface`.

- **`CrossRequestStateTest`** — a guard that fails when a class in this package
  keeps mutable state without being either resettable or declared as not
  request-scoped. Translation is the one feature here that caches per request by
  design (the prefetch that keeps a translated page from issuing one SELECT per
  block), which is exactly the shape of thing that serves one page's French to
  the next page under a worker. See the
  [worker mode guide](https://klehm.github.io/content-blocks-project/guide/worker-mode).

### Fixed

- **The field-metadata cache no longer outlives the request it was built for.**
  `FieldMetadataReader` memoizes a block type's labels, translation domains and
  widgets so a 40-block page builds each form shape once. It reads that off a
  *form*, and a form is allowed to vary with the ambient request — a host's
  `BlockFormExtensionInterface` can add a field for one role and not another —
  while the cache key deliberately ignores `$data`, pinning whichever shape it
  saw first. Under PHP-FPM that pin lasted one request. Under a worker runtime
  (FrankenPHP, RoadRunner) it lasted until the process was recycled, so the
  workbench showed every editor the field set of whoever hit the page first.
  The reader now implements `ResetInterface` and is cleared on
  `kernel.terminate`, like `TranslationStore` already was.

## [1.0.0-RC4] - 2026-08-31

Version bump only — no functional change in `klehm/content-blocks-i18n`. The tag
is cut across the monorepo so the three packages stay installable as one set;
this candidate's fix is in `klehm/content-blocks` — the published page no longer
changes while someone edits it. **It needs a migration**: see that package's
CHANGELOG and the [upgrade guide](https://klehm.github.io/content-blocks-project/guide/upgrade).

Worth knowing here, since translations key off block ids: a block dragged
between columns now keeps its id through a Discard instead of staying put in its
new column, so its rows in `cb_block_translation` follow the block the editor
actually sees.

## [1.0.0-RC3] - 2026-08-24

Version bump only — no functional change in `klehm/content-blocks-i18n`, and
none in RC2 either. The tag is cut across the monorepo so the three packages
stay installable as one set; see the `klehm/content-blocks-kit` CHANGELOG for
what this candidate fixes.

## [1.0.0-RC1] - 2026-08-13

First release of the content-translation satellite, shipped as part of the
1.0 release candidate.

First release of the content-translation satellite. It implements the schema
decided in the monorepo's translation spike: **one shared layout, per-locale
field values, stored in a side table.**

### Added

- **Per-locale publish and discard, at the API level.** `TranslationPublisher`
  now reads the core's new `PublishContext`: `withLocales('fr')` takes French
  live and leaves German on its published values with its draft intact,
  `sourceOnly()` publishes the source and holds every translation back. Passing
  no context keeps the previous all-or-nothing behaviour, which stays the
  default and the only thing any UI offers today.

  A row whose block is being deleted is still removed whatever the scope —
  there is no locale left to hold back. And the area's own draft always
  publishes: the scope narrows translations only, so a translation can never
  run ahead of its source.
- **The workbench's fifteen `--cb-wb-*` tokens are documented** with their
  defaults, so restyling it to sit inside the host's admin no longer means
  reading the stylesheet. The four field-state colors (translated, outdated,
  missing, error) are the ones worth overriding first.
- `BlockTranslationRepository` is marked `@internal` ahead of the 1.0 freeze —
  it is queried only by the package. The seams remain
  `TranslationProviderInterface` and `RenderLocaleResolverInterface`.
- **`BlockTranslation` entity + `cb_block_translation` table** — one row per
  block per locale, holding a flat map of field path to value, with separate
  draft and published payloads mirroring `Block` exactly.
- **Field path grammar** (`FieldPath`) — addresses a value inside `Block.data`,
  with collection entries keyed by their `_id` rather than their position, so a
  reorder cannot reattach a translation to the wrong entry.
- **Staleness via source digests** (`SourceDigest`) — a fingerprint of the
  source text stored beside each translation, so a source rewritten after
  translation is reported as *outdated* rather than silently left wrong.
- **Render path** — `TranslationBlockDataResolver` merges the locale payload
  through the core's `BlockDataResolverInterface`; `PrefetchingBlockRenderer`
  decorates the renderer to load an area's translations in one query. Fallback
  is per field, so a half-translated page renders as incomplete rather than
  broken.
- **Locale resolution** — `RenderLocaleResolverInterface`, defaulting to the
  request locale with an explicit `RenderContext` locale taking precedence.
- **Progress reporting** — `TranslationInspector` / `TranslationProgress`, with
  translated / outdated / missing counted separately, per block, section, area
  and locale.
- **Machine translation — a seam, no engine.** `TranslationProviderInterface`
  (batch-shaped, so a page is one call), a registry, and
  `NullTranslationProvider` as an honest default. **No adapter for any
  translation service ships here**: where a page's text may be sent is a
  host decision about cost, quality and confidentiality, not something that
  should arrive as a transitive dependency of a page builder. The sandbox's
  `pseudo` provider — offline and deterministic — is the worked example, and no
  vendor SDK is in any package's `require`.
  `MachineTranslator` drives both the per-field and whole-page flows through one
  code path, writing results through the ordinary write gate.

  With no provider registered the workbench renders **no machine-translation
  affordance at all** — no ⚡ on a field, no "translate this page", no picker —
  and a provider whose `supports()` rejects a page's language pair is left out
  for that page. An unconfigured feature is better absent than present and
  failing; manual translation is unaffected either way.
- **Lifecycle** — `TranslationPublisher` decorates the core publisher so
  translations ride Publish and Discard with the content they translate;
  `TranslationCloneObserver` carries translations onto duplicated sections.
- **HTTP API** under `/_content-blocks/i18n` (CSRF + `canEdit`) and two console
  commands, `content-blocks:i18n:status` and `content-blocks:i18n:translate`.
- **The workbench** (`GET /_content-blocks/i18n/workbench/{id}/{locale}`) — every
  translatable field of a page in one list, source beside target, with the page
  previewed next to it. A standalone page rendered in full by the package, with
  its rows server-rendered so a translator can type on first paint. Saves are
  debounced and batched per block, and flushed on unload with `keepalive`; the
  preview swaps one block at a time, so its scroll position and JS state survive
  an edit.
- **Localized preview without a second resolver** — the workbench appends
  `?cb_preview=1&cb_chrome=0&cb_locale=<target>` to the URL the host's
  `ContentAreaUrlResolverInterface` returned, and `PreviewLocaleListener` turns
  the locale parameter into the request locale. It is honoured only on a request
  that is already in preview mode, so it cannot switch the language of a public
  page. `cb_chrome=0` (core) drops the builder's toolbars from the pane — they
  would be dead ends here, with no builder sidebar to open.
- **The mount point is the host's choice** — `config/routes/bare.php` carries the
  routes with no prefix, so a host can import them under `/admin/translations`
  (or anywhere) and let one firewall pattern cover them; `config/routes.php`
  keeps the default `/_content-blocks/i18n`. Route names are the same either
  way, and the workbench generates every URL its JavaScript calls with `path()`,
  so nothing hardcodes a path.
- **Twig helpers** (`I18nExtension`) — `cb_i18n_workbench_url()`,
  `cb_i18n_locales()` and `cb_i18n_progress()`, so a host's page list can link
  into the workbench and show "DE 40%" without knowing how any of it is computed.
- **Self-served assets** — `/_content-blocks/i18n/asset/workbench-{css,js}`. The
  workbench is deliberately not a Stimulus controller: the page never loads the
  host's bundle, so a self-contained ES module needs no `controllers.json` entry
  and no asset recompilation, under AssetMapper or Encore alike.

### Requires (core)

This package consumes seams that shipped with `klehm/content-blocks` for exactly
this purpose:

- `BlockDataResolverInterface` — the render-time data seam;
- `RenderContext` — carries the locale through the render pipeline;
- the `cb_translatable` form-option convention + `TranslatableFieldsInterface`;
- `_id` on collection entries and the reserved `_` prefix;
- **`BlockCloneObserverInterface`** — added alongside this package (additive: an
  optional constructor argument on `SectionCloner`, no interface change).
