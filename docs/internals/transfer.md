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

## Assets travel beside the content

Unlike a [section template](section-templates.md#what-a-snapshot-holds), which
stays inside one app and keeps plain storage paths, an export may land in another
installation where those paths mean nothing. So each asset reference is read from
storage, hashed (sha256), and replaced in place by an `asset://{hash}` token. The
payload's `assets` map lists each file once — `mimeType`, `extension`, `size` and
the `path` it had here — and the bytes travel beside it, in the zip.

Finding the references is `AssetReferenceCollector`'s job, shared with the
garbage collector so the two cannot disagree about what counts as a reference.
That includes paths embedded in rich-text markup, replaced in place so the
surrounding `<img src="…">` survives. See
[assets.md](assets.md#one-definition-of-a-reference).

A token can be the whole value (an image field) or sit inside markup, so the
rewriter handles both, the second by substitution in place. Two failure modes
are handled by leaving a value readable rather than dropping it:

- a reference that **cannot be read** at export keeps its original path, so the
  import side sees a broken reference rather than a silently missing field;
- a token with **no file** at import is replaced by the source `path` the
  manifest gives it, or left as the token when there is none, and reported in
  `ImportResult::$missingAssets`.

Before RC17 the bytes were inlined in the JSON as base64 (`data` on each entry).
The importer still reads that shape, so an old export imports as it always did.

## The zip

`GET …/area/{id}/export` answers a zip: `content.json`, then one
`media/{hash}.{ext}` per listed file (`ZipExportWriter`, on
`maennchen/zipstream-php`). Everything is **stored, not deflated**: the media
are already compressed formats, and a stored entry has a size known in advance.
That buys the two properties the export needed:

- **Constant memory.** One file is read at a time and written through; nothing
  holds the archive. The tokenizer reads each file once to hash it, and the
  writer reads it again to send it — twice the disk reads, one file in memory.
- **An exact `Content-Length`.** The archive is laid out in `SIMULATE_STRICT`
  mode first, which computes its size without reading a byte, then sent for
  real. The browser shows a real progress bar, and the summary endpoint
  (`…/export/summary`) reports the size of both variants for the dialog.

Streaming has one cost: once the headers are sent, an error can no longer be
reported, only cut the download short. Every file is read before the first byte
(the hashing pass), so what could fail late is only a file deleted in between.
The response carries `X-Accel-Buffering: no` so nginx relays it as it comes
instead of holding it whole.

`?assets=0` writes `content.json` alone: the manifest still lists every file,
with its hash and path, so the other side can tell which it already holds —
this site, or one that shares its uploads — and which are missing.

The format string stays `content-blocks/v1`. The `data`-less entry is not
something an older reader can get wrong: it refuses it, loudly, as a malformed
entry. It could not open the zip anyway.

## An import in steps

The browser opens the dropped file itself — the zip's directory is read from
the `File`, and a stored entry is a `Blob.slice()` of it, never loaded — and
talks to the server in small requests (`StagedImportController`):

1. **`POST …/import/plan`** — the manifest's hashes and paths, and the block
   types. The server answers which files it **already holds**: bytes at the
   listed path with the same hash (a copy on the same site sends nothing and
   duplicates nothing), or a file an earlier, interrupted attempt stored. It
   also names the block types unknown here and the largest file a request can
   carry.
2. **`POST …/import/asset`**, once per file to send. The server hashes the
   bytes, checks them against the hash sent (if any) and against the files the
   plan listed, applies the upload policy, stores the file and remembers where.
   A file the editor drops for a missing one goes through the same request
   without a hash: it is kept only if its bytes are a file of the export.
3. **`POST …/import/commit`** — the manifest. The draft is replaced in one
   journalled step, exactly like the single-request import.

Between the steps, what the server verified lives in the session
(`ImportStaging`, keyed by area): the hashes the plan expects and the path of
each file checked or stored. The commit resolves tokens **only** from that map,
never from anything in the request, so a forged manifest cannot point a token at
a file of its choosing. The map is emptied by the commit.

No request carries more than one file, so the size of the export no longer
meets `upload_max_filesize` or `post_max_size`; only the largest single file
does, and the plan reports that limit so the dialog can name an oversized file
before sending anything. An abandoned import leaves stored files nothing
references; the asset garbage collector takes them after its retention window.

`POST …/import` — one multipart JSON, bytes inline — is kept for scripts. It
takes the pre-RC17 payload and the zip's `content.json` alike, capped by
`content_blocks.import.max_size` and PHP's own limits (`ImportSizeLimit`).

### An imported file is an upload

An import writes files into the public upload directory, so it is held to the
upload endpoint's policy (`AssetPolicy`): `content_blocks.upload.max_size` and
`allowed_mime_types`. The MIME type is sniffed from the bytes, and the stored
extension is derived from it. The manifest's `mimeType` and `extension` are
claims written by whoever produced the file, and a file is trivially forged:
trusting `extension` let a payload drop a `.php` or `.html` file under the
public prefix, which is code execution on a server that runs PHP there, and
stored XSS everywhere else.

In the single-request import, every inline file is checked before any is
stored, so a refused payload leaves no file behind. The bytes are decoded twice
— once to check, once to store — so that only one blob is held at a time.

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
