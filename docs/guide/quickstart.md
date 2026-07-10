---
title: Quick start
---

# Quick start

This walks you from an empty project to a rendered, editable content area. It assumes a working Symfony app with Doctrine and a MySQL database. For the full install (Flex recipe, asset wiring, without-Flex path) see [Installation](./installation.md).

::: tip For AI agents
A condensed, deterministic version of this path lives in [`AGENTS.md`](https://github.com/klehm/content-blocks-project/blob/master/AGENTS.md) at the repo root.
:::

## 1. Install the packages

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

If your project is `minimum-stability: stable`, allow beta pre-releases:

```json
{
    "minimum-stability": "beta",
    "prefer-stable": true
}
```

Register the bundles (Flex does this automatically — otherwise add them to `config/bundles.php`) and mount the routes. See [Installation](./installation.md#without-flex) for the manual snippets, and [Stimulus controllers & admin CSS](./installation.md#stimulus-controllers-admin-css) for the required `assets/controllers.json` entries.

## 2. Attach a ContentArea to your entity

Give your own entity (here `Page`) a `OneToOne` relation. The `cascade: ['persist', 'remove']` is **required** — `ContentAreaType` returns a transient `ContentArea` on submit and relies on cascade to commit it.

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

Generate and run the migration (this also creates the `cb_*` tables):

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## 3. Wire the two required host services

ContentBlocks doesn't know your auth model or how a `ContentArea` maps back to a URL. Provide both — the defaults deny access and throw, respectively.

```yaml
# config/services.yaml
services:
    ContentBlocks\Security\AccessCheckerInterface:
        class: App\Security\PageAccessChecker

    ContentBlocks\Preview\ContentAreaUrlResolverInterface:
        class: App\Preview\PageContentAreaUrlResolver
```

See [Host services](./host-services.md) for the (small) implementations.

## 4. Add the builder to a form

```php
use ContentBlocks\Form\Type\ContentAreaType;

$builder->add('contentArea', ContentAreaType::class);
```

The widget renders the "Edit content" launcher. On a brand-new entity that hasn't been saved yet, it shows a "save first" placeholder — [by design](./concepts.md#contentareatype-lifecycle), it never writes to the DB on a GET.

## 5. Render the area on the public page

**This step is required.** The builder edits your *real* public page in an iframe, and the editing markers are produced by the render call — without it, nothing is editable.

```twig
{# templates/page/show.html.twig — your public template #}
<article>
    <h1>{{ page.title }}</h1>
    {{ cb_render_content_area(page.contentArea) }}
</article>
```

`cb_render_content_area()` accepts `null` (renders an empty string), so you don't need an `{% if %}` guard.

## 6. Include the kit stylesheet

The kit's blocks render neutral markup styled by one shipped stylesheet. Include it once in your front layout:

```twig
<link rel="stylesheet" href="{{ path('content_blocks_kit_asset_css') }}">
```

## Done

Open your page as an authenticated editor, click **Edit content**, and start adding sections and blocks. The preview is your live page.

Next:

- **[Core concepts](./concepts.md)** — understand the model before you build on it.
- **[Block Kit](/kit/)** — every shipped block, configurable per host.
- **[Custom blocks](./custom-blocks.md)** — add your own block type in one class.
