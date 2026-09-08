# Assets — upload, reference detection, garbage collection

Integrator-facing version: [../guide/asset-lifecycle.md](../guide/asset-lifecycle.md).
This page is the reasoning behind the implementation.

## Nothing deletes on delete

Deleting a block does not delete its file, and neither does Publish. That is a
decision, not an oversight.

`deleted` is a **draft flag**. The published page still renders that block until
someone hits Publish, and Discard brings it back — unlinking the file at that
moment breaks a live page, the exact class of bug `PublishedRenderImmutabilityTest`
exists to prevent.

Even at Publish time the file may still be reachable through:

- the block's own published/draft twins;
- another block after a copy/paste — the clipboard duplicates the **path**, so
  two blocks share one file;
- another area after an Insert-content deep clone;
- a saved `cb_section_template` (its payload holds plain paths);
- a translated rich-text value in `cb_block_translation`;
- the host's own entities, sharing the upload directory.

Every one of those makes a reference count computed at delete time either wrong
or as expensive as a full scan. Hence: full scan, off the hot path, when an
operator asks for it — `content-blocks:assets:gc`.

## The order of operations *is* the safety property

`AssetGarbageCollector` snapshots the inventory **before** collecting
references. A file uploaded while marking runs is therefore absent from the
snapshot and cannot be swept, whatever the reference scan concludes about it.

The retention window covers the other race, the one that actually bites:
`/_content-blocks/upload` writes the file the moment the editor picks it,
minutes before the block form that will reference it is submitted.

Marking compares strings **exactly**, against the spelling stored in the
payload. Normalizing here would create a second, divergent definition of "the
same file".

## One definition of a reference

`AssetReferenceCollector` is shared with the exporter, and that is the whole
point: a detector that sees *less* than the exporter loses a file from an
export; a detector that sees less than reality deletes a live one.

It recognizes three shapes, all present in real data:

| Shape | Example | Written by |
|---|---|---|
| The whole string is the path | `{"src": "/uploads/content-blocks/a.png"}` | `ImageUploadType` |
| Embedded in markup | `<p><img src="/uploads/…"></p>` | a rich-text editor upload |
| Embedded **and relative** | `<img src="../../uploads/…">` | TinyMCE, whose `relative_urls` is on by default |

Shapes 2 and 3 were invisible before this class existed:
`AssetResolverInterface::isAssetPath()` tests a whole string, so
`str_starts_with('<p><img…', '/uploads/')` is false and every rich-text image
silently fell out of exports. Shape 3 is the *normal* spelling for an
editor-uploaded image, not an edge case.

They are found by cutting the string on characters that cannot occur inside a
URL in markup or CSS and asking the resolver about each piece, in three
spellings. That keeps the class storage-agnostic: a resolver that recognizes
`https://cdn.example.com/…` works exactly as well as a local prefix.

**The asymmetry that drives every judgment call here:** missing a reference
deletes a file that is on a live page; reporting one too many only spares a file
until the next run. So where a candidate is genuinely ambiguous, every plausible
reading is reported for marking, and the single most likely one is used for
substitution.

## The scalar-hydration trap

Found by running the sweep against a real database:
`SELECT b.publishedData AS published FROM Block b` hydrated with `HYDRATE_SCALAR`
returns the column **as its raw JSON string**, not as the array the entity
getter hands back — the type conversion `getPublishedData()` benefits from does
not run on an aliased scalar.

A provider that simply checked `is_array()` therefore skipped every row and
reported *no* references at all, which in a sweep means "delete everything". The
failure is silent and it points the wrong way, so the decoding lives once in
`JsonPayload`, not in each provider.

## What the core providers count

`ContentAreaAssetReferenceProvider` reads every block's data and every section's
settings. Three deliberate choices, each of which would cost a live page if
reversed:

- **Both twins.** `publishedData` *and* `draftData`. A file referenced only by
  the published side is on the public page right now; one referenced only by the
  draft side is one Publish away from being on it.
- **Soft-deleted rows included.** No `WHERE` clause on `deleted`, on purpose.
- **Scalar hydration, streamed.** An established install has six figures of
  blocks; hydrating entities to read two JSON columns would load the whole table
  into the identity map.

## Why the sweep is a command

`content-blocks:assets:gc` is a command rather than anything automatic, for two
reasons that are not going to change:

