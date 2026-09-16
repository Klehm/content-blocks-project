---
title: Mounting the routes
---

# Mounting the routes

By default the recipe mounts every route of the package under `/_content-blocks`. That path is a default, not a requirement: **the mount point belongs to your app**. Route *names* are the contract ([backward compatibility](./backward-compatibility.md#http-and-console)); the URLs are yours to choose.

The usual reason to move them is a firewall. With the default mount, an admin firewall has to spell out a second path next to `/admin`. Mounted under `/admin`, the builder is covered by the pattern you already have.

## Two families, imported apart

The package's routes fall into two families that must **not** share a mount:

| File | Routes | Who calls them |
|---|---|---|
| `config/routes/editor.php` | every builder endpoint: sections, blocks, sidebars, upload, clipboard, history, import/export, the asset report | the editor, from the builder |
| `config/routes/public.php` | `layout`, `styling`, `builder` (CSS) and `preview-overlay` (JS) | **every visitor**: the public page links `layout.css` and `styling.css` |

Neither file carries a prefix of its own. `config/routes.php`, the file the recipe imports, is simply both of them under the default prefixes.

::: danger Keep the public family public
Mounting `public.php` under a path your firewall protects breaks the styling of every published page for anonymous visitors. Leave it at its default, or mount it anywhere public.
:::

## Choosing your own mount

Replace the recipe's import:

```yaml
# config/routes/content_blocks.yaml
content_blocks_editor:
    resource: '@ContentBlocksBundle/config/routes/editor.php'
    prefix: /admin/content-blocks
content_blocks_public:
    resource: '@ContentBlocksBundle/config/routes/public.php'
    prefix: /_content-blocks/public
```

Nothing else changes. The builder shell reads the mount from the router and carries it on `data-cb-api-base`. The package's controllers never write a path themselves, and an app served from a subdirectory gets its base URL included.

Import `editor.php` **as a whole**. The mount is derived from one of its routes, so cherry-picking or re-pathing individual editor routes is refused with an explicit error rather than guessed at.

The firewall then needs no extra path:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        admin:
            pattern: ^/admin
```

## Your own scripts

A [shell fragment](./host-services.md#adding-your-own-ui-to-the-builder-shell) or any script living inside the builder should not hardcode `/_content-blocks` either. Read the mount off the shell, the same way you read the CSRF token:

```js
const shell = element.closest('[data-cb-api-base]');
await fetch(`${shell.dataset.cbApiBase}/area/${areaId}/sections`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': shell.dataset.cbCsrfToken, 'Content-Type': 'application/json' },
    body: JSON.stringify({ layout: 'full' }),
});
```

In Twig, `cb_api_base()` returns the same value, and `path()` with the route name works for any single endpoint.

## The other packages

- **Translation (i18n)**: same model, one family. `@ContentBlocksI18nBundle/config/routes/bare.php` carries the routes without a prefix; its workbench generates every URL with `path()`.
- **Kit**: only public asset routes (`/_content-blocks-kit/public/*`), which belong outside any firewall anyway.
