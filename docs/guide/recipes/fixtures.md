---
title: Seed content and write fixtures
---

# Seed content and write fixtures

Sooner or later something other than an editor has to create content: a demo install that ships with a landing page, a `DoctrineFixtures` class, a test that needs three sections and a button. This recipe covers the two ways to do it, and the one mistake that makes a fixture look right and render nothing.

Nothing here is new machinery — it is the export/import pair and the entities you already have, pointed at a use case the UI does not cover.

::: danger The trap: draft is not published
Every node of a content tree has **two sides**, a draft and a published one. The builder reads the draft; the public page reads the published side. A fixture that writes only the draft shows up perfectly in the builder and leaves the front-office page **empty** — no error, no warning, nothing in the logs.

Both routes below tell you exactly where they write. Read that line before debugging an empty page.
:::

## Which route

| | JSON envelope | Entity builder |
|---|---|---|
| **Good for** | seeds, demos, "give everyone the same page" | tests, edge-case states |
| **Written as** | a file you commit | PHP in the test |
| **Comes from** | the builder's Export button | your keyboard |
| **Survives a schema change** | yes — [content versioning](../content-versioning.md) migrates it | no — it is code, you edit it |
| **Needs a database** | yes | no |

The short version: **content** you want to keep goes in a JSON file; **states** you want to assert on are built in PHP.

## Route 1 — the JSON envelope

The builder's **Export** button already produces a self-contained document: sections, columns, blocks, and every referenced upload inlined as base64. That file is a fixture.

### Produce it

Compose the page in the builder, click Export, drop the file in your repository:

```
fixtures/content/homepage.json
```

Being self-contained matters: the file carries its images, so a fresh clone with an empty `public/uploads/` still gets a complete page.

### Replay it

```bash
php bin/console content-blocks:import <area-id> fixtures/content/homepage.json --publish
```

::: warning `--publish` is not optional in a seed
Without it the import is written **as a draft** — the trap above. The command says so on every run that omits the flag. Leave it off only when you intend to hand the editor something to review before it goes live.
:::

Other flags and behaviours worth knowing:

- `--dry-run` parses and validates the envelope, reports what it would import, writes nothing. Useful in CI to catch a fixture that has rotted.
- **The area must already exist.** A `ContentArea` belongs to one of *your* entities (a `Page`, a `Product`), and the package cannot invent that owner — see [the code route](#in-a-doctrine-fixture) below for creating it.
- **The import replaces**, it does not append: the area's existing sections are soft-deleted first.
- A block whose type is not registered in this installation is **skipped and reported**, not fatal — the rest of the page still comes in.
- **No `AccessCheckerInterface` check.** A console command runs outside a session, so "may this user edit that area?" has no answer; shell access is the authorization.

### In a Doctrine fixture

Creating the owner and filling it in one pass:

```php
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class HomepageFixture extends Fixture
{
    public function __construct(
        private readonly ContentAreaImporterInterface $importer,
        private readonly ContentAreaPublisherInterface $publisher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $page = new Page();
        $page->setTitle('Home');
        $page->setContentArea($area = new ContentArea());
        $manager->persist($page);

        $payload = json_decode(
            file_get_contents(__DIR__ . '/content/homepage.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        $this->importer->import($area, $payload);
        $manager->flush();

        // The importer writes the draft side, and only this promotes it to
        // what the public page reads. Skipping it is the trap.
        $this->publisher->publish($area);
    }
}
```

Two services, both autowirable, both part of the supported surface:

- `ContentBlocks\Transfer\ContentAreaImporterInterface` — replays an envelope into an area, returning an `ImportResult` that describes what it could not take in.
- `ContentBlocks\Publishing\ContentAreaPublisherInterface` — promotes draft to published. `discardDraft()` is the other direction.

Both are on the [supported surface](../backward-compatibility.md), and the exporter behind the Export button, `ContentAreaExporterInterface`, is the third of the set.

### When the envelope is refused

An envelope carries a `format` and a `contentVersion`. A foreign format, or a content version this build has no upgrade step for, throws `InvalidArgumentException` rather than importing something half-understood. See [Content versioning](../content-versioning.md) for `ContentVersionUpgraderInterface`, the seam that bridges a generation gap.

## Route 2 — the entity builder, in tests

For a test, a JSON file is indirection: you want to see the tree in the assertion's own file. `ContentBlocks\Test\ContentAreaBuilder` builds one in memory.

```php
use ContentBlocks\Entity\Section;
use ContentBlocks\Test\ColumnBuilder;
use ContentBlocks\Test\ContentAreaBuilder;
use ContentBlocks\Test\SectionBuilder;

$area = ContentAreaBuilder::create()
    ->withId(7)
    ->section(fn (SectionBuilder $s) => $s
        ->layout(Section::LAYOUT_TWO_COLS)
        ->settings(['backgroundColor' => '#f5f5f5'])
        ->column(fn (ColumnBuilder $c) => $c
            ->preset('col-6')
            ->block('title', ['text' => 'Hello'])
            ->block('text', ['content' => 'World']))
        ->column(fn (ColumnBuilder $c) => $c
            ->preset('col-6')
            ->block('image', ['src' => '/uploads/photo.png'])))
    ->build();
```

The defaults are chosen against the trap:

- **Published by default.** The naive call gives you a tree that renders on a public page. `->draft()` is the explicit opt-out, and it applies to the whole subtree.
- **Positions auto-increment** in insertion order, for both `position` and `previewPosition` — so a plain tree has no pending changes. `->position(3, 5)` sets them apart on purpose, which is what a reorder awaiting publication looks like.
- **`withId()` stamps the database-generated key** by reflection, for the many tests that need an identified entity and no database.
- **Nothing is persisted.** Pass the result to `EntityManager::persist()` yourself when a test wants it stored.

Modelling a specific state is a matter of naming the side:

```php
// A block edited since its last publish: the public page still shows the old text.
->block('text', configure: fn (BlockBuilder $b) => $b
    ->publishedData(['content' => 'live'])
    ->draftData(['content' => 'edited']))

// A whole area waiting for its first Publish click.
ContentAreaBuilder::create()->draft()->section(...)->build()

// A section soft-deleted in the draft: still stored, gone at publish time.
->section(fn (SectionBuilder $s) => $s->deleted())
```

`draft()`, `published()` and `deleted()` exist at every level, and the order of calls inside a closure never matters — the tree is materialized by `build()`, not as you describe it.

::: tip Not frozen yet
`ContentBlocks\Test\*` is the one part of the package marked `@experimental`: it ships in `src/` so your test suite can use it, but its shape is still driven by this package's own suite and may change in a minor release. Everything else in these docs is covered by the [BC promise](../backward-compatibility.md).
:::

## Fixtures for browser tests

If a Playwright or Panther spec needs a state the UI cannot reach — a section template referencing a block type this build does not have, say — neither route helps: you need an HTTP endpoint the browser can call.

Keep that endpoint in the **application**, not the package, and guard it on `kernel.debug` so it cannot exist in a production build. [`TestFixtureController`](https://github.com/klehm/content-blocks-project/blob/master/apps/content-blocks-sandbox/src/Controller/TestFixtureController.php) in the sandbox is the worked example.
