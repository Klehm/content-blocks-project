# Transfer — exporting and importing an area

## The payload shape is the contract

The thing that has to stay stable here is not the PHP signature — it is the
**payload shape**, which travels on disk and between installations. `FORMAT`
versions it, and a reader must refuse a payload whose format it does not know
rather than guess. Changing the shape means bumping the format string.

The constant lives on the interface rather than the implementation, so a host
that swaps the exporter does not leave the importer validating against the
shipped class.

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
