# Section templates — the library, snapshots and posters

Integrator-facing version: the *Vignettes de la bibliothèque de sections*
section of [../../CLAUDE.md](../../CLAUDE.md).

## Skipped blocks versus kept keys

A snapshot may be replayed long after it was saved, against a registry that has
moved on. The restore is **optimistic**: everything this installation can use
comes in, and the rest is reported rather than aborting the whole operation. The
same rule governs the import flow, and the vocabulary is deliberately shared.

Compatibility is judged per **block**, not per key, which is why the two
discrepancies are handled differently:

- **An unknown block type is skipped.** A block whose type is gone has no view
  template, no edit form and no defaults, so inserting it would hand the editor
  an inert placeholder. Nothing is lost: the stored payload is the archive —
  restore the block type, insert again, and the block comes back.
- **An unknown data key is kept**, and merely reported. The block itself is
  perfectly usable, the key harms nothing, and it may well be a field the host
  is about to add.

What counts as a known key is [`BlockDataKeys`](clipboard.md#which-keys-a-block-type-can-hold),
shared with the import flow so the two cannot disagree.

The one hard stop left is a template that **had** blocks and kept none: there is
nothing to insert, and dropping an empty section into the area would only puzzle
the editor. A template that never had any — a spacer section, say — inserts
fine.

The clipboard deliberately does *not* follow this rule; see
[clipboard.md](clipboard.md#why-the-clipboard-needs-a-replayer) for why an
untrusted payload gets a stricter one.

## Two unreadable-template cases

`IncompatibleTemplateException` means *the blocks this template references are
gone*, and carries the missing type ids.

`UnsupportedTemplateFormatException` is about the payload's own structure. It is
a separate type on purpose: reusing the first one would leave `getMissingTypes()`
empty, which reads as "nothing is missing" — the opposite of the truth.

Both are hard stops with the same consequence for the editor, so the controller
answers 422 for either.

## Versioning the envelope

The envelope format versions the payload **structure**, which this package owns
— unlike the block data inside it, whose shape belongs to the host and its block
types. An older structure is migrated forward by `EnvelopeUpgradeChain` when a
step exists for it, and refused otherwise: replaying a structure we cannot read
would quietly produce half-empty sections.

The format identifier lives on the *interface*, not the implementation, so a host
that swaps the exporter does not leave the importer validating against the
shipped class.

## What a snapshot holds

Draft state takes precedence over published state, soft-deleted entities are
skipped, and columns and blocks are ordered by `previewPosition` — the same
convention as the clone, replace and rendering pipelines.

Asset references stay plain storage paths rather than embedded binaries: the
library lives inside one app, so embedding would bloat every template for
nothing. The [export format](transfer.md) makes the opposite choice, because it
travels between installations.

The distinct block-type identifiers are returned alongside the payload and
denormalized into `cb_section_template.block_types`, so the picker can flag an
unusable template from a cheap column read instead of deserializing every
payload to look for its types.

## Managing the library

Saving a section into the library and inserting a template into an area are both
gated by `AccessCheckerInterface::canEdit()` on the area at hand — you can only
capture or drop content where you may already edit.

Managing the library *itself* (rename, delete) is a cross-area concern with no
`ContentArea` to key off, so it gets its own capability rather than overloading
`AccessCheckerInterface`. `DenyAllSectionTemplateManager` is the default, secure
by default; dev and sandbox environments alias the allow-all one.

## Why the poster is a spec

The library cards carry a thumbnail drawn from the stored payload, not a capture
of the real section.

Rasterising would mean either a headless browser on every host, or a client-side
canvas pass that quietly loses its styling whenever the host serves CSS from
another origin. The payload meanwhile already holds the layout, the column
presets, the block order and the block data — enough to draw something faithful
*in the DOM*, where a real `<img>` is a real `<img>` and real copy is real text.

It also costs no column, no migration and no storage, and it works on rows saved
long before the feature existed.

The renderer on the other end is `cb-builder_controller.js#_buildTemplatePoster`;
the shape `SectionPosterBuilder::build()` returns is that contract. It returns
null when the payload holds no column structure to draw — an envelope from
another format, or a row written by hand — and callers render the card without a
thumbnail rather than an empty frame.

### What the poster decides in PHP

**The background**, its own or the one its style preset brings. The merge mirrors
`BlockRenderer`: preset settings sit *under* the section's, key by key, so an
explicit choice wins and an untouched field falls through to the preset. Without
it a section styled entirely by a preset would post a blank thumbnail while
rendering, say, dark navy.

**Whether the copy must be flipped for a dark ground**, at both levels. Only PHP
has the resolved colour, and a poster whose copy is unreadable against its own
background is worse than one with no background at all. A tile cannot inherit the
section's answer: a red card on a cream section is a dark ground inside a light
one.

The luminance threshold comes straight off the sRGB coefficients. Its exact value
matters less than having one, since it only picks between two paint jobs.

`styling.backgroundColor` is read straight from the data with no
`BlockPreviewHintInterface` involved, because `styling` is the **core's**
sub-form — added to every block by `BlockFormType` — not something a block type
defines. Block types own their own fields; this one the package owns. Only the
one colour format the styling forms produce is accepted (`PaletteColorType`
stores a plain `#hex`, `''` meaning none); anything else is a row from elsewhere
and is ignored rather than passed into a `style` attribute.

**Which references may reach an `<img src>`**: same-origin paths and http(s) URLs
only. Stored data is form-validated, but this value is about to be written
straight into the admin DOM, and narrowing it here is cheaper than trusting every
block author and every legacy row that ever wrote the field.

### Two caps

At most six tiles per column are drawn, the rest folded into a `+N` chip. A
thumbnail a few hundred pixels tall cannot show more, and the cap is what keeps a
sixty-block section from bloating the list response.

A type this build no longer registers still earns a tile. Seeing *where* the
holes are beats a thumbnail that silently omits them, and `list()` reports the
same absence in words through `skippedTypes`.
