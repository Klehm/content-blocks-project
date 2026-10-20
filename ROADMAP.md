# Roadmap

Planned and under-consideration work for ContentBlocks. This is a living document — items here are directions, not commitments, and may change. Shipped items move to the packages' `CHANGELOG.md`.

Legend: 🅿️ planned · 🤔 under consideration · 💡 idea

---

## Additive work for 1.x 🤔

`v1.0.0` shipped on 23 Sep 2026; its RC cycle is recorded in the packages' `CHANGELOG.md`. None of the items below breaks anything when it lands. They are what a reviewer comparing ContentBlocks to Sulu, Sonata Page or a Sylius CMS plugin will list.

All of them fit in 1.x under three rules:
- **No method is added to an existing interface.** A new capability is a new optional interface, the way `BlockPreviewHintInterface` is. The `bc-check` CI job holds the PHP side.
- **A host that does nothing sees the same page.** A change to what a page renders is opt-in, behind a config key; making it the default waits for 2.0.
- **Template variables and parameters are added, never removed or renamed.**

Not ranked yet:

- **Kit:**
  - logical properties (RTL)
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
- **Add the intent, not only the shape.** A form says what a block accepts, not what it is for, and composing a page that sells needs the second. Each block type gets a usage description (the hero, the offer, the CTA, the FAQ); the palette and the section style presets carry a role ("brand accent, CTAs only", "dark highlighted section"). The brand guidelines already live as data — palette, presets, layouts, title sizes — so following them means picking from those named sets, never writing CSS. These descriptions are hand-written, but they describe purpose, not structure, so they do not drift the way a hand-written field list would.
- **The host's section templates as examples.** `cb_section_template` holds sections an editor already judged good; a few of them, serialized in the same shape as the output, say more about the house style than any description. No screenshots up front: they go stale with the CSS and teach little a good description does not. Vision belongs to a later review loop (render the draft with `cb_chrome=0`, capture, critique, correct), which judges the real rendering — that is the [AI content generation](#ai-content-generation--a-seam-no-engine-) entry's business.
- **Export it** as a command (`content-blocks:schema`) and as an endpoint, so it fits a host's own pipeline, or an MCP server.
- **The way back in is the paste path, not the importer.** The importer keeps block data verbatim (it trusts an export), while paste replays every block through its form. Generated content gets the paste treatment: `RestoredSectionBuilder` for structure, `BlockDataReplayer` for data, written to the draft. The schema guides the model; validation does not trust it.

**This is the keystone of the three entries above:** AI generation needs it, the semantic SEO layer benefits from it, and it is useful on its own (documentation, fixtures, a headless client). It is also the cheapest of the four, so it should come first.

---

## Publication history — revisions of a published page 💡 (on demand)

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