- The upload directory is not necessarily the package's alone. A host pointing
  `content_blocks.upload.directory` at a shared folder, or storing its own
  entities' images there, must register an `AssetReferenceProviderInterface`
  first. Nothing should delete files on a schedule the operator never chose.
- Deletion is irreversible and reference detection is a heuristic over
  host-shaped JSON. **`--dry-run` being the default and `--force` the opt-in is
  the whole safety design — do not invert it.**

`/_content-blocks/assets/report` shows what the sweep *would* reclaim, and has
no delete button and will not get one. Seeing a list in a browser and acting on
it are two different levels of deliberateness; the second belongs in a shell,
where the operator has a backup and an audit trail. The page prints the command
instead.

It is denied by default and 404s rather than 403s, so an install that never
wired it does not advertise the route's existence. Cost is one full directory
walk plus one pass over the content tables per view — the same work the command
does, which is why it is an operator page and not something linked from the
builder chrome.

## Asset routes are public on purpose

The package's own CSS/JS is served under `/_content-blocks/public/*`, outside
the `/_content-blocks/*` admin namespace, so a host locking the admin endpoints
behind `ROLE_ADMIN` does not 404 the stylesheet loaded inside the public preview
iframe. Hosts should keep that prefix reachable.

The URLs carry **no file extension** deliberately: PHP's built-in dev server
treats a path that looks like a file as a static asset and 404s it before the
router sees it. Content-Type headers do the MIME work.

`builder.css` is served with the design tokens *prepended* rather than
`@import`-ed — the file is served raw, with no bundler, and an `@import` would
resolve against `/_content-blocks/public/`, where no route serves them.

## Three seams, and why each is its own interface

- **`AssetReferenceProviderInterface`** — the mark half. Same reasoning as
  `AccessCheckerInterface`: the package cannot know the host's model. A host
  storing a Page cover image in the same directory registers one, or the sweep
  correctly concludes no *block* references it and deletes it. The core's two
  implementations and the i18n package's third arrive through this same
  interface — no privileged internal path, which is what keeps a host provider
  on equal footing. Autoconfigured; implementations should stream.

- **`AssetInventoryInterface`** — deliberately **not** five more methods on
  `FileStorageInterface`. Hosts alias that one to their own S3/Flysystem
  implementations, and widening it would break every one of them at the next
  upgrade. Listing is also a genuinely different capability: a signed-URL CDN
  bucket may store and read perfectly well with no cheap way to enumerate. A
  storage that does not implement it simply has no garbage collection —
  `CollectAssetsCommand` says so and stops rather than reporting a successful
  sweep of nothing.

- **`AssetReportViewerInterface`** — a cross-area concern with no `ContentArea`
  to key off, so it gets its own capability rather than overloading
  `AccessCheckerInterface`. Same shape as `SectionTemplateManagerInterface`. The
  report lists file paths and sizes across every area, so `DenyAllAssetReportViewer`
  is the default and the page 404s until a host opts in.

## The image seam ships a passthrough

ContentBlocks deliberately ships **no image processing**. An uploaded file is
served as-is and only its *display box* is controlled by CSS. That covers the
free wins — no layout shift, lazy loading — but never reduces byte size, which
inherently needs an image-processing library (LiipImagine, Glide, GD, Imagick) or
a transforming CDN. Neither belongs in this package's `require`.

So `ImageUrlResolverInterface` follows the same pattern as `FileStorageInterface`
and `AccessCheckerInterface`: an interface with a default that changes nothing.
`PassthroughImageUrlResolver` returns the stored source untouched with no
responsive candidates, so a fresh install renders byte-for-byte the markup it did
before the seam existed. A host aliasing its own implementation gets
`srcset`/`sizes` everywhere the kit renders an image, without touching a template.

**An implementation must be safe on any input.** `$src` is whatever an editor
stored — a local path, an absolute URL, a leftover from a previous storage
backend — and `new ResolvedImage($src)` is always a valid answer. A resolver that
cannot transform a given source says so by passing it through, never by throwing.

`srcset` and `sizes` are null when the resolver has nothing to offer, and a
template renders them only when non-null: an empty `srcset=""` is not the same
thing to a browser as no attribute at all.

Width and height are the *display* box the view intends to use, which is exactly
the input a resizing resolver needs. A fluid view passes null and lets the
resolver decide.

Worked example: [../guide/recipes/liip-imagine.md](../guide/recipes/liip-imagine.md).
