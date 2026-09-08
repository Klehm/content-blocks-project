# Clipboard — replaying an untrusted payload

Integrator-facing version: the *Copier / coller* section of
[../../CLAUDE.md](../../CLAUDE.md).

## Why the clipboard needs a replayer

Section-template insert and area import read rows this application wrote
itself, so they keep block data verbatim and only *warn* about keys the type no
longer declares.

The clipboard is different in one decisive way: it lives in `localStorage`, so
its payload is whatever the user — or anything running in their browser — put
there. It is input, not truth. The package already has a whitelist and validator
for block data, the block's own form, so `BlockDataReplayer` replays the payload
through it exactly as if an editor had typed those values into the sidebar.

What survives:

- **A field of the block's form** — submitted, so its `constraints` decide. A
  field that fails is dropped and reset to the type's default; the block still
  lands, because an editor would rather fix one field than recreate the block,
  and the dropped names are reported so the UI can say so.
- **A key the type declares in `getDefaultData()` without exposing a field** —
  kept verbatim. Same union rule as `BlockDataKeys`: it is part of the type's
  own data contract and no form child can vouch for it.
- **Nothing else.** An undeclared key never reaches `data`.

`_id`s do not cross: a pasted block is a new block, so per-entry information
keyed on the source's ids would not apply to it. `CollectionItemIds::backfill()`
mints fresh ones.

## The envelope

The envelope is only the outermost sanity check — is this ours, which scope,
which content generation. What is inside still goes through the instantiator and
each block's own form.

`contentVersion` stamps the host's content generation at copy time. Unlike a
section template — a stored row, worth migrating forward through
`ContentVersionUpgraderInterface` — a clipboard entry is minutes old at most, so
a mismatch is **refused outright rather than upgraded**: the editor copies again
under the current generation and loses nothing.

`NULL` means "copied before the stamp existed", and is just as unhelpful here as
anywhere: nothing says which generation produced it, so it is refused like any
other mismatch.

## Replay and placement

`ClipboardPaster` has two halves, both deliberate:

- **Replay** reuses the section-template instantiator for the section scope —
  same payload format, same skip-unknown-types behaviour — and adds the block
  scope one level down. Every surviving block then goes through
  `BlockDataReplayer`.
- **Placement** is the rule the editor sees: a pasted section lands right after
  the selected one, or at the end of the area when nothing is selected; a pasted
  block right after the selected one, or at the end of its column. Both re-index
  their siblings so `previewPosition` stays dense, the convention Duplicate and
  Move already follow.

Entities come back attached to their parent but **not flushed** — persisting is
the controller's job, as everywhere else in the package.

Asset references stay plain storage paths, same reasoning as the section
serializer: both ends of a copy live in the same app, so the pasted block points
at the very same stored file rather than a copy of it.

## The view-shape trap

**`submit()` expects the posted shape, not the model shape**, and assuming
otherwise is the trap here.

`Block.data` holds model values — `styling.backgroundColor` is the string
`'#eb0540'` — while `submit()` takes what a browser would post, which for a
compound field with a data mapper (`PaletteColorType`) is the array of its
children's view values. Submitting the model shape fails on every such field and
quietly resets it to the default.

So the form does the conversion itself: one built **on the payload** maps model →
view, and reading its children back gives the post shape. That form is a
converter and nothing else — its output is then submitted into a form built
**from the defaults**, which is the only one that validates. A payload that
shapes its own converter therefore gains nothing.

The validating form is built from the defaults for the same reason: a block type
may size its own fields from the data it is given, and a forged payload must not
get to shape the form meant to validate it.

Two details that look arbitrary and are not:

- A value the converter cannot represent comes back empty — an out-of-list
  choice, which `ChoiceType` maps to `''`. Submitting *that* would blank the
  field silently, so the raw value is submitted instead and the clean form
  refuses it out loud, which is how it gets reported and reset.
- **An expanded choice is not an array on the wire.** Radios and checkbox lists
  have one child per option, but a browser posts the chosen *value*, and
  `ChoiceType`'s submit path reads it that way — hand it a per-child map and it
  chokes. So a choice field always answers with its view data, expanded or not.

A value the converter cannot map at all costs only its own field: the whole
payload is tried first, and a per-field retry isolates the offender. The
drop-and-resubmit loop is bounded only so a pathological type cannot spin; each
pass removes at least one field.

## Which keys a block type can hold

`BlockDataKeys` answers that for both restore paths, and the answer is the
**union** of two sources because neither alone describes what a block stores:

- `getDefaultData()` — keys a block declares but does not expose as a field;
- the children of the block's built edit form — `styling` (added by
  `BlockFormType`, deliberately absent from `getDefaultData()`) and every field
  contributed by a host `BlockFormExtensionInterface`.

Reading only the first flags `styling` on every styled block and every host-added
field; reading only the second flags declared-but-not-editable keys.

Building the form, rather than reading a static declaration, is the only
definition that stays true when a host adds a field. Only the builder is created
— no view, no data mapping — so it stays cheap enough for admin-side calls.

An unregistered type has no shape to compare against, so nothing is reported and
the caller decides what an unknown type means: the template flow refuses it, the
import flow warns.

### The reserved prefix

`_` is reserved to ContentBlocks at every level of `Block.data`, collection
entries included, and **a block type must not declare one**.

It exists because some stored values are written by machinery rather than by the
block's form, and would otherwise be reported as unknown keys by both restore
paths. Today that is `_id`. Reserving the *prefix* rather than a list of names
means the next such need does not reopen a frozen data contract.

The flip side is verified rather than assumed: a POST carrying an
underscore-prefixed key is ignored by the block form, since only declared
children map — so reserving the namespace opens no write path through the editor.

## Stable entry ids

A collection entry has no identity of its own: it is a position in a list. Three
editor actions shift those positions — reorder, duplicate, delete — so anything
keying per-entry information by index silently attaches it to the wrong entry
afterwards. Content translation is the first consumer that would suffer (a French
title landing on the wrong card), but the problem is older than translation and
belongs to the data contract, which is why `_id` ships in 1.0 rather than with
the package that needs it.

Uniqueness is scoped to one collection of one block. Cloning a section, importing
an area and inserting a template all copy `_id`s verbatim, and that is correct:
the copies live under different block ids, and carrying the same entry ids lets
per-entry information map straight across.

Ids are minted only where an entry appears without one, which is exactly two
places: a newly added entry (the prototype has none) and a duplicated entry (the
copy is stripped of the original's id first). Everything else round-trips,
because a key no form child declares is preserved by the compound form — which is
what makes this work with no `_id` field in any item type.

Backfill is driven by the **form**, not by the shape of the data: only a form
knows which of a block's array values is a collection of entries rather than an
ordinary nested array the type happens to store. A row with no matching entry
form still gets an id, it just cannot be recursed into.
