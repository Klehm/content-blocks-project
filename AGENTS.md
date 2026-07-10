# AGENTS.md — installing ContentBlocks into a host Symfony app

This file is a deterministic, copy-pasteable integration guide for AI coding
agents adding **ContentBlocks** to an existing Symfony application. Humans should
read the [documentation site](https://klehm.github.io/content-blocks-project/);
this file is the condensed, unambiguous version.

> Scope: you are integrating the *published packages* into a host app. If you are
> working *inside this monorepo*, see [`CLAUDE.md`](CLAUDE.md) instead.

## Prerequisites (verify before starting)

- PHP >= 8.2 (>= 8.4 for Symfony 8.0), with `pdo_mysql` if using MySQL
- Symfony 6.4 LTS, 7.x, or 8.x
- Doctrine ORM ^2.12 or ^3.0
- Node.js >= 18 with AssetMapper or Webpack Encore configured
- The host app has an entity with a public URL (e.g. `Page`, `Product`)

## Step 1 — Require the packages

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

If the host is `minimum-stability: stable`, first set in `composer.json`:

```json
{ "minimum-stability": "beta", "prefer-stable": true }
```

## Step 2 — Register bundles & routes (skip if Symfony Flex did it)

```php
// config/bundles.php
ContentBlocks\ContentBlocksBundle::class => ['all' => true],
ContentBlocks\Kit\ContentBlocksKitBundle::class => ['all' => true],
```

```yaml
# config/routes/content_blocks.yaml
content_blocks:
    resource: '@ContentBlocksBundle/config/routes.php'
```

## Step 3 — Wire Stimulus controllers & admin CSS (REQUIRED, manual)

The host's Stimulus Bundle does not auto-discover third-party controllers. Add to
`assets/controllers.json`:

```json
{
    "controllers": {
        "@klehm/content-blocks": {
            "cb-builder-launcher": { "enabled": true, "fetch": "eager", "autoimport": { "@klehm/content-blocks/styles/admin.css": true } },
            "cb-builder":               { "enabled": true, "fetch": "eager" },
            "cb-autosave":              { "enabled": true, "fetch": "eager" },
            "cb-section-settings-form": { "enabled": true, "fetch": "eager" },
            "cb-spacing-link":          { "enabled": true, "fetch": "eager" },
            "cb-viewport-tabs":         { "enabled": true, "fetch": "eager" },
            "cb-collection-sort":       { "enabled": true, "fetch": "eager" },
            "cb-condition":             { "enabled": true, "fetch": "eager" },
            "cb-file-upload":           { "enabled": true, "fetch": "eager" }
        },
        "@klehm/content-blocks-kit": {
            "cb-tinymce": { "enabled": true, "fetch": "eager" },
            "cb-gallery": { "enabled": true, "fetch": "eager" }
        }
    },
    "entrypoints": []
}
```

Then:

```bash
php bin/console importmap:require sortablejs   # cb-collection-sort dependency
php bin/console asset-map:compile
```

## Step 4 — Attach a ContentArea to the host entity

`cascade: ['persist', 'remove']` is REQUIRED (the form returns a transient area).

```php
use ContentBlocks\Entity\ContentArea;

#[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?ContentArea $contentArea = null;
```

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Step 5 — Implement the two REQUIRED host services

Defaults deny all access / throw. You MUST provide both.

```yaml
# config/services.yaml
services:
    ContentBlocks\Security\AccessCheckerInterface:
        class: App\Security\PageAccessChecker
    ContentBlocks\Preview\ContentAreaUrlResolverInterface:
        class: App\Preview\PageContentAreaUrlResolver
```

- `AccessCheckerInterface::canEdit(ContentArea): bool` — authorize mutations (own the parent entity?).
- `ContentAreaUrlResolverInterface::resolve(ContentArea): string` — return the public URL of the area's owner (used for the iframe preview).

Implementations: https://klehm.github.io/content-blocks-project/guide/host-services

## Step 6 — Add the builder to a form and render on the page

```php
use ContentBlocks\Form\Type\ContentAreaType;
$builder->add('contentArea', ContentAreaType::class);
```

```twig
{# public template — REQUIRED: this call produces the editable markers #}
{{ cb_render_content_area(page.contentArea) }}

{# in your <head>, for kit block styles #}
<link rel="stylesheet" href="{{ path('content_blocks_kit_asset_css') }}">
```

## Step 7 — Security config (host)

```yaml
# config/packages/framework.yaml — CSRF tokens are session-bound
framework:
    session: true
    csrf_protection: { enabled: true }
```

```yaml
# config/packages/security.yaml — single-firewall example
security:
    access_control:
        - { path: ^/_content-blocks/public, roles: PUBLIC_ACCESS }
        - { path: ^/_content-blocks,        roles: ROLE_ADMIN }
```

Separate admin/front firewalls: see https://klehm.github.io/content-blocks-project/guide/security

## Verification checklist

- [ ] `composer require` succeeded; both bundles in `config/bundles.php`
- [ ] `assets/controllers.json` has both controller packages; `asset-map:compile` runs clean
- [ ] Migration created `cb_content_area`, `cb_section`, `cb_column`, `cb_block`
- [ ] Both required services are aliased in `services.yaml`
- [ ] Public template calls `cb_render_content_area()`
- [ ] Kit stylesheet `<link>` is in the front layout
- [ ] As an authenticated editor, "Edit content" opens the builder and the preview shows the real page

## Optional: kit block configuration

Restrict/tune the 17 blocks under `content_blocks_kit.blocks.<type>` (`enabled`,
`options`, `choices`, `defaults`). Inspect any block's surface:

```bash
bin/console content-blocks-kit:blocks --format=json
```

Reference: https://klehm.github.io/content-blocks-project/kit/configuration

## Custom blocks

One class + one Symfony form, tagged `#[AsContentBlock]`, auto-registers. The form
IS the data whitelist/validator. See
https://klehm.github.io/content-blocks-project/guide/custom-blocks
