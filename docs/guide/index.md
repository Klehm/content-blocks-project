---
title: Introduction
---

# What is ContentBlocks?

ContentBlocks is a **modular page builder for Symfony**. It lets editors compose rich content — sections, columns and blocks — directly inside your application, with a live in-context preview, without turning your app into a CMS.

It ships as two Composer packages:

| Package | What it gives you |
|---|---|
| [`klehm/content-blocks`](https://github.com/klehm/content-blocks) | The core: Doctrine entities, the admin builder UI (Live Components + Stimulus), the `ContentAreaType` form, and the extensible block-type system. |
| [`klehm/content-blocks-kit`](https://github.com/klehm/content-blocks-kit) | An optional set of **17 ready-to-use, self-contained blocks** (title, text, image, gallery, button…). See the [Block Kit](/kit/). |

## The core idea

The central entity is `ContentArea` — a **generic, titleless, slug-less container** of sections. It is not a page. It has no URL. Your application owns those.

```
ContentArea  →  Section  →  Column  →  Block
```

You attach a `ContentArea` to *your own* entity via a plain Doctrine relation:

```php
#[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
private ?ContentArea $contentArea = null;
```

That's the whole integration contract. A `Page`, a `Product`, a `Category` — anything with a public URL can carry editable content areas. ContentBlocks handles the structured content and the editing experience; **you keep routing, templates, SEO and auth**.

## In-context editing, real preview

Most builders show you an approximation and hope the front-end matches. ContentBlocks instead **opens your actual public page in an iframe** and injects the editing UI into it. Editors manipulate the real rendered markup — so the preview can't drift from production.

Rendering has two modes, auto-detected per request:

- **Public** — clean, published HTML. No markers, no overlay. This is what visitors get.
- **Preview** — the same HTML plus editing markers and the overlay script, shown only when `AccessCheckerInterface::canEdit()` grants access and `?cb_preview=1` is present.

## What it is not

ContentBlocks is deliberately **not** a CMS. It has no admin panel, no user model, no routing, and no opinion about your CSS framework. It gives you a content-editing capability you embed into an app you already own.

- It does **not** own your URLs or SEO.
- It does **not** ship authentication — you wire your auth model through a thin [`AccessCheckerInterface`](./host-services.md#accesscheckerinterface-authorization).
- It does **not** force Tailwind, Bootstrap, or any icon library on you — the kit renders neutral markup styled by CSS custom properties.
- It does **not** take over your templates — you decide the final markup and can [override every render template](./rendering.md#overriding-render-templates).

## Vision

The goal is a page builder that feels like **a native part of a Symfony application** rather than a platform you migrate into:

- **Framework-native.** Doctrine entities, Symfony forms, Live Components, Stimulus. No bespoke runtime, no proprietary storage.
- **Extensible before it is featureful.** A block is one class + one form. The block-type system, the styling sub-form, the defaults providers and the decorators are all extension points, not walls.
- **Host-owned, not tool-owned.** Your entity, your URL, your auth, your markup. ContentBlocks fills exactly one gap — structured, editable content — and gets out of the way.
- **Secure and predictable by default.** Deny-all access, CSRF everywhere, the form as the data whitelist. You opt into surface, never out of safety.

## Where to next

- **[Quick start](./quickstart.md)** — from `composer require` to a rendered content area in five minutes.
- **[Installation](./installation.md)** — the full install, with and without Symfony Flex.
- **[Core concepts](./concepts.md)** — the data model, the block-type system, and the Live Components + Stimulus split.
- **[Block Kit](/kit/)** — the 17 shipped blocks, documented one by one.
