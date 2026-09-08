# Asset lifecycle

Uploaded files outlive the blocks that showed them. This page explains why that
is deliberate, and how to reclaim the ones nothing points at any more.

## Nothing is deleted when a block is

Deleting a block does not delete its image, and neither does publishing that
deletion. That is a design decision, not an oversight — three separate things
make delete-time cleanup wrong here:

**`deleted` is a draft flag.** The published page still renders that block until
someone hits Publish, and Discard brings it back. Unlinking the file at delete
time would break a live page — the exact class of bug that
[`PublishedRenderImmutabilityTest`](./rendering.md) exists to prevent.

**A file can be reachable from several places at once.** The block's own
published and draft twins; another block after a copy/paste, since the clipboard
duplicates the *path* and two blocks then share one file; another area after an
Insert content deep clone; a saved section template, whose payload keeps plain
storage paths; a translated rich-text value in a separate table; or the host's
own entities, if they share the upload directory.

**A reference count computed at delete time is therefore either wrong or as
expensive as a full scan.** So ContentBlocks does the full scan instead — off
the hot path, when an operator asks for it.

## Reclaiming: `content-blocks:assets:gc`

```bash
# Report only — the default. Nothing is deleted.
php bin/console content-blocks:assets:gc

# Actually reclaim.
php bin/console content-blocks:assets:gc --force
```

| Option | Default | Meaning |
|---|---|---|
| `--force` | off | Delete. Without it the command only reports. |
| `--retention=N` | `30` | Spare files modified within the last N days. |
| `--format=` | `table` | `table` or `json` (the full list, not a preview). |

Two properties make it safe to run:

- **The inventory is snapshotted before references are collected.** A file
  uploaded while the scan runs is not in the snapshot and cannot be swept, no
  matter what the marking concludes about it.
- **The retention window covers the race that actually happens.**
  `/_content-blocks/upload` writes the file the moment an editor picks it,
  minutes before the block form referencing it is submitted. Do not set
  `--retention=0` on a live site.

There is no scheduler in the package. If you want this on a cron, that is your
call to make and your crontab to own.

## The report page

`/_content-blocks/assets/report` renders the same data in a browser, for
operators who do not live in a terminal. It is **read-only**: it has no delete
button, and it prints the command instead. Reclaiming belongs where you have a
shell, a backup and an audit trail.

It is **denied by default** and returns 404 — not 403 — until you opt in, since
it lists paths and sizes across every area in the install:

```yaml
# config/services.yaml
services:
    ContentBlocks\Asset\AssetReportViewerInterface:
        class: App\Security\AssetReportAccess
```

```php
use ContentBlocks\Asset\AssetReportViewerInterface;

final class AssetReportAccess implements AssetReportViewerInterface
{
    public function __construct(private Security $security) {}

    public function canViewAssetReport(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }
}
```

Add `?format=json` for the machine-readable version, and `?retention=N` to try
a different window.

::: warning
Both the command and the page walk the whole upload directory and pass over the
content tables. That is fine for an operator action; it is not something to put
behind a link in a hot page.
:::

## If you share the upload directory

**The sweep only knows about references it is told about.** The package ships
three sources — content areas, the section-template library, and (with
`klehm/content-blocks-i18n`) translated values. If your own entities store
images in the same directory, register a provider or the sweep will correctly
find no *block* pointing at them and delete them:

```php
use ContentBlocks\Asset\AssetReferenceProviderInterface;

final class PageCoverAssetReferences implements AssetReferenceProviderInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function referencedAssetPaths(): iterable
    {
        $rows = $this->em
            ->createQuery('SELECT p.coverImage AS path FROM App\Entity\Page p')
            ->toIterable([], AbstractQuery::HYDRATE_SCALAR);

        foreach ($rows as $row) {
            if (is_string($row['path']) && $row['path'] !== '') {
                yield $row['path'];
            }
        }
    }
}
```

The interface is autoconfigured — implementing it is enough, no tag needed.
Paths must be spelled exactly as your storage produced them; the comparison is
a string comparison, deliberately, so that "the same file" has one definition.

::: tip Test it before you trust it
Run `--format=json` and check that a file you know is on a page is **not** in
`swept`. That is the whole safety check, and it takes a minute.
:::

## Storage support

Sweeping needs a storage backend that can enumerate itself, which is a
capability separate from storing and reading:
`ContentBlocks\Storage\AssetInventoryInterface`. `LocalFileStorage` implements
it, so hosts on the built-in local storage need to do nothing.

If you aliased `FileStorageInterface` to your own S3/Flysystem implementation,
implement `AssetInventoryInterface` on it too — yielding one `StoredAsset`
(public path, size, last-modified) per file — or the command will tell you it
cannot proceed rather than reporting a successful sweep of nothing.

## What counts as a reference

A reference is found by walking the stored JSON and asking the storage backend
about every URL-ish string in it. Three spellings are recognized, because all
three occur in real data:

| Shape | Example |
|---|---|
| The whole value | `{"src": "/uploads/content-blocks/a.png"}` |
| Embedded in markup | `<img src="/uploads/content-blocks/a.png">` |
| Embedded **and relative** | `<img src="../../uploads/content-blocks/a.png">` |

The third is not an edge case: rich-text editors rewrite the absolute URL the
upload endpoint returns into a document-relative one (TinyMCE's `relative_urls`
is on by default), so that is the *normal* spelling for an image uploaded from
inside the editor.

The same walk feeds the export flow, on purpose. A detector that found fewer
references than the exporter would mean an export silently loses a file; one
that found fewer than reality would mean the sweep deletes a live one. There is
one implementation so the two cannot drift.
