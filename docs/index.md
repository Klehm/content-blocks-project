---
layout: home

hero:
  name: ContentBlocks
  text: A page builder that lives inside your Symfony app
  tagline: Build content areas from sections, columns and blocks — in-context, with live preview. Framework-native, extensible, no CMS lock-in.
  image:
    src: /hero.svg
    alt: ContentBlocks
  actions:
    - theme: brand
      text: Quick start
      link: /guide/quickstart
    - theme: alt
      text: Why ContentBlocks?
      link: /guide/
    - theme: alt
      text: Browse the block kit
      link: /kit/

features:
  - icon: 🧩
    title: Attach content anywhere
    details: A ContentArea is a generic container of sections you bolt onto any entity — Page, Product, Category. Your model, your routes, your templates. ContentBlocks never owns the URL.
  - icon: 🖼️
    title: In-context live preview
    details: The builder opens your real public page in an iframe and edits it in place. What editors see is what visitors get — no separate "preview" that drifts.
  - icon: 📦
    title: 17 ready-made blocks
    details: The optional kit ships title, text, rich text, image, gallery, button, card, list, icon, alert, divider, accordion, table, embed, breadcrumb, tabs and a raw-HTML escape hatch — all self-contained, zero CSS-framework dependency.
  - icon: 🔌
    title: Extensible by design
    details: A block is one PHP class with a Symfony form. Tag it with #[AsContentBlock] and it auto-registers. The form IS the data whitelist and validator.
  - icon: 🔒
    title: Secure by default
    details: DenyAll access checker out of the box, CSRF-guarded AJAX, IDOR protection on every mutation, and MIME/size-checked uploads. You wire your auth model; the bundle enforces it.
  - icon: 🎨
    title: Themeable, not opinionated
    details: Neutral cb-kit-* markup styled by a single stylesheet of CSS custom properties. Override the variables — or the whole templates — to match any design system.
---

<div class="cb-home-extra">

## Install in two commands

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
php bin/console doctrine:migrations:diff && php bin/console doctrine:migrations:migrate
```

Then attach a `ContentArea` to your entity, add `ContentAreaType` to a form, and call `cb_render_content_area()` in your template. The [Quick start](/guide/quickstart) walks through the whole path in five minutes.

## The mental model

```
ContentArea  →  Section  →  Column  →  Block
 (container)    (layout)    (preset)   (type + JSON data)
```

`ContentArea` is a **titleless, slug-less container**. The host app brings its own entity (a `Page`, a `Product`…) with a `OneToOne` to a `ContentArea`. That single design choice is why ContentBlocks drops into an existing app instead of asking you to migrate into a CMS.

## What it does — and what it deliberately doesn't

| ✅ ContentBlocks does | ❌ ContentBlocks does not |
|---|---|
| Store structured content (sections / columns / blocks) as your data | Own your routing, URLs, or SEO |
| Render an in-context builder with live preview | Ship a CMS, admin panel, or user management |
| Provide an extensible block-type system | Force a CSS framework (Tailwind/Bootstrap) on you |
| Enforce your auth model via a thin interface | Know who your users are — you wire that |
| Draft / publish / discard content states | Replace your templating — you keep full control of markup |

## Built for humans **and** agents

Installing is a short, deterministic path — and it's documented for AI coding agents too. See [`AGENTS.md`](https://github.com/klehm/content-blocks-project/blob/master/AGENTS.md) and the machine-readable [`llms.txt`](/llms.txt) index.

</div>

<style>
.cb-home-extra {
  max-width: 960px;
  margin: 4rem auto 0;
  padding: 0 24px;
}
.cb-home-extra h2 {
  border-top: 1px solid var(--vp-c-divider);
  padding-top: 2.5rem;
  margin-top: 3rem;
}
.cb-home-extra table { display: table; width: 100%; }
</style>
