# Changelog

All notable changes to `klehm/content-blocks-i18n` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
