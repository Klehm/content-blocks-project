# Changelog

All notable changes to `klehm/content-blocks` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Refactor: extract section / column / block render into dedicated templates for granular overrides.** `@ContentBlocks/render/content_area.html.twig` no longer renders sections, columns and blocks inline — each level now lives in its own template and is included from the parent with `with_context = false`. Markup, CSS classes and `data-cb-*` attributes are unchanged.
  - New override points exposed under `templates/bundles/ContentBlocksBundle/render/` in the host app:
    - `section.html.twig` — receives `section: Section`, `isPreview: bool`
    - `column.html.twig` — receives `column: Column`, `isPreview: bool`
    - `block.html.twig` — receives `block: Block`, `isPreview: bool`
  - **Breaking for forks of `content_area.html.twig`:** if your host app previously copied the entry-point template to customise a sub-level (a `<section>`, a `<div class="cb-col">`, etc.), re-target your override to the new dedicated template rather than maintaining a full copy of `content_area.html.twig`.

## [0.1.0-alpha.2] - 2026-05-13

Initial alpha. See `git log` for the per-commit history prior to this changelog.
