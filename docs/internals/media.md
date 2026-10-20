# Media library — internals

Maintainer notes for `klehm/content-blocks-media`. The integrator-facing page is
[docs/guide/media-library.md](../guide/media-library.md).

## A row per file, the file stays a path

The library is a table beside the storage, not a new storage. A block still
stores a path, exactly the string the upload endpoint returned; `cb_media` adds
what a path cannot say: title, alt, dimensions, folder, dates. That is why
installing or removing the package changes nothing in rendering, export/import
or the clipboard: none of them ever see a library id.

`path` is unique, in the exact spelling block data stores. The GC compares
spellings exactly ([assets.md](./assets.md)), so a normalized path here would
be a second definition of "the same file".

## Rows are written beside the ORM

`MediaRows` inserts through DBAL, never `persist()` + `flush()`. Uploads reach
the storage from two very different callers:

- the upload endpoint, with nothing pending in the unit of work;
- the importer (`uploadFromString()`), in the middle of building sections it has
  persisted but not flushed.

Flushing from the decorator would commit the importer's half-built tree. A DBAL
insert on the same connection joins whatever transaction is open instead: an
import that rolls back takes its library rows with it.

The entities exist for reading and editing (the dialog, metadata, folders). The
mapping test (`MediaMappingTest`) holds the two halves together: every column
`MediaRows` writes must exist under any naming strategy the host configured.

## An upload never fails over its row

`UploadRecorder` catches the DBAL exception and logs it. By the time the row is
written the file is already stored; throwing would answer the editor with an
error for an upload that succeeded, and leave an orphan nobody knows about. A
missing row costs less: the file works, and `content-blocks-media:import` adds
it later.

The one case that still fails loudly is PostgreSQL inside the importer's
transaction: a failed statement aborts the whole transaction there, whatever
PHP catches. That needs a missing table, so a migration not run, which the
import then reports.

## The decorator keeps the inventory

The package decorates `FileStorageInterface`, whichever implementation the host
aliased. The asset GC asks `$storage instanceof AssetInventoryInterface` before
it sweeps, so a decorator implementing only `FileStorageInterface` would quietly
switch the GC off. `RecordingFileStorage::decorate()` is a factory that picks
`RecordingInventoriedFileStorage` when the inner storage lists its files, and
the plain decorator otherwise. The choice is made on the instance, not at
compile time: a host's storage may be built by a factory whose class the
container does not know.

## The library is a reference

`LibraryAssetReferenceProvider` reports every path in `cb_media` as referenced,
so the GC never sweeps a library file. Removing a file from the library deletes
its row, never the file: the next GC run decides, like after any other editor
action ([asset-lifecycle](../guide/asset-lifecycle.md)).

The consequence is deliberate: every upload enters the library, so an image an
editor replaced stays until someone removes it from the library. That is what a
library is for. It is also why `content-blocks-media:import` takes only the
files some content uses by default: importing orphans would keep them forever.
The importer skips the library's own provider when it computes "used", or the
library would vouch for the files it already holds.

## The picker is self-contained

The picker is a shell fragment (`MediaShellExtension`): a `<dialog>`, a
stylesheet and a module served by the package's own route
(`content_blocks_media_asset`). No Stimulus controller, so nothing for a host
to declare in `controllers.json` and nothing to rebuild.

It reads nothing off `.cb-shell`: its endpoints, the area id and the CSRF token
(`content_blocks`, the core's id, so the core upload endpoint accepts it) are
attributes of the dialog itself, and its CSS falls back on every `--cb-*` token
it reads. That is what lets the i18n workbench, which is not the shell, render
the same fragment.

Uploading goes through the core's `content_blocks_upload`, never a second
endpoint: the size and MIME limits stay in one place. The decorator gives the
file its row; `POST place` then files it under the folder the editor is in.

Two traps of living inside the builder:

- **The builder's keys.** Its document `keydown` handler closes the Navigator
  on Escape and pastes a block on Ctrl-V. The dialog stops the propagation of
  every key pressed inside it and closes itself on Escape. Both are pinned by
  `media-library-picker.spec.js`, and fail without the guard.
- **Enter in the search field** submits the dialog's `method="dialog"` form,
  which closes it. The field turns Enter into an immediate search.

Answers to a superseded list request are dropped (a generation counter), so a
fast typist never sees the results of an older query paint over a newer one.

## Who may browse

Listing and filing need `canEdit()` on the area the picker was opened from, the
same check as the core's upload endpoint: whoever may upload into a page may
see what was uploaded before. The library itself is global, not per area.
Managing it (folders, metadata, removal) is a separate capability.
