# Changelog

All notable changes to `klehm/content-blocks-i18n` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release of the content-translation satellite. It implements the schema
decided in the monorepo's translation spike: **one shared layout, per-locale
field values, stored in a side table.**

### Added

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
- **Machine translation** — `TranslationProviderInterface` (batch-shaped, so a
  page is one call), a registry, `NullTranslationProvider` as an honest default,
  and a shipped `DeepLTranslationProvider`. `MachineTranslator` drives both the
  per-field and whole-page flows through one code path, writing results through
  the ordinary write gate.
- **Lifecycle** — `TranslationPublisher` decorates the core publisher so
  translations ride Publish and Discard with the content they translate;
  `TranslationCloneObserver` carries translations onto duplicated sections.
- **HTTP API** under `/_content-blocks/i18n` (CSRF + `canEdit`) and two console
  commands, `content-blocks:i18n:status` and `content-blocks:i18n:translate`.

### Requires (core)

This package consumes seams that shipped with `klehm/content-blocks` for exactly
this purpose:

- `BlockDataResolverInterface` — the render-time data seam;
- `RenderContext` — carries the locale through the render pipeline;
- the `cb_translatable` form-option convention + `TranslatableFieldsInterface`;
- `_id` on collection entries and the reserved `_` prefix;
- **`BlockCloneObserverInterface`** — added alongside this package (additive: an
  optional constructor argument on `SectionCloner`, no interface change).
