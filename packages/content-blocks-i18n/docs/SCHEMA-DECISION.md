# Schema decision record

*Formerly `TRANSLATION-SPIKE.md` at the monorepo root. It drove a decision that
had to be made before the core's 1.0 freeze, and it is kept here — rather than
deleted — because it records **why** the storage schema is what it is, which the
code cannot say for itself.*

*Status: decided and implemented. Everything below the decision points is
history; the current behaviour is documented in the [README](../README.md).*

---

The [roadmap entry](../../../ROADMAP.md) left the data schema as an open question — "needs a schema
spike before implementation". This is that spike. Its job is **not** to design the translation
package; it is to answer one question:

> Which seams must exist in `klehm/content-blocks` at v1.0.0 so a satellite translation
> package can be built in 1.x **without a breaking change to the core**?

Everything the satellite can do by decorating an already-aliased interface, or by adding an
autoconfigured collection to a concrete service, is out of scope by construction: it is not a
freeze problem.

---

## 1. What the core has today

Traced, not assumed.

**The render path has no data seam.** `BlockRenderer::buildBlockViewModel()` resolved the
effective payload inline:

```php
$data = $mode === RenderMode::PREVIEW
    ? ($block->getDraftData() ?? $block->getPublishedData() ?? [])
    : ($block->getPublishedData() ?? []);
```

Nothing could intervene. `BlockDecoratorInterface` runs one line later but contributes only
classes, inline styles and attributes — by contract it cannot rewrite `data`. The only
override available was re-aliasing `BlockRendererInterface` and reimplementing ~340 lines.

**The render path has no locale.** `BlockRendererInterface` exposed `render(ContentArea,
?RenderMode)` and friends. There was no way to say "render this area in `fr`". Adding a
parameter to an interface method after 1.0 is a breaking change for every implementor —
**this is the one genuinely freeze-blocking item.**

**The lifecycle seams already exist.** `ContentAreaPublisherInterface`,
`SectionClonerInterface`, `ContentAreaExporterInterface`, `ContentAreaImporterInterface`,
`SectionTemplateSerializerInterface`, `SectionTemplateInstantiatorInterface` are all aliased to
their default implementation, so a satellite decorates them.

**Collection entries have no identity.** The kit's `card`, `list`, `accordion`, `tabs`,
`gallery`, `breadcrumb` and `table` blocks store entries as plain positional arrays. Three
editor actions shift those positions — `moveCollectionItem`, `duplicateCollectionItem` and
deleting an entry. Anything keyed per entry by index silently reattaches itself to the wrong
entry afterwards.

**There is no event dispatcher anywhere in `src/`** — zero. The extension model is interfaces +
autoconfigured tagged collections, and this spike stays inside it.

---

## 2. Where translated values live

The roadmap listed side table / JSON envelope / cloned blocks. Cloned per-locale blocks are
dismissed immediately: they break the design premise the roadmap itself set — *one shared
layout, translatable fields* — and double every structural operation. That leaves two.

### Option A — envelope inside `Block.data`

`data.translations.<locale>.<field>`, the source locale living in `data` itself.

- Every duplication flow works untouched: clone, replace-with, export, import and section
  templates all copy `data` wholesale, so translations ride along for free.
- It survives editing. `BlockComponent::persistDraft()` writes `$form->getData()`, and the
  form's model data *is* the block's data array: a key no form child declares is frozen, not
  dropped.
- Costs: publishing is all-locales-at-once (the block is the publish unit), and the payload is
  opaque — "which pages are untranslated?" means scanning every block's JSON, so no progress
  reporting and no filtering.

### Option B — side table `cb_block_translation (block_id, locale, draft_data, published_data)`

- Mirrors the draft/published duality of `Block`, so **per-locale publishing is possible**.
- **Queryable**: translation progress, "show me untranslated blocks", deleting a locale in one
  statement. This is what an editorial team actually asks for.
- Leaves `Block.data` alone; the satellite ships its own entity and migration.
- Costs: every flow that *duplicates* a block must duplicate its rows, and none of them hands
  out a source→copy correspondence. Render needs a prefetch to avoid N+1.

### Decision: **Option B**, the side table

The queryable state is the deciding factor: a multilingual site is run from a progress view,
and Option A cannot produce one without reading every row. Per-locale publishing comes along
as a bonus.

**Correction to an earlier draft of this document.** It claimed Option B was blocked because
exposing the clone correspondence meant changing `SectionClonerInterface::cloneSection()`'s
return type — a breaking change that would have to land before the tag. That was wrong: the
correspondence can be delivered by an **observer in a tagged collection**, the idiom already
used everywhere in this package. `SectionCloner` gains a constructor dependency and calls
`$observer->blockCloned($source, $copy)` during its walk; the interface signature never moves,
so it is **additive and deferrable to 1.x**. The same shape covers the four transfer walks
(export / import / serialize / instantiate), which produce `['type' => …, 'data' => …]` from
private methods today.

**Option B therefore adds no freeze blocker.** What it does remove is the need to reserve
`translations` inside `Block.data` — under B, nothing is stored there.

---

## 3. Entry identity — `_id`

Independent of the storage choice, and the reason it is here: a per-locale payload keyed by
collection *position* desynchronizes the moment an editor reorders, duplicates or deletes an
entry. Option B stores a blob per block, so its entries are positional too — moving the
storage does not solve identity.

