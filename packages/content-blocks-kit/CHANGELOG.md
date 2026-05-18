# Changelog

All notable changes to `klehm/content-blocks-kit` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0-alpha.6] - 2026-05-18

Version bump only — no functional changes in `klehm/content-blocks-kit`. The companion `klehm/content-blocks` package replaces the sidebar tabs with two always-visible groups, dedupes no-op autosaves (no more redundant iframe reloads), and conditionally hides the section `maxWidth` field. See the `content-blocks` CHANGELOG for the upgrade notes.

## [0.1.0-alpha.5] - 2026-05-18

Version bump only — no functional changes in `klehm/content-blocks-kit`. The companion `klehm/content-blocks` package ships a substantial builder UI refactor (permanent sidebar, autosave, removal of the `horizontalAlign` styling option, outline preservation across iframe reloads). Tags are kept in sync across the two packages for monorepo coherence; see the `content-blocks` CHANGELOG for the upgrade notes.

## [0.1.0-alpha.4] - 2026-05-18

### Fixed

- **Twig override priority for the `@ContentBlocksKit` namespace.** The bundle no longer manually registers its `templates/` path under its own Twig namespace — this was duplicating Symfony's `AbstractBundle` auto-detection and inserting the vendor path with higher priority than the host app's `templates/bundles/ContentBlocksKitBundle/` override directory, effectively disabling the standard override mechanism. Override directories now work as documented.
