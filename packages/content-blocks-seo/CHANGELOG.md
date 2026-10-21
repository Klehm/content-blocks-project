# Changelog

All notable changes to `klehm/content-blocks-seo` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: an SEO audit panel in the builder.** It checks the draft
  page, template included, for a missing or second `h1`, a skipped heading
  level, an empty heading, images without alternative text, links with no or
  vague text, and long paragraphs. A finding inside a block selects it (core
  `cb:focus`; on an older core the list still shows, the click does nothing).
  The panel opens from the Actions menu and checks again after each save.
  Configurable: `paragraph_max_words`, `generic_link_texts`,
  `disabled_checks`.
