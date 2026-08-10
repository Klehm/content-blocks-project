# Roadmap

Planned and under-consideration work for ContentBlocks. This is a living document — items here are directions, not commitments, and may change. Shipped items move to the packages' `CHANGELOG.md`.

Legend: 🅿️ planned · 🤔 under consideration · 💡 idea

---

## Kit — image optimization seam 🅿️

**Context.** The kit is deliberately dependency-free: the `image` block serves the uploaded file **as-is** and only controls the *display* box via CSS (`width`/`height`, `object-fit`, `border-radius`, `loading="lazy"`). It already does the free wins (no CLS, lazy loading), but it does **not** reduce byte size — there is no responsive `srcset`, no WebP/AVIF, no server-side thumbnails. That inherently requires either an image-processing library (LiipImagine/Glide/GD/Imagick) or a transforming CDN, neither of which the kit should hard-depend on.

**Direction.** Add a *seam*, not a dependency — mirroring the project's existing "interface + passthrough default" pattern (`FileStorageInterface`, `AccessCheckerInterface`, `ContentAreaUrlResolverInterface`):

- Introduce `ImageUrlResolverInterface::resolve(string $src, ?int $width, ?int $height): ResolvedImage` where `ResolvedImage` carries `{ src, srcset?, sizes? }`.
- Ship a default `PassthroughImageUrlResolver` returning `src` unchanged → **the current zero-dependency behavior, unchanged and backward-compatible**.
- The `image` view template calls the resolver and emits `srcset`/`sizes` only when the resolver provides them. The kit **already computes** the target display widths (sm=400, md=800, lg=1200, custom) — exactly the input a resolver needs.

**Opt-in optimization then lives outside the kit's `require`:**
- **CDN** (Cloudflare Images, imgix, Cloudinary) — the resolver just builds URLs with transform params. Zero PHP processing dependency; likely the best "optimized, turnkey" path for production hosts.
- **LiipImagine / Glide** — a thin bridge, shipped as a separate package (e.g. `klehm/content-blocks-kit-liip`) or a documented recipe + composer `suggest`. Never a hard dependency.

**Why this shape.** Keeps the kit's "self-contained, drops into any host" promise intact while unblocking real byte-size optimization as a one-line host opt-in. The plumbing (interface + passthrough default + `srcset` in the template) is small, dependency-free, and non-breaking, and can land before any concrete adapter exists.

**Rough scope when picked up:**
- [ ] `ImageUrlResolverInterface` + `ResolvedImage` value object (kit or core)
- [ ] `PassthroughImageUrlResolver` default + autowiring
- [ ] Route the computed display width/height through the resolver in `image/view.html.twig`, render `srcset`/`sizes`
- [ ] Unit test: passthrough emits today's markup exactly; a fake resolver emits `srcset`
- [ ] Recipe: wire a CDN resolver · Recipe/bridge: LiipImagine

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

**Direction.** Stay on the beta line and finish the feature set first — an RC is only worth testing once hosts can exercise what 1.0 will actually contain. **The three items above ship first** (translation package, kit rich-text blocks, kit image-optimization seam), *then* `1.0.0-RC1`, *then* the stable tag.

Why this order: the translation package is the one that would most likely expose a missing core seam, and finding that during an RC — after the freeze promise — is exactly what an RC is meant to prevent. The rich-text blocks and the image seam are additive by construction, so they cost the freeze nothing but round out what a host gets on day one.

Versioning consequence: the unreleased work carries breaking changes (the `Block.data` key renames, the `BlockRendererInterface` signature), and `0.1.0-beta.1` promised those bump to `0.2`. Any tag cut before the RC is therefore `0.2.0-beta.x`, not `0.1.0-beta.8`.

**Rough scope when picked up:**
- [ ] The three feature items above, shipped and documented
- [ ] Public-surface audit → freeze list + "experimental" markers
- [ ] Upgrade guide (beta → stable) + verified migrations — drafted, revisit once the three items land
- [ ] Green CI on the full supported matrix (Symfony 6.4/7.x/8.x, PHP 8.2–8.4; PHPUnit + Vitest + Playwright ×2)
- [ ] Tag `1.0.0-RC1`, let hosts run it
- [ ] Finalize docs site + stable release notes
- [ ] Tag `v1.0.0`, verify Packagist split

---

## Adding to this roadmap

Keep entries outcome-oriented: what problem, what direction, and (for larger ones) a rough scope checklist. Move an item to the relevant package `CHANGELOG.md` when it ships, and delete it here.
