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

## Translation / Multilingual — what is still open 🤔

The package shipped in [#19](https://github.com/klehm/content-blocks-project/pull/19): `klehm/content-blocks-i18n`, backend *and* workbench, on a side table (`cb_block_translation`) with per-field fallback, staleness digests and a batch machine-translation seam with no engine shipped. Package docs: [README](packages/content-blocks-i18n/README.md) · [guide](docs/guide/translation.md) · [provider recipe](docs/guide/recipes/translation-provider.md).

The cost of a side table is that nothing carries its rows for free, so every flow that moves content has to be taught about them one by one. Where that stands:

- [x] Clone / duplicate / insert content — through `BlockCloneObserverInterface`
- [x] Export / import — through `ContentAreaTransferExtensionInterface` ([#36](https://github.com/klehm/content-blocks-project/pull/36)); digests are carried, never recomputed
- [ ] **Section templates** do not carry translations yet — a template saved from a translated section instantiates untranslated
- [ ] **Clipboard** paste does not either — same gap, smaller stakes (a copy costs seconds to redo)
- [ ] **Per-locale publishing** — the API exists: `PublishContext` scopes which locales ride along with a publish or discard, and `TranslationPublisher` honours it. No flow exposes it yet, deliberately. Before designing one, note the constraint: the layout is shared, so a per-locale publish can only hold back *values of existing fields* — a newly added block appears in every locale at once and renders its source text there until translated.

---

## Release — the RC cycle, then 1.0 🅿️

**Context.** The candidates are out: `v1.0.0-RC1` (13 Aug), `v1.0.0-RC2` (14 Aug), `v1.0.0-RC3` (24 Aug), `v1.0.0-RC4` (31 Aug), `v1.0.0-RC5` (10 Sep), `v1.0.0-RC6` (14 Sep), `v1.0.0-RC7` (14 Sep), `v1.0.0-RC8` (16 Sep), `v1.0.0-RC9` (16 Sep), `v1.0.0-RC10` (16 Sep), `v1.0.0-RC11` (17 Sep), `v1.0.0-RC12` (17 Sep), `v1.0.0-RC13` (17 Sep), `v1.0.0-RC14` (17 Sep), `v1.0.0-RC15` (21 Sep), `v1.0.0-RC16` (21 Sep). The public surface is frozen as described in the [backward compatibility page](docs/guide/backward-compatibility.md), and the work that had to land *before* the freeze did: the 1.0 seams (`RenderContext`, `BlockDataResolverInterface`, collection `_id`, the `_` reserved prefix), the `Block.data` key unification, the kit's rich-text editors, the image-optimization seam, and the translation package.

The translation package was deliberately sequenced last, on the theory that it was the one most likely to expose a missing core seam — and it did, twice: `RenderContext` had to grow a locale before the freeze, and the workbench needed a way to render a draft without the builder's chrome. Both landed additive, which is the outcome an RC is meant to secure *before* the promise is made rather than after.

**Direction.** RC2 to RC4 were each cut *because a host ran the previous one*. RC2 came out of the first two migrations (a block whose root is an `<a>` was unselectable in the builder; the bundle's own config example named a key the tree rejects). RC3 came out of the next one (a subclassed kit block received none of its configuration; plus `table` starting on an alignment its own form refuses). RC4 came out of the third, and it is the one candidate so far that was worth the whole cycle on its own: an editor rearranged a page **without publishing** and watched the live page change under them. Three reads of draft state in the public render, there since the renderer's first commit, none caught by tests — because no test ever compared the public page across a builder action. Two suites now do.

RC5 and RC6 are different in kind: they carry **additive** work rather than host findings. RC5 brought asset garbage collection, enforced worker-mode safety and shell fragments; RC6 brings undo/redo, the navigator and translations through export/import. Everything in both sits on the frozen surface without changing it, and all of it wants the same thing the earlier candidates did — to be run on a real site before the promise is made.

No candidate since RC1 has touched the shape of `block.data`: no content migration between candidates and no `content_version` bump. RC6 does add one **schema** migration (`cb_action_log`, a new table, nothing existing touched) and one Stimulus controller (`cb-tree`). RC7 is a schema fix and nothing else: join columns are named explicitly, so the schema no longer depends on the host's Doctrine naming strategy. Underscore-naming hosts see no change; default-naming hosts get a `cb_section.contentArea_id` → `content_area_id` rename, which `doctrine:migrations:diff` generates as a `CHANGE`. RC8 is additive again, and needs neither a migration nor a controller: the host chooses where the routes are mounted (`config/routes/editor.php` and `config/routes/public.php`, the builder reading the mount from the router), and adding or deleting a section patches the preview in place instead of reloading it. RC9 is what a new host's first install asked for, and is additive too: a link to the published page from the builder and from each language in the workbench, empty Twig blocks so a host adds to both UIs without copying a template, and `section.initial_settings`, which replaces the Doctrine listener that host had written to give new sections their padding. One fix in it changes behaviour a host may not expect: an override of the workbench template, silently ignored until now, is used. RC10 is a single addition from the same host: the workbench's preview pane can be resized, as the builder's sidebar can. RC11 is additive but brings a **schema** migration: a section's columns become editable (added, named, removed from its sidebar), so `cb_column` gets the draft twins every other mutable field already had, and the i18n package a `cb_column_translation` table for tab titles. On top: host-configured section layouts, tabs and accordion displays, a kit `video` block, and the first fixes from a demo site built on the kit (block text alignment, columns reversed on mobile, an image aspect ratio, dark/light tone classes). Two things render differently without a migration: a *Full width* kit image fills its column, and coloured backgrounds carry tone classes. RC12 answers the demo site's install, with no migration: its Yarn 1 build refused the asset packages for lacking a `version`, and a block added but never edited (a `table` there) was invisible to the translation workbench because its collection entries had no ids yet. It also brings a section background image under an adjustable veil, a kit `button_group` block, one-at-a-time and start-closed accordion options, host keys in preset settings, and a copy-source arrow in the workbench. RC13 is additive, with no migration: a section's display is set per viewport (a grid on desktop can be a slider or an accordion on mobile), a new slider display, and a tablet or mobile order for sections and blocks, dragged in the preview. Host CSS copying the tab-panel selectors needs `:nth-of-type`. RC14 is a fix, with no migration: a firewall's login redirect answering a builder call is a `401` instead of a login page the builder took for a save, and the builder shows a *Session expired* banner, checks the session when the editor returns after a minute idle, and resends the unsaved edit once the session is back. RC15 is additive, with no migration: both sidebars are laid out in tabs and collapsible panels (the section one as Structure and Style, a field sharing its panel with the fields it gates), a choice can be drawn as icon buttons from a registry a host extends, a background image can sit in a corner, and fields are white with a shadow scale for controls. Host CSS or theme overrides of the sidebar need the renamed classes listed in the CHANGELOG. RC16 is additive, with no migration: a live collection's entries fold to a header naming them, with collapse-all and expand-all, and a section's or block's inline style no longer repeats spacing values the stylesheet already resolves.

**What 1.0 is now waiting on:** the rest of the third migration — the host with real editorial volume. Its first RC6 install already turned up one finding: the host runs Doctrine's default naming strategy, which none of the three sandboxes do, and `cb_action_log` could not be created there. That is RC7, and the mapping test that now holds the schema identical under both strategies. The host takes RC7 (the RC4 migration, the RC6 table, the join-column rename, the pages to re-check); RC8 to RC10 change nothing it has to migrate, RC11 adds the column twins (`Version20260917120000`, which pins already-published presets), RC12 asks for one `content-blocks:backfill-collection-ids` run; RC13 to RC16 need nothing; whatever else the host turns up is either an RC17 or the go-ahead for the stable tag.

**Rough scope:**
- [x] The translation package, shipped and documented — backend, workbench, and the two core seams it needed
- [x] Public-surface audit → freeze list + "experimental" markers — the outcome is the [backward compatibility page](docs/guide/backward-compatibility.md), and the markers are in the code. Not one symbol across 222 PHP files was marked `@internal`, `@experimental` or `@deprecated` before it, so tagging as-was would have frozen the controllers, the DI internals, `BlockComponent` and every collaborator signature. (Working notes live in `FREEZE-AUDIT.md`, kept out of git via `.git/info/exclude`.)
- [x] **`ContentAreaPublisherInterface` widened before the freeze.** `publish()` and `discardDraft()` take a nullable `PublishContext`; `null` is today's behaviour. Its shape makes the dangerous ordering inexpressible — a locale can be held back, never pushed ahead of its source
- [x] The kit's rich-text CDN default — **decided: keep `cdn: true`.** A default is as frozen as a signature, and this one has run unchanged across all three hosts without a complaint. Fully opt-out already (`cdn: false`, or `cdn_url` / `cdn_style_url` to a self-hosted copy)
- [x] ~~Upgrade guide (beta → stable) + verified migrations~~ — **descoped as a published document.** Three hosts, all ours, no third-party install. The migrations still get verified — by doing them, against a runbook kept outside this repository
- [x] Green CI on the full supported matrix (Symfony 6.4/7.x/8.x, PHP 8.2–8.4; PHPUnit + Vitest ×3 + Playwright ×2) — **a gate re-run at every tag.** The split job `needs` all test jobs, so a red matrix reaches neither the mirrors nor Packagist
- [x] **`v1.0.0-RC1`** — distribution verified end to end: `composer require klehm/content-blocks:^1.0@RC` resolves from Packagist. The `v` is not cosmetic: `ci.yml` triggers on `tags: ['v*']`
- [x] **`v1.0.0-RC2`** — the first two migrations' findings
- [x] **`v1.0.0-RC3`** — a subclassed kit block gets its configuration; `table`'s defaults are confronted with its own choice fields
- [x] **`v1.0.0-RC4`** — the published render is immutable until Publish. Needs a migration: a new column, and a `published_at` backfill without which already-live sections vanish
- [x] **`v1.0.0-RC5`** — asset GC (`content-blocks:assets:gc`), worker mode enforced, shell fragments. No migration
- [x] **`v1.0.0-RC6`** — undo/redo, the navigator, translations through export/import. Needs a migration (`cb_action_log`) and the `cb-tree` controller in `controllers.json`
- [x] **`v1.0.0-RC7`** — join columns named explicitly, so the schema no longer depends on the host's Doctrine naming strategy. Default-naming hosts rename `cb_section.contentArea_id` (generated by `migrations:diff`)
- [x] **`v1.0.0-RC8`** — the route mount belongs to the host (the builder can live under `/admin`, public assets imported apart); sections added and deleted without reloading the preview. No migration. A forked `builder/shell.html.twig` needs `data-cb-api-base`
- [x] **`v1.0.0-RC9`** — "View page" in the builder and per-language links in the workbench, empty Twig blocks for host additions, `section.initial_settings`, the workbench's back-URL seam. No migration. A workbench template override now takes effect
- [x] **`v1.0.0-RC10`** — the translation workbench's preview is resizable (drag, arrow keys, remembered per browser). No migration
- [x] **`v1.0.0-RC11`** — host section layouts, editable columns shown as a grid, tabs or accordion (translated titles), a kit `video` block, block text alignment, columns reversed on mobile, image aspect ratio, dark/light tone classes, and an autosave fix. Migrations: column draft twins, i18n column translations
- [x] **`v1.0.0-RC12`** — asset packages installable with Yarn 1, collection ids minted at block creation (untouched blocks become translatable), section background image with veil, kit `button_group`, accordion options, host keys in preset settings, workbench copy-source arrow. No migration; run `content-blocks:backfill-collection-ids` once
- [x] **`v1.0.0-RC13`** — section display per viewport, slider display (arrows, dots, autoplay, loop), section and block order per viewport dragged in the preview, sections dragged by their label. No migration. Host CSS copying the tab-panel selectors needs `:nth-of-type`
- [x] **`v1.0.0-RC14`** — an expired session is told apart from a save: `401` + *Session expired* banner, session checked after idle, unsaved edit resent. No migration
- [x] **`v1.0.0-RC15`** — sidebars laid out in tabs and collapsible panels (`cb_group`, `cb_panel`, `cb_help_tooltip`), icon choices (`cb_icons`, `UiIconProviderInterface`), background image in a corner, white fields and an elevation scale. No migration; sidebar CSS classes renamed
- [x] **`v1.0.0-RC16`** — live collection entries fold to a named header (collapse / expand all), redundant inline spacing variables dropped. No migration
- [ ] The last host migration, finished — the go/no-go for stable, and the source of an RC17 if there is one
- [ ] Finalize docs site + stable release notes
- [ ] Tag `v1.0.0`, verify Packagist split

---

## Publication history — revisions of a published page 💡 (post-1.0, on demand)

**The problem.** Publish is a one-way door. Discard rescues an unpublished draft, the snackbar one delete, `Ctrl/Cmd-Z` the current session — all three stop at the last publish. Once an editor has published there is no way back to what the page said last week short of restoring the database.

**Why this is an idea and not a plan.** No editor on any of the three hosts has asked for it. Every candidate that mattered in the RC cycle came from a host running the code, not from reasoning about it, and this feature is large enough that building it ahead of a real request is the wrong bet.

**What it would actually cost.** Assets turn out to be the *cheap* part, not the hard one:

- Uploaded files are never overwritten (`LocalFileStorage` names each one with random bytes), so a path in a revision always points at the bytes it pointed at.
- The GC is mark & sweep, not reference counting: a revision provider is the same ~45 lines as `SectionTemplateAssetReferenceProvider`, and without `assets:gc --force` nothing is ever deleted anyway.
- The real asset consequence is that **storage stops shrinking** while a revision references a file — which makes it a retention question, not an asset question.

The expensive parts are elsewhere:

- **Content versions.** `DenyOnMismatchUpgrader` refuses a known version gap by default, so every revision older than a `content_version` bump becomes unrestorable unless the host writes an upgrader. "Go back to last week" would silently stop working after every schema migration.
- **Translations** live in a side table, so each revision has to snapshot its rows too — and section templates and the clipboard do not do that yet either.
- **Restoring over a dirty draft** needs a rule (refuse, or warn and overwrite) and UI to say it, and has to sit correctly with the undo stack.
- **Retention** is a config node, a pruning command and a default — another default frozen once shipped.

**If it is asked for, build the smallest step that answers the request, in this order:**

1. **A recipe, no package code.** The host decorates `ContentAreaPublisherInterface` and writes an export after each publish, stored wherever it likes. The export already embeds assets as base64 and, since RC6, translations; it re-imports into the draft. A restorable backup for ~30 lines of host code and zero package debt.
2. **"Revert to the previous publish" only.** One revision per area: a bounded table, no retention policy, a trivial asset provider. Covers the common case — "I just published a mistake".
3. **Full history**, only if 2 proves insufficient. Recording decorator on the publisher *after* the inner publish (so it sees what the i18n decorator committed), `SectionTemplateSerializer`-shaped payload (not the exporter's base64), restore through the `ReplaceController` walk into the draft — never a button that republishes directly.

---

## Adding to this roadmap

Keep entries outcome-oriented: what problem, what direction, and (for larger ones) a rough scope checklist. Move an item to the relevant package `CHANGELOG.md` when it ships, and delete it here.
