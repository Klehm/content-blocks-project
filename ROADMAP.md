# Roadmap

Planned and under-consideration work for ContentBlocks. This is a living document — items here are directions, not commitments, and may change. Shipped items move to the packages' `CHANGELOG.md`.

Legend: 🅿️ planned · 🤔 under consideration · 💡 idea

---

## Image optimization — a concrete adapter 🤔

The seam itself shipped: `ImageUrlResolverInterface` + `ResolvedImage` + a passthrough default live in `klehm/content-blocks`, `cb_image()` exposes them to any template, and the kit's `image`, `gallery` and `card` views resolve through it. A host wires one service and gets `srcset`/`sizes` everywhere.

The LiipImagine side is answered too, as a **recipe rather than a package**: [docs/guide/recipes/liip-imagine.md](docs/guide/recipes/liip-imagine.md) wires compression + WebP in ~40 lines of host code, and `apps/content-blocks-sandbox` runs it under an end-to-end test. A `klehm/content-blocks-liip` bridge would save a host that file and cost everyone a package to version — not obviously worth it, and easy to reverse if hosts keep copying the same class.

What is left is optional and outside either package's `require`:

- **A Glide adapter**, if anyone asks for one — same shape as the LiipImagine recipe.
- **More CDN recipes** beyond the Cloudflare-shaped one in the host-services guide (imgix and Cloudinary want slightly different URL shapes).

Neither blocks the release: the seam is the part that had to exist before the freeze.

---

## New package — Translation / Multilingual 🅿️

**Context.** Hosts need multilingual content areas. The design compromise: **one shared layout** (sections/columns/blocks structure is language-agnostic) but **translatable blocks/fields** — the same visual skeleton renders per language, with only tagged field values swapped.

**Direction.** A dedicated package (e.g. `klehm/content-blocks-translation`) so the core stays single-language by default:

- **Field-level opt-in**: let a block dev *tag* which fields are translatable; those (and only those) surface in a dedicated per-field translation UI. Untagged fields (and the whole layout structure) stay shared across languages.
- **Language-aware rendering**: load a `ContentArea` in a given locale — defaulting to the Symfony request/translator context (`LocaleAwareInterface` / `Request::getLocale`) unless overridden.
- **Data schema — the open design question**: decide between (a) a side table of per-field, per-locale overrides keyed by `(block_id, field, locale)`, vs (b) a locale-keyed JSON envelope inside `Block.data`, vs (c) cloned per-locale blocks sharing a layout id. Trade-offs: migration/diff cost, query complexity, fallback-to-default-locale behavior, and how it interacts with the draft/publish + replace-content flows. **Needs a schema spike before implementation.**

**Rough scope when picked up:**
- [ ] Design spike: data schema (side-table vs JSON envelope vs cloned blocks) + fallback rules — write it up before coding
- [ ] Field-tagging mechanism (attribute/metadata on block fields) → drives the translatable-field allow-list
- [ ] Per-field translation UI (field-by-field, per locale)
- [ ] Locale-aware render path (default from Symfony context, explicit override)
- [ ] Interaction with draft/publish + replace-content; migration story

---

## Release — an RC once the feature set is whole, then 1.0 🅿️

**Context.** The last tag is `v0.1.0-beta.7`. The block set, core styling, security model, config surface and docs are in place, and the work that had to land *before* a freeze already has: the 1.0 seams (`RenderContext`, `BlockDataResolverInterface`, collection `_id`, the `_` reserved prefix), the `Block.data` key unification and the upgrade guide are on `master`, unreleased, accumulating under `[Unreleased]` in both CHANGELOGs.

**Direction.** Stay on the beta line and finish the feature set first — an RC is only worth testing once hosts can exercise what 1.0 will actually contain. Of the three items this release was waiting on, two have shipped (the kit's rich-text editors, the image-optimization seam); **the translation package ships first**, *then* `1.0.0-RC1`, *then* the stable tag.

Why that one and not the others: the translation package is the one that would most likely expose a missing core seam, and finding that during an RC — after the freeze promise — is exactly what an RC is meant to prevent. The two that already landed were additive by construction, so they cost the freeze nothing while rounding out what a host gets on day one.

Versioning consequence: the unreleased work carries breaking changes (the `Block.data` key renames, the `BlockRendererInterface` signature), and `0.1.0-beta.1` promised those bump to `0.2`. Any tag cut before the RC is therefore `0.2.0-beta.x`, not `0.1.0-beta.8`.

**Rough scope when picked up:**
- [ ] The translation package, shipped and documented
- [ ] Public-surface audit → freeze list + "experimental" markers
- [ ] Upgrade guide (beta → stable) + verified migrations — drafted, revisit once the three items land
- [ ] Green CI on the full supported matrix (Symfony 6.4/7.x/8.x, PHP 8.2–8.4; PHPUnit + Vitest + Playwright ×2)
- [ ] Tag `1.0.0-RC1`, let hosts run it
- [ ] Finalize docs site + stable release notes
- [ ] Tag `v1.0.0`, verify Packagist split

---

## Adding to this roadmap

Keep entries outcome-oriented: what problem, what direction, and (for larger ones) a rough scope checklist. Move an item to the relevant package `CHANGELOG.md` when it ships, and delete it here.
