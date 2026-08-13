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

## New package — Translation / Multilingual ✅

**Shipped** ([#19](https://github.com/klehm/content-blocks-project/pull/19)):
`klehm/content-blocks-i18n`, backend *and* editorial UI — 109 PHP specs, 20
Vitest specs and 4 browser specs, verified end to end against
`content-blocks-sandbox` (FR source + EN/DE/ES). Package docs:
[README](packages/content-blocks-i18n/README.md) ·
[guide](docs/guide/translation.md) ·
[provider recipe](docs/guide/recipes/translation-provider.md).

The schema question the spike left open is answered: **a side table**
(`cb_block_translation`), one row per block per locale, holding a flat map of
field path → value, with collection entries keyed by their `_id`. The envelope
alternative was rejected for being unqueryable — a multilingual site is run from
a progress view, and an envelope cannot produce one without reading every row.

What landed:

- [x] Design spike → side table + per-field fallback (`TRANSLATION-SPIKE.md`, now folded into the package docs)
- [x] Field tagging via the core's frozen `cb_translatable` convention
- [x] Locale-aware render path (`BlockDataResolverInterface` + `RenderContext`), one query per area
- [x] Draft/published lifecycle: translations ride the area's Publish and Discard
- [x] Progress + **staleness** (source digests → translated / outdated / missing)
- [x] Machine-translation seam (batch-shaped `TranslationProviderInterface`) — **no engine shipped**, adapters are the host's; offline `pseudo` demo in the sandbox
- [x] Clone/duplicate interaction via the new core `BlockCloneObserverInterface`
- [x] HTTP API + `content-blocks:i18n:{status,translate}`
- [x] **The workbench UI** — full-page field list with the preview beside it,
      scroll-to-field, inline single-block reload, collapsible preview, as
      specced in [docs/guide/translation-ui-proposal.md](docs/guide/translation-ui-proposal.md).
      It is **not** a Stimulus controller in the end: the workbench is a
      standalone page that never loads the host's bundle, so a package-served
      ES module needs no `controllers.json` entry and no recompilation in any
      host. Its mount point is the host's choice (`config/routes/bare.php`),
      which is what lets a firewall cover it in one pattern.
- [x] **Two additive core seams** it turned out to need, both opt-out and
      behaviour-preserving: `?locale=` on `GET /_content-blocks/block/{id}/render`,
      and `?cb_chrome=0` to render draft content without the builder's editing
      furniture. The second is the more generally useful of the two — a review
      link or an approval step wants exactly that, and nothing else could
      produce it from outside the package.

Still open:

- [ ] Export/import and section templates do **not** carry translations yet —
      the transfer walks need the same observer treatment the cloner got.
- [ ] Per-locale publishing — **the API now exists**: `PublishContext` scopes
      which locales ride along with a publish or discard, and
      `TranslationPublisher` honours it. No flow exposes it yet, deliberately.
      Before designing one, note the constraint: the layout is shared, so a
      per-locale publish can only hold back *values of existing fields* — a
      newly added block appears in every locale at once and renders its source
      text there until translated.

---

## The kit's rich-text CDNs — a default to decide before the freeze 🤔

The translation package settled a principle: **no package sends anything to a third party the host did not ask for.** That is why it ships a seam and no engine. One place in the monorepo still does the opposite by default.

The kit's `rich_text` block loads its editor from a public CDN — TinyMCE from `cdn.jsdelivr.net`, CKEditor from `cdn.ckeditor.com` — because `content_blocks_kit.blocks.rich_text.options.cdn` defaults to `true`. Opening a rich-text block therefore reaches out to a third party unless the host says otherwise.

It is already fully opt-out: `cdn: false` and the host bundles the editor (what `content-blocks-encore-sandbox` does), or `cdn_url` / `cdn_style_url` point at a self-hosted copy. So this is purely a question about the **default**, and defaults are exactly what a 1.0 freezes:

- **Keep `cdn: true`.** Nothing works out of the box otherwise — a fresh install gets a working editor with no bundler step, which is a real part of why the kit is pleasant to try.
- **Flip to `cdn: false`.** Consistent with the rule the translation package just set, and the safer default for the GDPR-sensitive and offline/air-gapped installs that a page builder sold to agencies actually meets. Costs every host one bundling step, and turns a quiet first-run experience into a broken-looking one.
- **A third way**: keep the CDN but make the reach-out *loud* — a startup notice, or a config node with no default that fails fast until the host picks a side.

Not obviously urgent, and deliberately not decided here. It only needs settling before the tag, because reversing a default afterwards is a breaking change for whoever relied on it.

---

## Release — an RC once the feature set is whole, then 1.0 🅿️

**Context.** The last tag is `v0.1.0-beta.7`. The block set, core styling, security model, config surface and docs are in place, and the work that had to land *before* a freeze already has: the 1.0 seams (`RenderContext`, `BlockDataResolverInterface`, collection `_id`, the `_` reserved prefix), the `Block.data` key unification and the upgrade guide are on `master`, unreleased, accumulating under `[Unreleased]` in both CHANGELOGs.

**Direction.** All three items this release was waiting on have now shipped — the kit's rich-text editors, the image-optimization seam, and the translation package. **The next tag is `1.0.0-RC1`.**

The translation package was deliberately sequenced last, on the theory that it was the one most likely to expose a missing core seam — and it did, twice: `RenderContext` had to grow a locale before the freeze, and the workbench needed a way to render a draft without the builder's chrome. Both landed additive, which is the outcome an RC is meant to secure *before* the promise is made rather than after. That question is now settled: the largest satellite anyone is likely to write has been written, and it needed nothing breaking.

Versioning consequence: the unreleased work carries breaking changes (the `Block.data` key renames, the `BlockRendererInterface` signature), and `0.1.0-beta.1` promised those bump to `0.2`. Any tag cut before the RC is therefore `0.2.0-beta.x`, not `0.1.0-beta.8`.

**Rough scope when picked up:**
- [x] The translation package, shipped and documented — backend, workbench, and the two core seams it needed
- [x] Public-surface audit → freeze list + "experimental" markers — run; the outcome is the [backward compatibility page](docs/guide/backward-compatibility.md), and the markers are in the code. The audit found that **not one symbol across 222 PHP files was marked `@internal`, `@experimental` or `@deprecated`**, so tagging as-was would have frozen the 14 controllers, the DI internals, `BlockComponent` and every collaborator signature. Now marked; the four-event `cb:*` contract and all 90 `--cb-*` tokens are declared, the latter guarded by a CI drift check. (Working notes live in `FREEZE-AUDIT.md`, kept out of git via `.git/info/exclude`.)
- [x] **`ContentAreaPublisherInterface` widened before the freeze.** `publish()` and `discardDraft()` take a nullable `PublishContext`; `null` is today's behaviour, so no call site changed. It carries a locale scope the i18n decorator reads, and its shape makes the dangerous ordering inexpressible — a locale can be held back, never pushed ahead of its source. The other seams needed nothing: the transfer walks take their translation observer by constructor injection, exactly as `SectionCloner` did
- [x] Decide the kit's rich-text CDN default (see above) — **decided: keep it as it is.** A default is as frozen as a signature, and this one has run unchanged across all three hosts without a complaint; changing it at the freeze would be trading a known default for an unproven one
- [x] ~~Upgrade guide (beta → stable) + verified migrations~~ — **descoped as a published document.** The package has exactly three hosts, all ours (`em-interpretation`, `ybc`, `efs`), and no third-party install. A public beta → stable guide would be written for nobody. The migrations still get verified — by doing them: [HOST-MIGRATION-RUNBOOK.md](HOST-MIGRATION-RUNBOOK.md) carries the plan, and its §6 journal harvests the guide while the three upgrades happen. If an outside host ever appears, that journal is what gets published
- [ ] Green CI on the full supported matrix (Symfony 6.4/7.x/8.x, PHP 8.2–8.4; PHPUnit + Vitest ×3 + Playwright ×2) — green as of [#19](https://github.com/klehm/content-blocks-project/pull/19) (27 checks), but this is a gate to re-run at tag time, not a box to tick once
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
