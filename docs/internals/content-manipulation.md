# Content manipulation

`ContentManipulator` holds what the section, column and block controllers used
to do inline. The controllers keep HTTP: CSRF, `canEdit()`, the journal,
events, the JSON answer, the flush. The service keeps the model: which fields a
new node needs, and in what order siblings sit.

## Why the service does not flush

A controller wraps its mutation in `ActionJournal::record()`, which diffs the
area before and after, then flushes once. A fixture builds a whole page and
flushes once. A service that flushed per call would break both: the journal
would see half the change, and a page would be many transactions. The
importer, the cloner and the paster already follow the same rule.

It does `persist()` new nodes, because a caller building from a managed area
has no cascade to rely on for a node added to it.

## Why events and access checks stay in the controllers

`canEdit()` needs the current user, which a command does not have, and a
refused `BeforeBlockDeleteEvent` answers `409` with a reason the snackbar
shows. Both are HTTP concerns. Moving the event into the service would make
code that deletes blocks in a loop dispatch it once per block, which no
listener written for the builder expects.

## Draft order

`DraftOrder` is the one definition of "siblings in draft order": live nodes
sorted by `previewPosition`. The mapped collections sort by the *published*
`position`, so iterating them for draft work reverts an unpublished reorder.
That rule used to be copied into every controller that reorders.

Two numbering rules coexist, on purpose:

- **Append** is `max(previewPosition) + 1` over *all* siblings, deleted ones
  included. A deleted sibling can come back through Discard or a restore, and
  appending past it keeps the two from sharing a position.
- **Insert, move, duplicate** renumber the live siblings `0..n`. The builder
  sends indexes into the visible list, so dense numbering is what lets the
  next drop land where the editor sees it.

The toolbar's up/down arrows still swap two positions in the controller: they
are a builder dialect, not a model operation.

## Sections from a payload

A section template, a clipboard entry and one section of an import share a
shape: layout, settings, columns with their presets and blocks.
`RestoredSectionBuilder` turns that shape into a detached section, and is the
one place that decides what survives: a known layout, a valid preset,
sanitized column settings, a registered block type (another is skipped and
counted), data kept verbatim with `_id`s backfilled. The importer passes its
asset rewriter and collects block refs for extensions; the instantiator
passes neither.

It does not place the section. Placement is `insertSection()`, so paste,
templates, import and "Insert content" number their positions by the same
rule as a section added in the builder.

The service can run without an entity manager: the importer and the paster
have none, and a node added to a managed area is persisted at flush by the
`cascade: persist` on every parent-to-child collection.

## Behaviour that changed on the way

`duplicateBlock()` notifies `BlockCloneObserverInterface`, as
`duplicateSection()` always did through the cloner. Before, duplicating one
block dropped its translations while duplicating its section kept them.
