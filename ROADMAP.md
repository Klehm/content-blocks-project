# Roadmap

Planned and under-consideration work for ContentBlocks. This is a living document — items here are directions, not commitments, and may change. Shipped items move to the packages' `CHANGELOG.md`.

Legend: 🅿️ planned · 🤔 under consideration · 💡 idea

---

## Image optimization — a concrete adapter 🤔

The seam itself shipped: `ImageUrlResolverInterface` + `ResolvedImage` + a passthrough default live in `klehm/content-blocks`, `cb_image()` exposes them to any template, and the kit's `image`, `gallery` and `card` views resolve through it. A host wires one service and gets `srcset`/`sizes` everywhere.

What is left is optional and outside either package's `require`:

- **LiipImagine / Glide bridge** — a thin adapter, shipped as its own package (e.g. `klehm/content-blocks-liip`) or as a documented recipe + composer `suggest`. Never a hard dependency.
- **A worked CDN recipe** beyond the one in the host-services guide (Cloudflare Images, imgix, Cloudinary each want slightly different URL shapes).

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

## Builder — copy / paste a section or a block 🅿️

**Context.** Duplicating exists, but only in place: `Duplicate` puts a copy right next to the original, and the section library needs a deliberate "save this as a template" step with a name. Neither covers the everyday move — *take this block and put it over there*, in another section, another area, another page. Editors do it by hand today: recreate the block, retype the fields, re-upload the image.

**Direction.** A clipboard on top of the snapshot machinery that already exists, not a new persistence path. `SectionTemplateSerializerInterface` produces a self-contained section payload (`content-blocks/section-v1`, asset references as plain storage paths) and `SectionTemplateInstantiatorInterface` replays it against the *current* block-type registry — skipping types that no longer exist, keeping fields the block cannot hold yet. Copy/paste wants exactly that behavior; the block case needs the equivalent pair one level down.

Interaction rules:

- **Copy is acknowledged.** A copy produces no visible change, so it needs feedback — the "deleted — Undo" snackbar the builder already has, minus the action button. Silent copy is how an editor ends up pasting the wrong thing three times.
- **Pasting a section appends it** after the existing sections of the area.
- **Pasting a block requires a selected section.** With nothing selected there is no answer to *where*, so the action is disabled and says why rather than guessing.
- **A pasted block lands in the first column** of the selected section.
- **It lands right after the selected block**, or at the end of the column when the selection is the section itself.

Open questions worth settling before coding:

- **Where the clipboard lives.** In-memory is trivial and useless across pages — and across pages is the whole point, since in-place copying is what `Duplicate` already does. `localStorage` (or `sessionStorage`) gives cross-tab, cross-area paste for one user. **It also makes the payload user-writable**, so paste must stay a replay through the instantiator + the block's own form validation, never a trusted write. Same rule as import: the payload is input, not truth.
- **A payload copied under an older `content_version`.** Section templates route this through `ContentVersionUpgraderInterface` (`DenyOnMismatchUpgrader` by default). A clipboard is short-lived enough that refusing outright may be the honest answer — but it must be *decided*, not inherited by accident.
- **Whether pasting a section should follow the block rule** and land right after the selected section rather than at the end. Appending is what is specified above; the asymmetry is deliberate but worth a second look once it is in an editor's hands.
- **Keyboard vs menu.** Ctrl/Cmd-C and Ctrl/Cmd-V on the focused entity are the expected gesture, but they must not steal a genuine text selection inside a sidebar form field. Menu entries (and the in-preview toolbar) are the discoverable path either way.

**Rough scope when picked up:**
- [ ] Block-level serializer/instantiator pair mirroring the section ones (or a shared one parameterized by scope)
- [ ] Clipboard store + payload envelope (`format`, `contentVersion`, scope) — decide storage and the mismatch policy
- [ ] Copy affordances (toolbar + menu + keyboard) with the snackbar acknowledgement
- [ ] Paste endpoints writing to draft, gated by `AccessCheckerInterface::canEdit()` on the **target** area, with placement as specified above
- [ ] Paste disabled-with-a-reason when the selection cannot answer "where"
- [ ] Tests: placement rules (first column, after selection, append), an unknown block type in the payload, a tampered payload, cross-area paste

Additive by construction and touching no published contract, so it does not gate the release — it can land on either side of the RC.

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
