# Roadmap

Planned and under-consideration work for ContentBlocks. This is a living document — items here are directions, not commitments, and may change. Shipped items move to the packages' `CHANGELOG.md`.

Legend: 🅿️ planned · 🤔 under consideration · 💡 idea

---

## Kit — image optimization seam 🤔

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

## Kit — rich-text blocks (TinyMCE & CKEditor) with overridable init 🅿️

**Context.** The kit ships a neutral `rich_text` block, but real editors want a full WYSIWYG. Two ecosystems dominate — **TinyMCE** and **CKEditor** — and hosts are rarely neutral: they already standardize on one. Hard-wiring either (or a single init config) would break the kit's "drops into any host" promise and fight whatever the host already loads.

**Direction.** Ship the two rich-text blocks as **opt-in** kit blocks (like `html_raw`, off unless enabled), each self-contained but with a documented seam to override the editor's init config:

- Two new kit blocks (`rich_text_tinymce`, `rich_text_ckeditor`) — Stimulus-driven, mounting the editor on a textarea, persisting HTML like the current `rich_text`.
- **Override API**: the integrator supplies the init/config object without forking the block — via a Stimulus value / `data-*` config seam and/or a JS hook (e.g. `window`-scoped registry or a controller they extend), so the host's toolbar/plugins/branding win. Default init = a sane neutral toolbar.
- Editor JS itself stays a **host concern** (importmap/CDN), not a kit `require` — the block wires the bridge, the host brings the library. Mirror the existing core TinyMCE bridge where it makes sense (palette `color_map` via `cb_color_palette()`).
- Same raw-HTML caveat as `html_raw` → these blocks trust their editors; document the trust boundary.

**Rough scope when picked up:**
- [ ] `rich_text_tinymce` + `rich_text_ckeditor` blocks (opt-in, in `DEFAULT_DISABLED`)
- [ ] Stimulus controller(s) mounting each editor on a textarea; HTML persisted + sanitization note
- [ ] Init-config override seam (data-config / JS hook / extendable controller) — host config wins, neutral default otherwise
- [ ] Palette bridge (swatches / color_map) reused from core where applicable
- [ ] Docs: enable + override recipe for each editor; trust-boundary note

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

## Core — Flex recipe for asset wiring 🅿️

The Stimulus controllers + admin CSS (`assets/controllers.json`) and the `sortablejs` importmap entry are currently a manual install step (documented in [Installation](https://klehm.github.io/content-blocks-project/guide/installation)). A Symfony Flex recipe that injects this automatically is planned — once published, the manual step goes away.

---

## Release — stabilize and ship a first stable version 🅿️

**Context.** The project is on `v0.1.0-beta.7`. The block set, core styling, security model, config surface and docs are in place. The goal now is to converge the beta line into a **first stable release** hosts can depend on with a real semver guarantee.

**Direction.** Freeze and harden the public surface rather than add features:

- **API freeze**: audit the public seams (interfaces, config keys, block data shapes, Twig namespaces/templates meant for override) and lock what's stable; mark anything still experimental.
- **Backward-compat & upgrade**: document breaking changes accumulated over the beta line; ensure migrations exist for schema changes (e.g. `cb_content_area.updated_at`) with a clean upgrade path.
- **Test & CI confidence**: full green across core + kit (PHPUnit, Vitest, Playwright) on the supported matrix (Symfony 6.4/7.x/8.x, PHP 8.2–8.4).
- **Docs & CHANGELOG**: finalize the docs site, write the stable release notes, tag `v1.0.0` and let the split CI propagate to the read-only repos.

**Rough scope when picked up:**
- [ ] Public-surface audit → freeze list + "experimental" markers
- [ ] Upgrade guide (beta → stable) + verified migrations
- [ ] Green CI on the full supported matrix
- [ ] Finalize docs site + stable release notes
- [ ] Tag `v1.0.0`, verify Packagist split

---

## Adding to this roadmap

Keep entries outcome-oriented: what problem, what direction, and (for larger ones) a rough scope checklist. Move an item to the relevant package `CHANGELOG.md` when it ships, and delete it here.
