# Content from code

Fixtures, a migration from another CMS, a command that seeds a landing page:
they build sections, columns and blocks without the builder. Setting
`previewPosition`, collection `_id`s and published twins by hand is how that
used to go wrong. `ContentManipulatorInterface` does it the way the builder
does, because the builder's own endpoints run it.

```php
use ContentBlocks\Content\ContentManipulatorInterface;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;

final class LandingPageSeeder
{
    public function __construct(
        private readonly ContentManipulatorInterface $content,
        private readonly ContentAreaPublisherInterface $publisher,
        private readonly EntityManagerInterface $em,
    ) {}

    public function seed(Page $page): void
    {
        $area = new ContentArea();
        $page->setContentArea($area);

        $hero = $this->content->addSection($area);
        $column = $hero->getColumns()->first();
        $this->content->addBlock($column, 'title', ['text' => 'Welcome', 'tag' => 'h1']);
        $this->content->addBlock($column, 'text', ['content' => 'We ship worldwide.']);

        $features = $this->content->addSection($area, Section::LAYOUT_THREE_COLS);
        foreach ($features->getColumns() as $i => $col) {
            $this->content->addBlock($col, 'card', ['items' => [['title' => "Feature $i"]]]);
        }

        $this->em->persist($page);
        $this->em->flush();

        $this->publisher->publish($area);   // optional: live right away
    }
}
```

## What it does for you

- **Sections get their layout's columns**, with the spans the builder would
  give them, and the `content_blocks.section.initial_settings` a section added
  from the builder gets. The `$settings` you pass are merged over those.
- **Blocks start from their type's defaults.** `$data` is merged over
  `getDefaultData()`, key by key at the top level, and every collection entry
  gets its `_id`, so the block is translatable before anyone edits it.
- **Order is kept.** Without a position, a section or a block goes last. With
  one, it is inserted there and its live siblings are renumbered.
- **Everything is draft.** Nothing reaches the public page until
  `ContentAreaPublisherInterface::publish()`, and `discardDraft()` undoes it,
  exactly as for an editor's changes.

## What it leaves to you

- **Flushing.** No method flushes, so a whole page is one transaction. New
  entities are persisted for you.
- **Access checks.** It trusts its caller. The builder checks `canEdit()`
  before calling it; a command or a fixture has nobody to check.
- **Validation of block data.** `$data` is written as given, like an import.
  The block's form, which checks what an editor submits, does not run, so pass
  values your views accept.
- **Events.** The builder dispatches `BeforeBlockDeleteEvent` and
  `AfterBlockDeleteEvent` around its delete endpoint. The service does not:
  it is what the endpoint calls between the two.

## Methods

| Method | Does |
|---|---|
| `addSection(area, layout = 'full', settings = [], position = null)` | A section with its layout's columns |
| `insertSection(area, section, position = null)` | Places a section built elsewhere (a clone, say) |
| `addColumn(section)` | Appends a column and gives the live ones equal spans |
| `addBlock(column, type, data = [], position = null)` | A block, `data` over the type's defaults |
| `insertBlock(column, block, position = null)` | Places a block built elsewhere |
| `moveSection(section, position)` | Among its live siblings, clamped |
| `moveBlock(block, column, position = null)` | Within the same area; at the end without a position |
| `duplicateSection(section)`, `duplicateBlock(block)` | A copy right after the source, translations included |
| `deleteSection`, `deleteColumn`, `deleteBlock` | Soft deletes: the public page keeps them until Publish |
| `restoreSection`, `restoreBlock` | Undo a soft delete |

Positions count **live** siblings: a deleted block the editor cannot see does
not take a slot.

## Refusals

A change the model cannot take throws `ContentManipulationException`. Its
`reason` is a stable code:

| `reason` | When |
|---|---|
| `unknown_block_type` | `addBlock()` with a type no block is registered for |
| `unknown_layout` | `addSection()` with a layout that is unknown or disabled |
| `too_many_columns` | `addColumn()` past 20 columns |
| `last_column` | `deleteColumn()` on a section's only live column |
| `foreign_target` | `moveBlock()` into a column of another area |

## Decorating it

Every structural change goes through the service: the builder's section,
column and block endpoints, and the flows that bring in content from
elsewhere. Paste, section templates, import and "Insert content" build their
section or block from a payload, then place it with `insertSection()` or
`insertBlock()`.

So decorating `ContentManipulatorInterface` sees editors' changes as well as
your own code's. A host can, for instance, fill every new section with a
default block by decorating `addSection()`. Each method is its own entry
point: a section can arrive through `addSection()`, `duplicateSection()` or
`insertSection()`, and a decorator that wants all of them wraps all three.
