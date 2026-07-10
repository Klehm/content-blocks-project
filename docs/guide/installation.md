---
title: Installation
---

# Installation

ContentBlocks is the core package for the Symfony page builder: entities, admin UI (Live Components + Stimulus), the `ContentAreaType` form, and the block-type registry. Pair it with [`klehm/content-blocks-kit`](https://github.com/klehm/content-blocks-kit) for ~17 ready-to-use blocks (Text, Title, Image, Tabs…).

## Requirements

- PHP >= 8.2 (>= 8.4 for Symfony 8.0)
- Symfony 6.4 LTS, 7.x or 8.x
- Doctrine ORM ^2.12 or ^3.0

## Pre-release stability

The packages are published as `0.1.0-beta.*` pre-releases. If your project is `stable`-only, allow beta packages by lowering the minimum stability while keeping stable dependencies preferred:

```json
{
    "minimum-stability": "beta",
    "prefer-stable": true
}
```

Then require both packages:

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

## With the Flex recipe (recommended)

A self-hosted Flex recipe endpoint automates the bundle registration, route mounts and config templates. Add it **before** requiring the packages:

```bash
composer config extra.symfony.endpoint \
  '["https://raw.githubusercontent.com/klehm/content-blocks-project/flex/main/index.json", "flex://defaults"]'
composer require klehm/content-blocks klehm/content-blocks-kit
```

Each package has its own recipe: requiring only `klehm/content-blocks` applies only its recipe; the kit's recipe (its bundle, upload route and file-storage config template) is applied when `klehm/content-blocks-kit` is required.

The recipe registers the bundles, mounts the `/_content-blocks/*` routes, and copies a documented `config/packages/content_blocks.yaml` where the two **required** host services are wired (see [host services](./host-services.md)). Flex itself (independently of the recipe) syncs the Stimulus controllers and the `sortablejs` importmap entry into your `assets/controllers.json`.

## Without Flex

If you don't use Flex, register the bundles and routes manually:

```php
// config/bundles.php
return [
    // ...
    ContentBlocks\ContentBlocksBundle::class => ['all' => true],
    ContentBlocks\Kit\ContentBlocksKitBundle::class => ['all' => true],
];
```

```yaml
# config/routes/content_blocks.yaml
content_blocks:
    resource: '@ContentBlocksBundle/config/routes.php'
```

## Stimulus controllers & admin CSS

::: warning Required (manual until a Flex recipe ships)
The host's Symfony Stimulus Bundle reads `assets/controllers.json` from your project — it does **not** auto-discover controllers shipped by third-party packages. Without an entry for each controller, the builder UI loads no JS and the "Edit content" button does nothing.
:::

Add the following to `assets/controllers.json`:

```json
{
    "controllers": {
        "@klehm/content-blocks": {
            "cb-builder-launcher": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@klehm/content-blocks/styles/admin.css": true
                }
            },
            "cb-builder":               { "enabled": true, "fetch": "eager" },
            "cb-autosave":              { "enabled": true, "fetch": "eager" },
            "cb-section-settings-form": { "enabled": true, "fetch": "eager" },
            "cb-spacing-link":          { "enabled": true, "fetch": "eager" },
            "cb-viewport-tabs":         { "enabled": true, "fetch": "eager" },
            "cb-collection-sort":       { "enabled": true, "fetch": "eager" },
            "cb-condition":             { "enabled": true, "fetch": "eager" },
            "cb-file-upload":           { "enabled": true, "fetch": "eager" }
        }
    },
    "entrypoints": []
}
```

The `cb-collection-sort` controller (drag-and-drop reordering of collection fields) depends on [SortableJS](https://github.com/SortableJS/Sortable). Pin it in your importmap once:

```bash
php bin/console importmap:require sortablejs
```

Then re-run `php bin/console asset-map:compile` (or your normal asset build).

The `autoimport` block on `cb-builder-launcher` pulls in `admin.css` (styles for the launcher button, builder dialog and sidebars). You do **not** need to add `import '@klehm/content-blocks/styles/admin.css'` in `app.js` — Stimulus Bundle handles it once the entry above is in place.

::: tip
A Symfony Flex recipe that injects this whole block automatically is on the roadmap — once published, this manual step goes away.
:::

### Public assets loaded inside the preview iframe

The bundle exposes four routes under `/_content-blocks/public/*` that serve the styles and the overlay JS injected into the front-end iframe:

- `/_content-blocks/public/layout` → `text/css` (PUBLIC + PREVIEW)
- `/_content-blocks/public/styling` → `text/css` (PUBLIC + PREVIEW)
- `/_content-blocks/public/builder` → `text/css` (PREVIEW only)
- `/_content-blocks/public/preview-overlay` → `application/javascript` (PREVIEW only)

The render template injects these `<link>` and `<script>` tags itself, so the host has nothing to wire. They are deliberately split out from the admin endpoints (`/_content-blocks/sections/*`, `/_content-blocks/blocks/*`, `/_content-blocks/upload`) so a host can lock the admin endpoints down without 404-ing the iframe assets — see [Firewalls & access control](./security.md#firewalls-access-control).

## Database schema

This package ships Doctrine entities (`cb_content_area`, `cb_section`, `cb_column`, `cb_block`) but no migrations — generate them in your own pipeline:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Or, for a brand-new database:

```bash
php bin/console doctrine:schema:update --force
```

## Quick start

Attach a `ContentArea` to your own entity (e.g. `Page`).

::: danger cascade persist is required
`ContentAreaType` returns a **transient** `ContentArea` on submit and relies on `cascade: ['persist', 'remove']` to commit it together with the host entity. Without cascade, the area is never written.
:::

```php
use ContentBlocks\Entity\ContentArea;

#[ORM\Entity]
class Page
{
    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ContentArea $contentArea = null;
}
```

Render the builder in any Symfony form:

```php
$builder->add('contentArea', ContentAreaType::class);
```

Then [render the ContentArea on the public page](./rendering.md) — this step is required for the in-context editing UI to appear.

For the mental model behind these entities and the `ContentAreaType` lifecycle, see [Core concepts](./concepts.md).

## Known install-time warnings

`composer audit` may flag `doctrine/annotations` as abandoned. This package does **not** require `doctrine/annotations` — the warning comes from your host project (typically pulled in by an older Symfony Framework Bundle setup or a legacy Doctrine config). Remove it with `composer remove doctrine/annotations` and set `framework.annotations: false` in your config if your app no longer uses annotation-based metadata.
