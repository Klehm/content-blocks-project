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
  every key pressed inside it. Both are pinned by
  `media-library-picker.spec.js`, and fail without the guard.
- **Escape is the dialog's `cancel`, not a keydown.** Closing the picker from
  its keydown handler let Chrome's close request fall through to the next
  modal, the builder itself, which closed too. The `cancel` event aims at the
  topmost modal only; cancelled, it lets go of the selection instead.
- **Enter in the search field** submits the dialog's `method="dialog"` form,
  which closes it. The field turns Enter into an immediate search.

Answers to a superseded list request are dropped (a generation counter), so a
fast typist never sees the results of an older query paint over a newer one.

## It behaves like a file browser

The selection is a set of ids plus an anchor, not a DOM state, so it survives a
repaint and a reload drops only what is no longer listed. A click selects, a
click on the file already alone in the selection lets go of it, a click on the
empty zone lets go of everything; Ctrl/Cmd-click toggles, Shift-click takes the
range from the anchor, Ctrl/Cmd-A selects all outside a field. Escape lets go
of the selection first and closes the dialog only when there is none.

Two kinds of drag, told apart by the `dataTransfer` types:

- **Files from the desktop** (`Files`) onto the main zone upload one at a time
  through the core endpoint, each filed under the folder shown; what made it
  in ends up selected. A depth counter keeps the highlight steady while the
  cursor crosses the thumbnails.
- **Files of the picker** (`application/x-cb-media`, the ids as JSON) onto a
  folder or a crumb of the trail move in one `POST file/move`. Dragging a file
  outside the selection drags it alone. Only a manager can start such a drag.

While picker files are dragged, the drag image is a compact chip (thumbnail
and title, or a count): the tile itself would cover the folders it aims at.
The dragged tiles dim, every folder and trail crumb is outlined as a place to
drop, and the row under the cursor fills with the accent colour. The whole row
is the target, its rename and delete buttons included, and moving onto one of
its children is not leaving it (`relatedTarget`), so the highlight holds.

`file/move` and `file/remove` take a list of ids and refuse the whole request
when one is unknown: a drag with a stale id moves nothing rather than half.
*Insert* needs exactly one file, since a field holds one.

## Two places for a message

What the grid holds (loading, empty, no match) is said inside the zone that
scrolls, where the tiles would be. What an action did (an upload in progress,
a refused file, a failed move) is said in a notice above the grid, outside the
scroll: written after a long folder, it was out of view. An error stays until
closed or until the next clean upload; a drop where some files fail names how
many, with the server's reasons, and selects the ones that made it.

A file over the upload limit is refused before it is sent: the dialog carries
the lower of `content_blocks.upload.max_size` and PHP's own limits. Over
`post_max_size` PHP empties the request, area included, so without that check
the server could only answer that the area was missing.

## Pages load as the grid scrolls

A sentinel after the grid is watched by an `IntersectionObserver` rooted on the
zone that scrolls, with a 300px look-ahead (about two rows); *Show more* stays
as the fallback without an observer. Three rules keep it from loading wrong:

- **One next page at a time.** A fast scroll reports the sentinel several
  times; `more()` returns while a page is in flight, and a failed page leaves
  the page number where it was, so it is asked again rather than skipped.
- **A next page never supersedes a listing.** A new listing (a folder, a
  search) takes a new generation; a next page reuses the current one and is
  dropped if it changed. No next page starts while a listing is loading.
  Before, a page in flight cancelled the folder the editor had just opened.
- **The observer is asked again after a listing.** It reports changes only,
  so its last word describes the grid that was replaced: re-observing the
  sentinel makes it answer for the new one, and a first page shorter than the
  zone keeps loading until the end leaves the look-ahead.

A next page appends its tiles; repainting the grid would reload every image.

## Opened from the Actions menu

`MediaActionProvider` adds *Media library* to the builder's Actions menu; the
script answers that `cb:builder:action` key by opening the picker for no field:
every configured kind at once (`kind=all` on the list), no *Insert*, and a
double click that selects only. It is where files are sorted and uploaded
ahead of the page that will use them.

## Who may browse

Listing and filing need `canEdit()` on the area the picker was opened from, the
same check as the core's upload endpoint: whoever may upload into a page may
see what was uploaded before. The library itself is global, not per area.
Managing it (folders, metadata, removal) is a separate capability.

## Managing the library

`ManageController` holds every write but the picker's `place`: folders
(create, rename, delete), a file's title and alt, a move, removal. Each needs
the CSRF token and `MediaManagerInterface::canManage()`, denied by default like
`SectionTemplateManagerInterface`: the library is shared by every area, so the
edit right on one page cannot be what allows renaming what all pages use.

`place` stays open to any editor for one case only: a file still at the root
and uploaded in the last hour, which is the upload the picker has just made.
Any other move is managing.

Ids travel in the JSON body, not the URL, so the script builds no URL: every
route it calls is generated by `path()` in the template.

A deleted folder takes its subfolders with it (`ON DELETE CASCADE` on
`parent_id`) and hands their files to the root (`SET NULL` on `folder_id`):
deleting a folder never loses a file. There is no folder move, so no cycle can
be built from the UI; the breadcrumb and the folder list are bounded anyway.

## Where a file is used

`MediaUsage` answers "which pages use this file" for the detail panel. Two
steps, because neither is enough alone:

1. **SQL on the file name.** `LIKE '%name%'` on `draft_data` and
   `published_data`, joined up to the area. The name, not the path, because
   rich text stores relative spellings (`../../uploads/…`, TinyMCE's default)
   and JSON may escape the slashes. PostgreSQL has no `LIKE` on `json`, hence
   the cast there.
2. **The GC's collector confirms.** Each candidate row is decoded
   (`JsonPayload::decode()`, since DBAL returns raw strings) and walked by
   `AssetReferenceCollector`, the single definition of "this string references
   a file". `aa.png` matches `LIKE '%a.png%'` and is dropped here.

A block deleted in the draft still counts where it is published: the public
page renders it until Publish. Only blocks are searched; a section template or
a translation holding the file keeps it from the GC, but is not listed.

The answer names only the pages the editor may edit (`canEdit()` on each) and
counts the others, so the library leaks no title of a page its editor cannot
open. Labels and links come from the host's `ContentAreaProviderInterface` and
`ContentAreaUrlResolverInterface`.
