---
title: Content versioning
---

# Content versioning

Stored content outlives the code that wrote it. A section template saved today
gets inserted in eighteen months, an exported JSON file gets re-imported after
two upgrades. This page is about keeping that survivable.

## Two schemas, two owners

A stored payload has two independent schemas, and confusing them is the main way
this gets hard:

| | Owned by | Changes when | Migrated by |
|---|---|---|---|
| **Content** — the keys inside `block.data`, `section.settings` | **you** (your blocks, plus the kit's) | you rename a field, the kit renames one | **you** |
| **Envelope** — `{format, contentArea: {sections: […]}, assets}` | this package | a release changes the payload structure | this package |

They move independently and neither can speak for the other. That is why there
are two mechanisms, and why only one of them is your business.

## The content version — yours

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    content_version: 1
```

An integer you bump whenever anything that shapes your stored block data
changes: your own block types, a kit upgrade that renames keys, or a core
upgrade note telling you to.

As content is written, the current value is stamped onto
`cb_content_area.content_version`; saving a section into the library stamps
`cb_section_template.content_version`. That gives your migrations something to
aim at.

### What the number means — and what it does not

::: danger Read this before building anything on the column
It records the generation an area was last **written** under. It does **not**
mean every block in it conforms.

The stamp is applied by the same listener that touches `updatedAt`, on any
write. So:

> You deploy version 2. An editor fixes a typo in one block of an old area. The
> area is now stamped `2` — while its *other* blocks still hold version-1 shapes.
> Your `WHERE content_version < 2` will never find it again.

The column is a **targeting index**, not a conformance guarantee. Which leads to
one rule:

**Run your migration before letting editors work on the new version.**
:::

Section templates have no such caveat: a snapshot is frozen, so its stamp keeps
describing its payload for as long as the row lives.

### `NULL` is not `0`

Every row written before versioning existed carries `NULL`. It means *"no
information"*, not *"generation zero"* — you cannot know what shape that content
is in. Decide explicitly what to do with those rows; the config node refuses `0`
precisely so the two never blur together.

```sql
-- candidates that certainly predate generation 2
SELECT id FROM cb_content_area WHERE content_version < 2 OR content_version IS NULL;
```

## Writing a migration

The full shape of a content migration: rewrite the data, then move the stamp
forward yourself so the rows drop out of your own targeting query.

```php
// migrations/Version20270301120000.php
final class Version20270301120000 extends AbstractMigration
{
    private const TARGET = 2;

    public function getDescription(): string
    {
        return 'Content v2: my `quote` block renames `author` to `attribution`.';
    }

    public function up(Schema $schema): void
    {
        // 1. Rewrite the data. Both JSON columns: a block keeps its published
        //    payload in `published_data` and any in-flight edit in `draft_data`,
        //    and migrating only one surfaces the moment an editor publishes.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, draft_data, published_data FROM cb_block WHERE type = 'quote'"
        );

        foreach ($rows as $row) {
            $set = [];
            $params = ['id' => $row['id']];

            foreach (['draft_data', 'published_data'] as $col) {
                if ($row[$col] === null) {
                    continue;
                }
                $data = json_decode((string) $row[$col], true);
                if (!is_array($data) || !array_key_exists('author', $data)) {
                    continue;
                }
                $data['attribution'] = $data['author'];
                unset($data['author']);

                $set[] = "$col = :$col";
                $params[$col] = json_encode($data);
            }

            if ($set !== []) {
                $this->connection->executeStatement(
                    'UPDATE cb_block SET ' . implode(', ', $set) . ' WHERE id = :id',
                    $params
                );
            }
        }

        // 2. Move the stamp forward on everything you just brought up to date,
        //    so your own `WHERE content_version < 2` stops matching them.
        $this->addSql('UPDATE cb_content_area SET content_version = ' . self::TARGET);
        $this->addSql('UPDATE cb_section_template SET content_version = ' . self::TARGET);
    }
}
```

Then bump the config, in the same deploy:

```yaml
content_blocks:
    content_version: 2
```

::: tip Order matters
Deploy the migration and the config bump together, before editors resume. If the
new code runs first, editors stamp untouched areas as `2`; if the migration runs
first, nothing breaks — the data is simply ahead of the declared version for a
few seconds.
:::

## Deciding what happens to older snapshots

A section template's stored version is comparable — the number came from this
same installation — so the library can act on it. What to do is yours to decide
through `ContentVersionUpgraderInterface`:

```php
namespace App\ContentBlocks;

use ContentBlocks\Versioning\ContentVersionUpgraderInterface;

final class MyUpgrader implements ContentVersionUpgraderInterface
{
    public function supports(?int $stored, int $current): bool
    {
        // Cheap: called once per row when listing the library, so the picker
        // greys out what you refuse instead of letting an editor click into an
        // error. No side effects, no touching the payload.
        return $stored === null || $stored >= 1;
    }

    public function upgrade(array $payload, ?int $stored, int $current): array
    {
        if ($stored === 1) {
            $payload = $this->renameAuthorToAttribution($payload);
        }

        return $payload;
    }
}
```

```yaml
# config/services.yaml
ContentBlocks\Versioning\ContentVersionUpgraderInterface: '@App\ContentBlocks\MyUpgrader'
```

::: warning Upgrading is transient
Whatever `upgrade()` returns is instantiated; the template row is **not**
rewritten. Making it permanent is a migration — the one above. The seam exists so
old snapshots stay usable *between* migrations, not instead of them.
:::

The shipped default, `DenyOnMismatchUpgrader`, refuses a **known** mismatch and
accepts `NULL`. The asymmetry is deliberate: refusing `NULL` would make your
entire library unusable the day you first set a version, which is worse than the
risk it guards against. Alias the interface if you want the strict reading.

### Why import does not use it

An imported payload carries the *emitting* app's `contentVersion`, and that
number means nothing here — "12" there and "12" in your app have no relation. So
the import flow ignores it and stamps the target with your **local** version.

Imports are judged by **shape** instead: a block whose type you do not have is
skipped (importing it would leave an inert placeholder — nothing renders it, no
form edits it) and reported; a stored key nothing declares is kept and reported.
The file you imported remains the archive, so installing the missing block type
and re-importing brings it back.

If you control both ends of a transfer and want to gate it on a version anyway,
decorate `ContentAreaImporterInterface`.

## The envelope — ours

You should never have to think about this one, but it is worth knowing it exists.

When a release changes the *structure* around your content, it ships an
`EnvelopeUpgraderInterface` step alongside. `EnvelopeUpgradeChain` walks a stored
payload from the format it declares to the one the current code reads, hop by
hop, so your old templates and export files keep working across the bump.

The chain is empty today — only one format of each kind has ever existed. It is
in place so that a future bump is a class we ship, not a day you lose your
library.

You can add your own step (implement the interface, it is autoconfigured) if you
invented a payload format of your own. Otherwise: nothing to do.

## Summary

- **You own the content schema.** Bump `content_blocks.content_version`, write
  the migration, move the stamp.
- **The area stamp is a targeting index**, not proof. Migrate before editors
  resume.
- **`NULL` means unknown**, never zero.
- **Snapshots are frozen**, so their stamp stays true — and
  `ContentVersionUpgraderInterface` decides what happens to the older ones.
- **We own the envelope**, and its migrations ship with the package.
