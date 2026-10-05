# Simplifying the builder

By default an editor builds a page: they add sections, pick their columns, lay them out and style them. Many projects want less than that. A product description is a stack of blocks, and a landing page template has a skeleton the editor should fill rather than redesign. Three settings narrow the builder around the blocks:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    structure:
        sections: editable      # editable | fixed | hidden
        columns: true           # false: columns cannot be added, removed or laid out
    styling: true               # false: no spacing, background or alignment fields
```

`styling` is covered in [Styling → A builder without styling fields](./styling.md#a-builder-without-styling-fields). This page covers `structure`.

Blocks are never affected: in every mode the editor adds, edits, moves, duplicates and deletes blocks as usual.

## The three section modes

| | `editable` (default) | `fixed` | `hidden` |
|---|---|---|---|
| Add a section, insert a saved section, paste a section | yes | no | no |
| Move, duplicate or delete a section | yes | no | no |
| Select a section and edit its settings | yes | yes | no |
| Sections drawn in the preview and the navigator | yes | yes, without actions | no |

### `hidden`: blocks only

The editor sees a column of blocks and nothing else. There is no section handle, no section outline, no section sidebar, and the navigator lists the blocks flat.

Under the hood the blocks still live in a section, since that is what the public render walks. The server creates it with the first block: an empty area shows an **Add a block** button, and choosing a type adds a full-width section (layout `full`, with your `section.initial_settings`) holding that block. The section is a draft like everything else, so Publish puts it live and Discard removes it. After that, each block is added below the others through the column's **+ Block** button. A block pasted with nothing selected goes to the end of the area.

Keep the built-in `full` layout enabled in this mode. With `section.layouts.full: false`, an empty area has no section to create, and the builder says so.

An area that already holds several sections keeps rendering them. Their blocks stay editable, but no section can be selected.

### `fixed`: a skeleton to fill

The sections in place are the host's. Editors can still select a section and change its settings (width, styling, presets), but cannot add, move, duplicate, delete or paste one. The section library and the add-section buttons are gone, and so is the section toolbar in the preview.

Create the skeleton from code ([Content from code](./content-from-code.md)), from an import, or by building it with `editable` before switching. An empty area in `fixed` mode has nothing for the editor to add blocks into.

## Locked columns

`columns: false` removes the **Columns** panel (add, name, remove) and the **Layout** panel (display as grid, slider, tabs or accordion, column widths, reverse on mobile) from the section sidebar. A section keeps the columns of the layout it was created with. Combined with `section.layouts`, this gives editors a short, closed list:

```yaml
content_blocks:
    structure:
        columns: false
    section:
        layouts:
            three_cols: false   # editors pick full or two columns, and that is final
```

In `hidden` mode the columns are locked whatever this setting says, since there is no section sidebar to edit them in.

## Per area

The configuration applies to every area. To choose by owning entity, for example blocks only for products and a full builder for pages, alias `BuilderStructureResolverInterface`:

```php
use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Builder\ConfiguredBuilderStructureResolver;
use ContentBlocks\Entity\ContentArea;

final class AppBuilderStructureResolver implements BuilderStructureResolverInterface
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ConfiguredBuilderStructureResolver $configured,
    ) {
    }

    public function forArea(ContentArea $area): BuilderStructure
    {
        if ($this->products->findOneBy(['contentArea' => $area]) !== null) {
            return new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN);
        }

        return $this->configured->forArea($area);
    }
}
```

```yaml
# config/services.yaml
ContentBlocks\Builder\BuilderStructureResolverInterface:
    alias: App\Builder\AppBuilderStructureResolver
```

The resolver is asked on every builder request, so keep it cheap, and keep no per-request state on `$this` ([Worker mode](./worker-mode.md)).

## What is enforced

The settings are not only a matter of what the UI shows. The endpoints refuse what the area's structure rules out with a `409` carrying `error: "refused"` and `reasons: ["structure"]`:

- adding, moving, duplicating, deleting and restoring a section, and inserting a saved section;
- pasting a section (a `422` with `error: "sections_locked"`, and the copy stays in the clipboard for another area);
- reordering sections per viewport;
- adding, removing and naming columns (`columns: false`, or `hidden`);
- the section sidebar itself, in `hidden` mode.

Three things are left to their own settings, because they replace the whole area rather than edit its structure: **Insert content** (`enable_replace`), **Import** (`enable_import`) and **Discard**. Undo replays what this editing session recorded and is not checked again, so it can only undo what the structure allowed when it was done.

The public render does not change. These settings shape the builder, not the page.