**Decision: every collection entry carries a stable `_id`.** Adding it after 1.0 would mean a
data migration across every collection block, which makes it the one true one-way door in this
document.

- **Uniqueness is scoped to one collection of one block.** Cloning a section, importing an area
  or inserting a template copies `_id`s verbatim, and that is correct — the copies live under
  different block ids, and carrying the entry ids across lets per-entry data map straight onto
  the copy.
- **Minted on the draft-write path** (`BlockComponent::persistDraft` → `CollectionItemIds`),
  which every editor write goes through. Driven by the *form*, since only a form knows which
  array value is a collection rather than an ordinary nested array a block happens to store.
- **No form field in any item type.** Verified by probe: an unmapped key survives a submit
  inside a collection entry, stays glued to its own row through a delete, and a newly appended
  entry arrives without one — which is exactly the signal the backfill needs.
- **The one trap:** `duplicateCollectionItem` copies the entry wholesale, so it must *strip*
  `_id` and let a fresh one be minted. Two entries sharing an identity is the only way this
  design corrupts data, and it is pinned by a test.
- **Existing content** is normalized by `content-blocks:backfill-collection-ids` — a command,
  not a Doctrine migration, because which JSON keys hold a collection is knowledge that lives
  in the block types' forms and SQL cannot ask them. Idempotent; skips and reports blocks whose
  type is no longer registered.

### Reserved namespace

Rather than reserving the single key `translations` (which Option B made pointless), the
package reserves the **`_` prefix** at every level of `Block.data`, including collection
entries. `BlockDataKeys` never reports an underscore-prefixed key as unknown. One rule frozen
now, instead of reopening a frozen data contract for each future need — `_id` today, `_src`
(see §4) tomorrow.

---

## 4. Fallback and staleness

- **Unknown / absent locale → source locale.** `data` itself is the source; a block with no
  row for that locale renders exactly as it does today.
- **Partial translation → per-field fallback**, not per-block. The alternative makes a
  half-translated site look broken rather than incomplete.
- **Empty string is a translation.** A deliberate blank must not fall back; only an *absent*
  key does. Merge on `array_key_exists`, not on truthiness.
- **Non-translatable fields never merge** — a locale payload carrying an untagged field is
  ignored at render. The tagging (§5c) is the allow-list, mirroring how the block's form is
  already the whitelist for `data` itself.
- **`_src` — staleness, not identity.** With `_id` solving *which* entry a translation belongs
  to, the remaining question is whether it is still *current*: storing the source value (or its
  hash) next to the translation lets the UI flag "the source changed since this was
  translated". Entirely inside the satellite's rows under Option B, so it needs nothing from
  the core and can land whenever the UI does.

---

## 5. The core seam list — the output of this spike

Only what cannot be added later, plus what must be published now so hosts and the kit agree on
one convention.

**a. `BlockDataResolverInterface`** — the missing render seam. An autoconfigured tagged
collection on the exact pattern of `BlockDecoratorCollection`; the draft-or-published choice
moved into a default `CoreBlockDataResolver` at priority 256. Output with no third-party
resolver registered is byte-for-byte what it was. The satellite merges its locale payload here.

**b. `RenderContext` + `BlockRendererInterface` signatures** — a value object carrying the
render mode and an optional locale, replacing the bare `?RenderMode` parameters.
**Breaking-if-deferred**; the reason this spike had to happen before the tag. The host passes
its own context, which keeps locale resolution the host's business rather than the package's.

**c. The `cb_translatable` form-option convention** — a field opts in via a form option, read
back by walking the block's built form (the trick `BlockDataKeys` already uses). Additive in
mechanism, but the *convention* must be public at 1.0 so host blocks and kit blocks tag
identically from day one. The kit tags 29 fields.

**d. `_id` on collection entries + the reserved `_` prefix** — see §3. The one-way door.

**Deliberately not landing:** the clone-correspondence observer and the per-block transfer
observers. Both are additive (a constructor dependency on a tagged collection, not an interface
change), so they belong with the satellite that needs them. Also not landing: a prefetch hook
for the render path — the satellite decorates `render()` to warm its map before the resolvers
run, which the existing alias already allows.

---

## 6. Explicitly out of scope for the core

Storage, migrations, the per-field translation UI, locale negotiation, the language switcher,
and any translation behaviour whatsoever. The core ships seams and a convention; with no
satellite package installed, **nothing about the rendered output changes**.

---

## 7. Probe record

Two claims were load-bearing enough to test rather than reason about. Both used a standalone
Symfony Forms factory with an array model, submitting only the declared fields.

- **An unmapped key survives a submit — at the root and inside a collection entry.** After
  submitting `title` and `content`, `getData()` returns the untouched extra key; inside a
  collection, each entry keeps its own `_id` through an edit, and through the deletion of a
  *different* entry (the array goes sparse, the ids stay with their rows). A newly appended
  entry has none. This is what lets `_id` live without a form field in any of the 8 item types.
- **An injected key is ignored.** Submitting a POST that also carries the extra key leaves the
  stored value untouched — the compound form maps only its declared children. A bonus security
  property: nothing can be written into the reserved namespace through the block edit form.

Seam sufficiency (§5 a–d) is exercised by the shipped tests: a fake resolver, registered by
autoconfiguration alone, substitutes a field end to end without touching the core; and
`content-blocks:backfill-collection-ids` was run against the sandbox's 3599 blocks — 250 gained
ids, a second pass reported nothing to do.
