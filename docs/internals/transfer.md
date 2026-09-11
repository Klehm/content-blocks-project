# Transfer — exporting and importing an area

## The payload shape is the contract

The thing that has to stay stable here is not the PHP signature — it is the
**payload shape**, which travels on disk and between installations. `FORMAT`
versions it, and a reader must refuse a payload whose format it does not know
rather than guess. Changing the shape means bumping the format string.

The constant lives on the interface rather than the implementation, so a host
that swaps the exporter does not leave the importer validating against the
shipped class.

What has to bump the format is a change a reader can get **wrong**: a key that
moved, a value that changed meaning, a structure that has to be walked
differently. A key that is simply *new* — `ref`, `extensions` — is not one of
those: an older reader ignores it and imports exactly what it always did, and a
newer reader handles a payload that lacks it. Bumping the format for an additive
key would buy nothing and cost every older installation the ability to read
today's exports, which is the one thing the format exists to protect.

An envelope from an older structure is migrated forward when this package ships
a step for it, and refused otherwise — see
[section-templates.md](section-templates.md#versioning-the-envelope),
which is the same machinery.

## What is exported

Draft state takes precedence over published state, soft-deleted entities are
skipped, and everything is ordered by `previewPosition` — the same convention as
the clone, replace and rendering pipelines.

The payload also carries the emitting app's `contentVersion`, and it is
**informative only**. A version number means something only inside the
installation that issued it, so the importer ignores it and stamps the target
with the *local* version instead.

## Assets travel as bytes, not paths

Unlike a [section template](section-templates.md#what-a-snapshot-holds), which
stays inside one app and keeps plain storage paths, an export may land in another
installation where those paths mean nothing. So asset references are read from
storage, embedded as base64 under their sha256 hash — identical binaries
deduplicated — and replaced in place by an `asset://{hash}` token.

Finding the references is `AssetReferenceCollector`'s job, shared with the
garbage collector so the two cannot disagree about what counts as a reference.
That includes paths embedded in rich-text markup, replaced in place so the
surrounding `<img src="…">` survives. See
[assets.md](assets.md#one-definition-of-a-reference).

Two failure modes are handled by leaving the value alone rather than dropping it:

- a reference that **cannot be read** at export keeps its original path, so the
  import side sees a broken reference rather than a silently missing field;
- a token whose **hash is unknown** at import is left as-is, so the problem
  surfaces in the UI instead of vanishing.

A token can be the whole value (an image field) or sit inside markup, so the
rewriter handles both, the second by substitution in place.

## What is stored beside a block

Not everything that belongs to a block lives in `Block.data`. The i18n satellite
keeps its translations in a table of its own, deliberately —
[i18n.md](i18n.md#why-a-side-table-not-an-envelope-in-blockdata) — and the price
of that choice is that **no flow carries those rows for free**. Cloning was
taught through `BlockCloneObserverInterface`; transfer is taught through
`ContentAreaTransferExtensionInterface`, autoconfigured the same way.

An extension gets a `key()`, writes a fragment under `extensions.<key>`, and is
called back on import only when the payload carries that key. The core knows
nothing about what is inside a fragment, and an install without the satellite
imports the same payload minus the rows it cannot store.

**A fragment addresses a block by `ref`, not by id.** Database ids mean nothing
on the other side of a transfer, so the exporter stamps every block with a
positional `ref` (`s0.c1.b2`) and hands extensions the same `ref => Block` map
the payload uses. On import the ref comes back off the payload — a block the
importer skipped is simply absent from the map, so its rows are dropped with it
rather than landing on a neighbour.

Two consequences worth knowing before writing one:

- **Assets are shared.** An extension is handed the same `AssetTokenizer` on the
  way out and the same `AssetRewriter` on the way back, so a file only a
  translated value references travels like any other. The payload's `assets` map
  is read *after* the extensions have run, which is what makes that true.
- **Rows may point at blocks with no id yet.** The importer builds entities and
  leaves flushing to the caller, so an extension persisting a row whose FK is one
  of those blocks is persisting against an unflushed entity. Doctrine tolerates
  it: the "new entity found through relationship" check is deferred to the end of
  the commit, by which point the area's cascade has persisted the block.

Clipboard entries and section templates go through their own serializers and do
**not** carry fragments today — copy/paste of a translated block still loses its
translations.

## Import is a replace and does not flush

Import mirrors the *Insert content* flow: the target's existing sections are
soft-deleted — the actual removal happens at the next publish — and the imported
ones are added as never-published drafts.

It builds; it does not commit. Flushing is the caller's job, as everywhere else
in the package (see [publishing.md](publishing.md)).

## Strict envelope, optimistic content

Only the **envelope** is validated, and a bad one throws: the payload is a file
that travelled, so an unreadable structure must not be replayed.

The *content* is taken optimistically — a block whose type is unknown here is
skipped, a stored key nothing declares is kept, and both are reported in the
`ImportResult`. A payload comes from another installation, so the two apps not
having identical blocks is the normal case; refusing would make cross-install
transfer useless.

That rule and the reasoning behind the asymmetry live in
[section-templates.md](section-templates.md#skipped-blocks-versus-kept-keys).
