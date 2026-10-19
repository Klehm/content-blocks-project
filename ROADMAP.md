# Roadmap

Planned and under-consideration work for ContentBlocks. This is a living document — items here are directions, not commitments, and may change. Shipped items move to the packages' `CHANGELOG.md`.

Legend: 🅿️ planned · 🤔 under consideration · 💡 idea

---

## Release — the RC cycle, then 1.0 ✅

**`v1.0.0` shipped on 23 Sep**, once RC17 had run on the production host without a finding. What follows is the record of how it got there.

**Context.** The candidates are out: `v1.0.0-RC1` (13 Aug), `v1.0.0-RC2` (14 Aug), `v1.0.0-RC3` (24 Aug), `v1.0.0-RC4` (31 Aug), `v1.0.0-RC5` (10 Sep), `v1.0.0-RC6` (14 Sep), `v1.0.0-RC7` (14 Sep), `v1.0.0-RC8` (16 Sep), `v1.0.0-RC9` (16 Sep), `v1.0.0-RC10` (16 Sep), `v1.0.0-RC11` (17 Sep), `v1.0.0-RC12` (17 Sep), `v1.0.0-RC13` (17 Sep), `v1.0.0-RC14` (17 Sep), `v1.0.0-RC15` (21 Sep), `v1.0.0-RC16` (21 Sep), `v1.0.0-RC17` (23 Sep). The public surface is frozen as described in the [backward compatibility page](docs/guide/backward-compatibility.md), and the work that had to land *before* the freeze did: the 1.0 seams (`RenderContext`, `BlockDataResolverInterface`, collection `_id`, the `_` reserved prefix), the `Block.data` key unification, the kit's rich-text editors, the image-optimization seam, and the translation package.

The translation package was deliberately sequenced last, on the theory that it was the one most likely to expose a missing core seam — and it did, twice: `RenderContext` had to grow a locale before the freeze, and the workbench needed a way to render a draft without the builder's chrome. Both landed additive, which is the outcome an RC is meant to secure *before* the promise is made rather than after.

