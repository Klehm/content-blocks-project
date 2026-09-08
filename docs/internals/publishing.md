# Publishing — the draft twins, Publish and Discard

Integrator-facing version: [../guide/concepts.md](../guide/concepts.md).

## The rule the whole design rests on

**No builder action changes the published page.** PUBLIC mode reads published
state only: a Section/Column appears once `published_at` is set, a Block once
`published_data` is written, each at its published `position`, in its published
column, with its published settings.

Every mutable field has a draft twin — `position`/`previewPosition`,
`publishedData`/`draftData`, `publishedSettings`/`draftSettings` — and the rule
holds exactly as long as **nothing writes a published field outside `publish()`**.

Two traps this cost, both reported from a production site:

- **`deleted` is a draft flag**, not a deletion. The public render ignores it:
  deleting a block removes nothing from the live page before Publish. The
  public render never *sets* it either — the templates prune a marked subtree
  as soon as `chrome` is false, which is right for the `cb_chrome=0` preview and
  would be the leak in public.
- **The `Block.column` FK is the draft position.** A drag between columns writes
  it immediately, because that is what the builder, the preview and Doctrine's
  cascades walk. `published_column_id` remembers the published one;
  `Block::moveTo()` sets it and `restoreTo()` takes it back at Discard. Both
  synchronize the two sides of the association — Doctrine cancels orphan removal
  when the entity joins another collection, so `removeElement` then `add` is what
  keeps the block alive.

Safety net: `tests/Rendering/PublishedRenderImmutabilityTest.php` (every builder
controller against a snapshotted public render) and
`assets/test/e2e/published-render-immutability.spec.js` (the real builder, the
real public page, byte for byte). Every new builder action joins them.

## Publish and Discard semantics

Publish:

- a soft-deleted Section/Column is `em->remove()`d, and Doctrine's
  `cascade={"remove"}` wipes its descendants — the publisher does not iterate
  into them;
- a live entity has its draft promoted: `position ← previewPosition`,
  `publishedData ← draftData`, then `draftData ← null`.

Discard — an entity never published is a brand-new addition and goes entirely:

- Section/Column with `publishedAt === null` is removed, cascade included;
- Block with `publishedData === null` is removed;
- everything else has its draft flags cleared.

**Order matters at Discard.** Moved blocks are put back in their published
column *before* anything is removed: the column a block was dragged into may
itself be one of the brand-new ones about to be deleted, and a block still
sitting there would be cascaded away with it. A block whose published column has
since disappeared stays where it is — the move is all that is left of it.

Both methods flush. They are the two terminal operations of the draft
lifecycle, so committing is part of what they mean; the services that *build*
rather than commit (`SectionClonerInterface`, `ContentAreaImporterInterface`,
`SectionTemplateInstantiatorInterface`) leave the flush to their caller.

## Every structural op writes to draft

The AJAX controllers under `/_content-blocks/*` all share one shape, which is
why their individual docblocks say so little: a write lands in draft state
(`draftData`, `previewPosition`, `column`, `deleted`), **never** in
`publishedData` or `position`, and promotion happens only through
`ContentAreaPublisher`. Publish commits, Discard reverts.

That covers replace-content, import, template insert and paste alike: each
soft-deletes what it displaces and adds its new sections as never-published
drafts, so the editor can preview the result and still back out.

Two consequences worth knowing before touching one of them:

- **A cross-column move calls `moveTo()`, not `setColumn()`.** The FK is the
  draft location, so a published block has to leave a note saying which column
  the public page keeps showing it in until Publish.
- **Sort by `previewPosition` before re-indexing.** `getBlocks()` is ordered by
  the *published* position through the collection's `#[OrderBy]`, so a source
  column carrying an unpublished reorder would otherwise be re-indexed back into
  published order, silently reverting the draft order of the blocks left behind.

Deleted siblings are dropped from the position math because the frontend's drag
logic ignores them too — they are `display:none` — so the index the iframe agreed
on is one in the *visible-only* list.

A soft-delete undo (`restore`) is only valid while the deletion is still a
draft. Once Publish ran, the row was physically removed and the endpoint 404s.

`@internal` on these classes means the **routes** are the contract, not the PHP.

## Why publish() takes a context object

`PublishContext` exists for the same reason `RenderContext` does: the publish
methods used to take nothing but a `ContentArea`, and adding a parameter to a
published interface breaks every implementor — including a host's own decorator
wrapping it for an audit trail or a cache purge. A context object grows fields
without touching the signature, so the seam is frozen with room in it.

The core ignores it. An area's own draft is a single state with no locale
dimension, and `ContentAreaPublisher` promotes it whatever the context says. The
scope addresses **translations**, and a satellite decorating the publisher is
what reads it. With `klehm/content-blocks-i18n` installed:

| Context | Publishes |
|---|---|
| `null` / `everything()` | the area's draft and every locale's translations |
| `withLocales()` | the draft, plus only the named locales |
| `sourceOnly()` | the draft alone; translations stay as published |

### The invariant the shape enforces

There is deliberately **no way to publish a locale without publishing the area's
pending draft**. A translation is written against a specific source text, so
pushing it ahead of that text produces the exact failure the feature exists to
prevent: a French heading live on the public site describing an English heading
nobody has seen. Holding a translation back is safe and expressible; running it
ahead of its source is neither.

## Why the touch listener hooks onFlush

`ContentAreaTouchListener` sets `ContentArea::updatedAt` and stamps
`contentVersion` whenever a descendant Section / Column / Block changes.

It listens on `onFlush` rather than `@PreUpdate` because `ContentArea` almost
never has its own fields mutated — the entity is an id plus a collection — so a
`@PreUpdate` on it would not fire on a typical block edit. From `onFlush` it can
watch every relevant child and bubble the change up to the owning area, calling
`recomputeSingleEntityChangeSet()` so the new `updatedAt` lands in the SQL of
that same flush. Areas already scheduled for deletion are skipped: touching them
is pointless and could re-add them to the update set.

It is registered with the `doctrine.event_listener` tag in `services.php`, not
`#[AsDoctrineListener]`, so the package needs no hard dependency on
DoctrineBundle.

The version it stamps records the generation the content was **written** under —
a targeting index for host migrations, not a conformance claim. Editing one
block re-stamps the whole area while its other blocks keep whatever shape they
had, so migrate before letting editors work on a new version.
