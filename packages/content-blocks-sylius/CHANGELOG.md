# Changelog

All notable changes to `klehm/content-blocks-sylius` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: ContentBlocks in a Sylius 2 shop (2.0 to 2.3).**
  - CMS pages (`ContentPage`): admin CRUD under *Content → Pages*, title, slug
    and meta translated per Sylius locale, enabled per channel, previewed by
    an administrator before being enabled.
  - Builder content on native pages: a channel's homepage (replaces the native
    sections once something is published), below a product's details, above a
    taxon's products. Nothing changes on a page without published content.
  - Catalog blocks in a *Shop* picker group: `product`, `product_list`
    (chosen, taxon or latest; grid or carousel), `product_price`,
    `add_to_cart` and `taxon_list`. They store codes and render through the
    shop's own Twig components, and are swapped in place in the builder's
    preview, without a reload. The carousel has the core slider's arrows and
    dots.
  - Bootstrap 5 markup for twelve kit blocks (`kit_markup: bootstrap`, the
    default); a host's own kit template overrides still win.
  - The builder's sidebar posts to Sylius's admin Live route, which every
    Sylius app mounts (no `ux_live_component` route needed).
  - The host services the core needs: an access checker for shop
    administrators, preview URLs, the *Insert content* list, and with
    `klehm/content-blocks-i18n` the translation targets (the locales of every
    enabled channel), the page URLs and the workbench's way back.
  - With `klehm/content-blocks-media`, the media library in the builder: its
    routes under the admin prefix (`config/routes/media.yaml`), and shop
    administrators allowed to manage it unless the host names its own
    manager.