**Direction.** RC2 to RC4 were each cut *because a host ran the previous one*. RC2 came out of the first two migrations (a block whose root is an `<a>` was unselectable in the builder; the bundle's own config example named a key the tree rejects). RC3 came out of the next one (a subclassed kit block received none of its configuration; plus `table` starting on an alignment its own form refuses). RC4 came out of the third, and it is the one candidate so far that was worth the whole cycle on its own: an editor rearranged a page **without publishing** and watched the live page change under them. Three reads of draft state in the public render, there since the renderer's first commit, none caught by tests — because no test ever compared the public page across a builder action. Two suites now do.

RC5 and RC6 are different in kind: they carry **additive** work rather than host findings. RC5 brought asset garbage collection, enforced worker-mode safety and shell fragments; RC6 brings undo/redo, the navigator and translations through export/import. Everything in both sits on the frozen surface without changing it, and all of it wants the same thing the earlier candidates did — to be run on a real site before the promise is made.

No candidate since RC1 has touched the shape of `block.data`: no content migration between candidates and no `content_version` bump. RC6 does add one **schema** migration (`cb_action_log`, a new table, nothing existing touched) and one Stimulus controller (`cb-tree`). RC7 is a schema fix and nothing else: join columns are named explicitly, so the schema no longer depends on the host's Doctrine naming strategy. Underscore-naming hosts see no change; default-naming hosts get a `cb_section.contentArea_id` → `content_area_id` rename, which `doctrine:migrations:diff` generates as a `CHANGE`. RC8 is additive again, and needs neither a migration nor a controller: the host chooses where the routes are mounted (`config/routes/editor.php` and `config/routes/public.php`, the builder reading the mount from the router), and adding or deleting a section patches the preview in place instead of reloading it. RC9 is what a new host's first install asked for, and is additive too: a link to the published page from the builder and from each language in the workbench, empty Twig blocks so a host adds to both UIs without copying a template, and `section.initial_settings`, which replaces the Doctrine listener that host had written to give new sections their padding. One fix in it changes behaviour a host may not expect: an override of the workbench template, silently ignored until now, is used. RC10 is a single addition from the same host: the workbench's preview pane can be resized, as the builder's sidebar can. RC11 is additive but brings a **schema** migration: a section's columns become editable (added, named, removed from its sidebar), so `cb_column` gets the draft twins every other mutable field already had, and the i18n package a `cb_column_translation` table for tab titles. On top: host-configured section layouts, tabs and accordion displays, a kit `video` block, and the first fixes from a demo site built on the kit (block text alignment, columns reversed on mobile, an image aspect ratio, dark/light tone classes). Two things render differently without a migration: a *Full width* kit image fills its column, and coloured backgrounds carry tone classes. RC12 answers the demo site's install, with no migration: its Yarn 1 build refused the asset packages for lacking a `version`, and a block added but never edited (a `table` there) was invisible to the translation workbench because its collection entries had no ids yet. It also brings a section background image under an adjustable veil, a kit `button_group` block, one-at-a-time and start-closed accordion options, host keys in preset settings, and a copy-source arrow in the workbench. RC13 is additive, with no migration: a section's display is set per viewport (a grid on desktop can be a slider or an accordion on mobile), a new slider display, and a tablet or mobile order for sections and blocks, dragged in the preview. Host CSS copying the tab-panel selectors needs `:nth-of-type`. RC14 is a fix, with no migration: a firewall's login redirect answering a builder call is a `401` instead of a login page the builder took for a save, and the builder shows a *Session expired* banner, checks the session when the editor returns after a minute idle, and resends the unsaved edit once the session is back. RC15 is additive, with no migration: both sidebars are laid out in tabs and collapsible panels (the section one as Structure and Style, a field sharing its panel with the fields it gates), a choice can be drawn as icon buttons from a registry a host extends, a background image can sit in a corner, and fields are white with a shadow scale for controls. Host CSS or theme overrides of the sidebar need the renamed classes listed in the CHANGELOG. RC16 is additive, with no migration: a live collection's entries fold to a header naming them, with collapse-all and expand-all, and a section's or block's inline style no longer repeats spacing values the stylesheet already resolves.

**RC17 (#49 to #52)**: the work is of a third kind: a **security audit**, plus the transfer rewrite it led to. Stored paths are confined to the upload directory (an editor could read `.env` through the export). Export and replace-with need `canEdit()`. A colour can no longer carry CSS declarations. SVG left the default upload types. Imported, templated and pasted structure goes through `RestoredStructure`. `/upload` checks rights on the builder's area. The kit sanitizes `rich_text` at render and drops `javascript:` links. The i18n preview locale only applies to a session that opened the workbench. On the feature side, the export is now a streamed zip, the import runs in steps (plan / asset / commit) behind a new Import / Export dialog, `cb:notify` is a second inbound event, and `cb_open_entries` chooses which collection entries open (kit collections open folded). No migration, but **this is not additive**. Under the rules of the [backward compatibility page](docs/guide/backward-compatibility.md) it contains breaking changes:
- `ContentAreaImporterInterface::import()` gains a parameter
- the upload MIME default changes
- `/upload` requires `area`
- the export format changes (an RC16 install cannot read an RC17 zip)
- the transfer panel moves to its own template
- two runtime dependencies are added (`maennchen/zipstream-php`, `symfony/html-sanitizer`)

All of these are right to make before the freeze, and they are exactly why it ships as **RC17**, not as 1.0.

**What 1.0 is now waiting on:** the rest of the third migration — the host with real editorial volume. Its first RC6 install already turned up one finding: the host runs Doctrine's default naming strategy, which none of the three sandboxes do, and `cb_action_log` could not be created there. That is RC7, and the mapping test that now holds the schema identical under both strategies. The host takes RC7 (the RC4 migration, the RC6 table, the join-column rename, the pages to re-check); RC8 to RC10 change nothing it has to migrate, RC11 adds the column twins (`Version20260917120000`, which pins already-published presets), RC12 asks for one `content-blocks:backfill-collection-ids` run; RC13 to RC16 need nothing. RC17 needs no migration either, but a host posting to `/upload` from its own script must send `area`, and SVG must be re-allowed explicitly if it is used. Two things now stand between RC17 and the stable tag: the host running it without a finding, and the blockers of the pre-1.0 review below. Most of those are about what the promise covers, not about code.

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
- [x] **Security audit** ([#51](https://github.com/klehm/content-blocks-project/pull/51)): path confinement, `canEdit()` on export / replace-with / upload, CSS-safe colours, no SVG by default, restored structure checked, rich text sanitized at render, safe link schemes, pinned editor CDN files with SRI, 403 on denial, private previews, size caps
- [x] **Transfer rewrite** ([#50](https://github.com/klehm/content-blocks-project/pull/50), [#49](https://github.com/klehm/content-blocks-project/pull/49)): streamed zip export, staged import, Import / Export dialog, imported files held to the upload policy; `cb:notify`; `cb_open_entries`
- [x] **`v1.0.0-RC17`**: the two items above plus the pre-1.0 review below. Carries BC breaks against RC16 (see above). No migration
- [x] The last host migration, finished, on RC17: the go/no-go for stable
- [x] Finalize docs site + stable release notes
- [x] Tag `v1.0.0`, verify Packagist split

### Found by the pre-1.0 review (2026-09-23)

A full pass over the three packages, read the way an outside developer evaluating the bundle would read them. The code itself holds up:
- PHPUnit 1036 / 281 / 174, Vitest 489 / 64 / 28, PHPStan level 8 and php-cs-fixer are all green
- no TODO or FIXME, no class over 600 lines, no platform-specific SQL

What does not hold is the **promise**. The backward compatibility page has drifted from the code, and a few decisions become impossible to take once it is live.

**Blockers.** Each one is either a broken promise or a decision a 1.x release could no longer take:
- [x] **`html_raw` is switched on by any config entry for it.** The prototype's `enabled` defaults to `true`, so `html_raw: { defaults: … }` registers the raw-HTML block, although "`html_raw` ships disabled" is frozen behaviour. Default `enabled` to `null` and resolve it against `DEFAULT_DISABLED`; add a test.
- [x] **`AccessCheckerInterface::canView()` is never called.** Decided: removed. It read as a protection it never gave; an implementation that keeps it still satisfies the interface. Every host implements it and nothing reads it. Publish, discard, import and replace all use `canEdit()`. Adding a method after 1.0 breaks every implementor. Decide now: drop `canView()`, or move to an action-scoped check (`can(ContentArea, string $action)`, or Symfony voters) so publishing rights can differ from editing rights.
- [x] **`BlockTypeInterface`'s static `getType()`, `getLabel()` and `getIcon()`.** Decided: instance methods, before the freeze, with `getName()` on editors and translation providers. Static methods mean one class cannot serve two types, and a label or icon cannot come from config or an injected service. Keep them, or make them instance methods; after 1.0 this can only change in 2.0.
- [x] **The BC page freezes the wrong perimeter.** Rewritten from the code: the page is the whole promise, `@internal` a reminder.
  - Types hosts are told to use are absent, and so internal by the page's own rule: `ContentAreaType` and its options, `PaletteColorType`, `ImageUploadType`, `VideoUploadType`, `BlockTypeRegistry`, `LocalFileStorage`, `#[AsBlockFormExtension]`, `CrossRequestStateScanner`, and the value objects a host has to build to implement a listed interface (`BlockDecoration`, `SectionDecoration`, `BuilderAction`, `BuilderShellFragment`, `PaletteColor`, `SectionStyle`, `StoredAsset`).
  - Twig functions are missing: `cb_api_base`, `cb_color_tone`, `cb_color_is_dark`, `cb_css_color`, `cb_history_state`, `cb_section_layouts`.
  - Tables are missing: `cb_action_log`, `cb_column_translation`.
  - Counts are stale: 19 kit blocks, not 17; 16 controllers, not 15; 38 interfaces, not 35.
  - The intro contradicts itself: "not on this page is internal" vs "`@internal` marks the line", with ~175 files in neither.
- [x] **The HTTP promise freezes 49 undocumented routes' payloads.** Narrowed: route names stay stable, payloads only for upload, export, import, publish, discard and the public assets. They mix REST and RPC (`DELETE /block/{id}` next to `POST /column/{id}/delete`) and use three error conventions (English sentences, codes, `{error, code}`). Narrow the promise to route names plus the routes a host references (`upload`, the public assets, what `path()` and the firewall need), and declare the rest internal. Or normalise the errors to `{error: code, message}` and document everything first.
- [x] **The event contract is misstated.** `cb:ready` is now re-emitted as a DOM event, `cb:block:rendered` is listed, and the `detail` shapes are documented under *Builder events*. `cb:ready` is a `postMessage` from the iframe, not a DOM event: a host cannot listen for it. `cb:block:rendered`, which the rendering and custom-block guides tell integrators to use, is not on the list. No payload shape (`{blockId}`, `{sectionId}`, `{key, areaId, button}`) is documented.
- [x] **Published-state setters are public and frozen**: `Block::setPublishedData`, `setPublishedColumnId`, `Section` / `Column::setPublishedSettings`, `Column::setPublishedPreset`, `ContentArea::setUpdatedAt`. They contradict "nothing writes a published field outside `publish()`". Mark them `@internal`.
- [x] **The published docs are missing `video` and `button_group`.** `docs/.vitepress/data/blocks.json` was not regenerated, and "17 blocks" appears in the README, the kit docs and the guide index.
- [x] **`custom-blocks.md` says a `null` view template renders a key/value dump.** It renders an empty `<div>`, which is what `AbstractBlockType` defaults to.

**Should-fix before the tag.** All cheap:
- Kit: done (accordion groups and tab ids from the block id, unknown config keys refused, tokens on `:root` and wired to the rules they document).
- Accessibility: done (translated ARIA labels, linked images always named, the *Insert content* picker keeps and returns focus, video captions). Reordering blocks from the keyboard (WCAG 2.5.7) is not planned.
- Composer: dependencies declared, `@symfony/ux-live-component` declared as a peer, lower bounds tested (below). The kit's and i18n's core floor is `^1.0.0-RC17`, the release that brings `StaticAssetResponse`.
- Packaging: done (`.gitattributes`, `dev-main` alias, root `LICENSE`, `SECURITY.md`, Flex recipes for 1.0 including i18n).
- Docs: done (RC wording, nav version read from the release, i18n changelog linked, proposal moved to internals, RC17 upgrade section, CSP section, uploads in the quickstart, screenshots, a guide for JS-driven fields, what the i18n package does not do).
- CI: done (docs build on pull requests, `composer audit`, a lowest-dependencies PHPUnit leg, the browser suite on PostgreSQL 16, a check that the block docs match the kit). The lowest-deps run raised the floors to what is tested: `doctrine/orm` ^2.15, UX ^2.36, and i18n conflicts with `symfony/twig-bridge` < 6.4.16.
- Public assets: done (ETag and content-hash URLs, a year of `immutable` cache; `cb_kit_stylesheet_url()` for hosts). The preview needs no CSP nonce: its data travels as JSON blocks.

---

## After 1.0 — additive, by priority 🤔

None of these breaks anything when it lands, which is why they wait. They are what a reviewer comparing ContentBlocks to Sulu, Sonata Page or a Sylius CMS plugin will list.

All of them fit in 1.x under three rules:
- **No method is added to an existing interface.** A new capability is a new optional interface, the way `BlockPreviewHintInterface` is. The `bc-check` CI job holds the PHP side.
- **A host that does nothing sees the same page.** A change to what a page renders is opt-in, behind a config key; making it the default waits for 2.0.
- **Template variables and parameters are added, never removed or renamed.**

Ranked, highest first:

1. **Per-block CSS/JS declaration**, through an optional interface on the block type.
2. **Picker categories**, through an optional `category` argument on `#[AsContentBlock]` or an optional interface. A block without one lands in a default group.
3. **`lang` on text that falls back to the source.** On a German page, an untranslated field shows French text that screen readers, hyphenation and search engines read as German (WCAG 3.1.2). A whole untranslated block can get `lang` on its wrapper through a `BlockDecoratorInterface`, with no template change. A partly translated block needs its view to know which field fell back: views receive `context` (its locale) since 1.5, so what is missing is the per-field status. **Opt-in in 1.x** (`content_blocks_i18n.fallback_lang`), since it changes the public markup.

Not ranked yet:

- **Headless / JSON rendering**: the renderer produces HTML only.
- **More server-side events**, if a host asks: a section saved or deleted, the other flows that write blocks (paste, templates, import). Each is a new before/after pair, so additive.
- **Kit:**
  - dark mode and logical properties (RTL)
  - WebVTT uploads for video captions: a `.vtt` sniffs as `text/plain`, so captions are a path or URL today
  - common blocks still missing: quote / testimonial, spacer, map, social links, code

---

## Media library — pick a file already uploaded 🤔

**The problem.** `ImageUploadType` only knows "upload a new file" or "paste a path". Reusing a logo or a product photo means uploading it again, so the same bytes are stored once per use, and nothing helps an editor find what is already there.

**What exists.** `AssetInventoryInterface::listAssets()` already enumerates stored files (the GC uses it). `AssetReferenceCollector` already knows which blocks reference a file. Both are a start, but a list of paths is not a library: a library needs metadata (alt text, title, dimensions, upload date), search, and probably folders or tags. That means a table.

**Direction: a seam in the core, the library in a satellite package.**

- **Core**: an optional *Browse library* action on the upload widget, shown only when a `MediaLibraryInterface` is registered (the same rule as machine translation: no provider, no button). Whatever the dialog returns is written through the widget's existing single writer (`_setValue`), so the stored value stays a path and nothing downstream changes.
- **`klehm/content-blocks-media`**: a `cb_media` table, a picker dialog served as a shell fragment (no Stimulus controller to declare), metadata editing, and the usage of each file from `AssetReferenceCollector`.
- **Hosts that already have a media system** (Sylius, Sonata Media, a DAM) implement the interface instead of installing the package.

**Points to settle first:**
- Alt text: library default, per-block override, or both? Under i18n it is also per locale.
- The GC must count a file the library holds as referenced, through an `AssetReferenceProviderInterface` (the same pattern as section templates).
- Deleting from the library while a page uses the file: refuse, or warn and list the pages.

---

## On-page SEO audit 💡

**The problem.** The builder shows what a page looks like, not whether it reads well to a search engine. Editors pick a title size for its looks (`title` decouples visual size from tag on purpose) and end up with two `h1`s or an `h2` → `h4` jump without noticing.

**Two layers, and the first does not need the second.**

1. **A deterministic linter**, run on the rendered draft HTML (`cb_chrome=0`), so it sees what a crawler sees, whatever the block types: heading hierarchy (single `h1`, no skipped level), images without `alt`, empty or generic link text ("click here"), very long paragraphs. Each finding points to the block that caused it and focuses it in the builder, the way the Navigator does.
2. **Target keyword checks**: highlight occurrences in the preview, presence in the `h1`, first paragraph and headings, density. A `ContentArea` has no title, slug or meta description, and no keyword: the host's entity holds those. So the host supplies them through a provider interface, and without a provider this layer stays hidden.

"Semantic verification and suggestions" belong to a third layer that needs a language model; see [AI content generation](#ai-content-generation--a-seam-no-engine-) below. Keep it out of the first two, so they stay offline and testable.

**Direction**: a satellite package (`klehm/content-blocks-seo`) with a builder panel delivered as a shell fragment. Several checks overlap accessibility (headings, `alt`, link text); report them once, not in two tools.

---

## AI content generation — a seam, no engine 💡

**The problem.** Editors want a first draft of a section, a rewrite, a shorter version, or `alt` text for an image.

**Same stance as machine translation, for the same reason.** Where a page's text is sent is the host's decision, not a transitive dependency of a page builder. `TranslationProviderInterface` set the precedent: a batch seam, no vendor SDK anywhere, no affordance in the UI without a registered provider, and a sandbox with one offline, deterministic example. Generation should follow the same rules.

**The rule that makes it safe: model output is untrusted input.** It reaches `Block.data` the way a paste does, by replaying each block through its own form (`BlockDataReplayer`). An undeclared key is dropped and a refused value falls back to the type's default, so a hallucinated field can never reach storage. It is written to the draft, journalled (one undo step), and goes live only on Publish.

**Scope, smallest first:**
1. Field-level actions on a text field (rewrite, shorten, translate tone).
2. `alt` text from an image.
3. A whole section from a prompt. This needs the [content schema](#a-content-schema-for-ai--generation-from-the-block-types-) below, so the model knows which blocks exist and what each one accepts.

---

## A content schema for AI — generation from the block types 💡

**The problem.** To produce structured content, a model needs to know which block types exist, what fields each one has, which values they accept, and how sections and columns nest. Today that knowledge exists only as PHP code (forms, constraints, `choiceFields()`).

**What exists.** The kit's `describe()` and the `content-blocks-kit:blocks` command already introspect the kit's blocks. The clipboard's paste path already turns an untrusted structured payload into a draft safely.

**Direction**:
- **Derive a JSON Schema from each block's form**, not from a hand-written description: field types, `Assert\Choice` sets, `Length` limits, collections. A schema derived from the form cannot drift from it, while a hand-written one would. A form field with no clean JSON equivalent falls back to a plain string with the field's help text.
- **Add the structure**: the layouts allowed (`SectionLayoutRegistry`), and the area's structure mode (`sections: hidden` means "blocks only").
- **Export it** as a command (`content-blocks:schema`) and as an endpoint, so it fits a host's own pipeline, or an MCP server.
- **The way back in is the paste path, not the importer.** The importer keeps block data verbatim (it trusts an export), while paste replays every block through its form. Generated content gets the paste treatment: `RestoredSectionBuilder` for structure, `BlockDataReplayer` for data, written to the draft. The schema guides the model; validation does not trust it.

**This is the keystone of the three entries above:** AI generation needs it, the semantic SEO layer benefits from it, and it is useful on its own (documentation, fixtures, a headless client). It is also the cheapest of the four, so it should come first.

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
- **Translations** live in a side table, so each revision has to snapshot its rows too — as section templates and the clipboard now do, through `SnapshotExtensionInterface`.
- **Restoring over a dirty draft** needs a rule (refuse, or warn and overwrite) and UI to say it, and has to sit correctly with the undo stack.
- **Retention** is a config node, a pruning command and a default — another default frozen once shipped.

**If it is asked for, build the smallest step that answers the request, in this order:**

1. **A recipe, no package code.** The host decorates `ContentAreaPublisherInterface` and writes an export after each publish, stored wherever it likes. The export already embeds assets as base64 and, since RC6, translations; it re-imports into the draft. A restorable backup for ~30 lines of host code and zero package debt.
2. **"Revert to the previous publish" only.** One revision per area: a bounded table, no retention policy, a trivial asset provider. Covers the common case — "I just published a mistake".
3. **Full history**, only if 2 proves insufficient. Recording decorator on the publisher *after* the inner publish (so it sees what the i18n decorator committed), `SectionTemplateSerializer`-shaped payload (not the exporter's base64), restore through the `ReplaceController` walk into the draft — never a button that republishes directly.

---

## Adding to this roadmap

Keep entries outcome-oriented: what problem, what direction, and (for larger ones) a rough scope checklist. Move an item to the relevant package `CHANGELOG.md` when it ships, and delete it here.
