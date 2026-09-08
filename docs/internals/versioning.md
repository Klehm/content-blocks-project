# Versioning — two schemas, two owners

Integrator-facing version:
[../guide/content-versioning.md](../guide/content-versioning.md).

## The ownership line

Two things in a stored payload can go out of date, and they belong to different
people:

| | Owner | Migrated by |
|---|---|---|
| **Content** — the shape of `block.data` | the host and its block types | `ContentVersionUpgraderInterface` |
| **Envelope** — the structure around it | this package | `EnvelopeUpgraderInterface` |

The package cannot know what changed between two of the host's own content
generations, and the host should not have to know how the package restructures
its own envelope. Keeping the two seams apart is what lets either side move
without asking the other.

## The content version

`content_blocks.content_version` is the host's declaration of its own schema
generation. It is stamped on `cb_content_area.content_version` by the touch
listener and on `cb_section_template.content_version` when a snapshot is saved.

**An area's stamp means "last written under version N", not "conforms to N".**
Editing one block re-stamps the whole area while its other blocks keep whatever
shape they had, so the value is a targeting index for migrations
(`WHERE content_version < N` finds what certainly predates a change), never a
guarantee. Migrate before letting editors work on a new version.

A **snapshot's** stamp is different: a section template is frozen by definition,
so its value really does describe its payload for as long as the row lives.

`null` means the row predates versioning. Treat it as *unknown*, never as `0` —
the config node refuses `0` for exactly this reason.

### Where the upgrader applies, and where it does not

`ContentVersionUpgraderInterface` governs **section templates only**. Their
stored version is a number this same installation issued, so comparing it means
something.

An imported payload's `contentVersion` belongs to the app that exported it — "12"
there and "12" here have no relation — so the import flow does not consult this
seam at all. It judges content by shape instead: unusable blocks are skipped and
reported. A host that controls both ends of a transfer and wants a version gate
can decorate `ContentAreaImporterInterface`.

**Upgrading is transient.** Whatever `upgrade()` returns is instantiated, not
written back to the template row. A permanent rewrite is a migration, and stays
the host's job.

`supports()` is a cheap predicate called once per row when listing the library,
so the picker can rule a template out before the editor clicks it. Keep it free
of side effects and of anything that touches the payload; `upgrade()` does the
work, and is called only for payloads `supports()` accepted — a mismatch that
reaches it means a stale listing or a hand-crafted request, so throwing is a
legitimate outcome.

### Why the default refuses a known mismatch

`DenyOnMismatchUpgrader` is the shipped default, and the asymmetry in it is
deliberate:

- a **known** mismatch (stored 3, current 4) is refused. Something changed
  between the two and only the host knows what; replaying the payload blind is
  how content quietly rots.
- an **unknown** version (`null`) is accepted. Every row written before
  versioning existed carries null, so refusing it would make a host's entire
  section-template library unusable the day they upgrade — a regression far worse
  than the risk it guards against. Null means *no information*, not *wrong*.

A host wanting the strict reading, or able to migrate a payload on read, aliases
the interface.

## The envelope chain

`EnvelopeUpgraderInterface` migrates the structure this package owns —
`{format, contentArea: {sections}, assets}` for a transfer,
`{format, layout, settings, columns}` for a section template. Block data inside
it belongs to the block types and must be carried over as-is.

**The chain ships empty**, and that is the point. Only one envelope format of
each kind exists so far, so every call is a no-op — but the mechanism has to
exist *before* the first bump, because the alternative is refusing every payload
written under the old format, which is what makes a format bump unthinkable in
the first place. The day a step is added, old templates and old export files keep
working with no further plumbing.

Steps form a linear path (v1 → v2 → v3): each declares one source and one target,
so the walk is a lookup by source format, not a graph search. A cycle or a missing
link simply means *no path*, which callers turn into their own refusal — an
`UnsupportedTemplateFormatException` for a template, an
`InvalidArgumentException` for an import. A step count cap guards against a cycle
in host-supplied steps turning into an endless walk.

Steps are ordinary autoconfigured services, so a host may add its own for a
format it invented.

## Three hard stops, three different meanings

A section template can refuse to insert for three unrelated reasons, and they
carry different exceptions precisely so the UI can say which:

| Exception | Means |
|---|---|
| `UnsupportedTemplateFormatException` | the **envelope** structure is unreadable |
| `IncompatibleTemplateException` | every **block type** it references is gone |
| `IncompatibleContentVersionException` | the payload is readable and its blocks exist, but their **data** belongs to another generation of the host's schema |
