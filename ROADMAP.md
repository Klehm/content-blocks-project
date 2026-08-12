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

## Action history — Ctrl+Z to undo the last action 🅿️ (post-1.0)

**Context.** Today the only rescue is scoped: a delete offers an "Undo" snackbar for a few seconds, and Discard reverts *everything* unpublished. Between those two there is nothing — a move, a duplicate, a paste, a settings change or a block edit is final until the editor undoes it by hand. Editors coming from any other builder expect `Ctrl/Cmd-Z`.

**Direction.** A per-session, draft-scoped **action journal**, undone by replaying an inverse operation server-side — not by snapshotting the whole area. Every structural mutation already funnels through a small set of endpoints (`section|block create/move/duplicate/delete/restore`, `paste`, `replace-with`, the sidebar `settings` saves) and, client-side, through the builder's `_mutationQueue`; those are the two chokepoints where an entry gets recorded and where an undo is applied, so the feature does not need every call site to opt in.

Open design questions, worth settling before coding:

- **Where the journal lives**: a `cb_action_log` table (survives reload, needs a migration, needs pruning) vs in-memory in the builder session (free, but lost on refresh — and refresh is exactly when an editor panics). Leaning table, keyed by content area + a builder session id, pruned on publish/discard.
- **Granularity of a block edit**: the sidebar autosaves, so a naive journal turns one paragraph of typing into forty undo steps. Needs coalescing (same block + same field + short window = one entry).
- **What an inverse is** for each operation — trivial for create/delete/move, less so for `replace-with` and `paste` (the inverse is a bulk delete of exactly what was inserted, so the journal must record the ids it produced).
- **Redo**, and whether the stack survives a page reload.
- **Concurrency**: two editors on the same area must not undo each other's work — the journal is per builder session, and an entry whose target moved under it is refused rather than guessed.

**Rough scope when picked up:**
- [ ] Design note: journal storage, entry shape, coalescing rules, inverse per operation
- [ ] Recording seam at the mutation chokepoint (server) + `_mutationQueue` (client)
- [ ] `POST /_content-blocks/area/{id}/undo` (and `/redo`), CSRF + `canEdit` on the target area
- [ ] `Ctrl/Cmd-Z` binding, sharing the clipboard shortcuts' rules (relayed from the iframe, yields to text editing)
- [ ] Pruning on publish/discard + a migration for hosts
- [ ] Tests: PHPUnit on the inverses, Vitest on the stack, Playwright on the round trip

---

## Tree view — the whole area as an outline 🅿️ (post-1.0)

**Context.** The builder shows content as it renders, which is the right default and a poor way to see a long page. There is no view that answers "what is in this area, in order" — and no way to move a block from the top of the page to the bottom without dragging past everything in between.

**Direction.** A topbar toggle opening the full outline — sections → columns → blocks — with drag-to-reorder, duplicate and delete on every node. Selecting a node opens the same sidebar a click in the preview opens, so the tree is a second way to reach existing state, not a second state to keep in sync.

Two placements to choose between: a **floating panel over the preview** (fast to open and dismiss, overlaps the content it describes) or a **sidebar mode** (permanent, costs preview width, composes badly with the edit sidebar that already lives there). The floating panel looks likelier; worth a mockup before committing.

Most of the backend already exists: section and block `move`, `duplicate` and `delete` are endpoints today, and the poster builder (`SectionPosterBuilder`) already proves the payload can describe an area's structure without knowing any block's data shape. Two gaps:

- **A tree payload**: one `GET` returning the area's structure with a per-node label. Block labels want the same seam the section-library thumbnails use — `BlockPreviewHintInterface` already yields a heading/text/image summary, and a type that does not implement it falls back to its label.
- **Columns are not first-class**: they are derived from the section's layout and column widths, with no CRUD of their own. Reordering columns is plausible (swap presets + reassign blocks); duplicating or deleting one means deciding what happens to the section's layout. Possibly the tree exposes columns as read-only nodes in v1 and only blocks and sections are actionable.

**Rough scope when picked up:**
- [ ] Placement decision (floating panel vs sidebar) — mockup first
- [ ] `GET /_content-blocks/area/{id}/tree` + per-node labels via `BlockPreviewHintInterface`
- [ ] `cb-tree` Stimulus controller (declare in `assets/package.json` + the three sandboxes' `controllers.json`)
- [ ] Reorder / duplicate / delete wired to the existing endpoints, through `_mutationQueue`
- [ ] Decide the column story (read-only nodes vs real column operations)
- [ ] Selection sync both ways: tree → sidebar, preview click → highlighted node
- [ ] Tests: Vitest on the controller, Playwright on reorder + duplicate + delete from the tree

---

## Adding to this roadmap

Keep entries outcome-oriented: what problem, what direction, and (for larger ones) a rough scope checklist. Move an item to the relevant package `CHANGELOG.md` when it ships, and delete it here.
