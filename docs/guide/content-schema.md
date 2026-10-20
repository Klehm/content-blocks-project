# Content schema for AI

To generate a page, a language model has to know what it may write: which blocks exist, what each one is for, which fields each accepts, which layouts and colours the site uses, and how sections, columns and blocks nest. The content schema puts all of this in one JSON document, built from what your app already declares. You write no description by hand except the purposes, and those are optional.

```bash
php bin/console content-blocks:schema              # the whole document, pretty-printed
php bin/console content-blocks:schema --only=schema --compact
php bin/console content-blocks:schema --area=42 --locale=en --examples=5
```

The same document is served to the builder's editors at `GET /_content-blocks/area/{id}/schema` (route `content_blocks_area_schema`). It requires `canEdit()` on the area, because it names your palette and your saved sections. `?examples=N` caps the example count at 10, and labels follow the request locale.

No model and no vendor SDK ship with the package: the schema is what you hand to the model, through your own pipeline, an MCP server, or a prompt.

## The document

```json
{
    "format": "content-blocks/schema-v1",
    "contentVersion": 1,
    "structure": { "sections": "editable", "columns": true },
    "layouts": [{ "name": "two_cols", "label": "2 columns", "columns": ["col-6", "col-6"], "display": "grid" }],
    "palette": [{ "label": "Brand", "color": "#eb0540", "tone": "dark", "role": "Calls to action only." }],
    "sectionStyles": [{ "name": "dark", "label": "Dark band", "role": "A highlighted section, once per page." }],
    "blocks": [{ "type": "card", "label": "Cards", "category": "layout", "purpose": "A set of cards side by side: features, benefits, offers." }],
    "examples": [{ "name": "Hero", "section": { "format": "content-blocks/section-v1", "layout": "two_cols", "columns": [] } }],
    "schema": { "$schema": "https://json-schema.org/draft/2020-12/schema", "…": "…" }
}
```

| Key | What it holds |
|---|---|
| `structure` | The area's [structure mode](./builder-structure.md). With `sections: hidden` the schema allows one full-width section without settings, and carries no examples. |
| `layouts` | The layouts editors can pick, with the `Column.preset` of each column. |
| `palette` | The colours of `content_blocks.palette` and of your palette providers, with their tone (`dark` / `light`) and role. |
| `sectionStyles` | The section style presets, with their role. Their settings are left out: the model picks a preset by name. |
| `blocks` | Every registered block type: label, picker category and purpose. |
| `examples` | Your latest saved sections (the section library), in the output shape. |
| `schema` | The JSON Schema of the output: `{"sections": [...]}`, each section in the `content-blocks/section-v1` shape that saved sections and the clipboard already use. |

## Where the data comes from

The JSON Schema of each block's `data` is **derived from the block's own form**, the same form that validates the data when an editor saves it. Field types, the values a choice offers, `Length`, `Count` and `Range` limits, collections and their entries, labels and help texts all come from there. A host's block form extensions and the block styling fields come along. When you change a form, the schema changes with it.

The schema still guides the model; it does not vouch for what comes back. Generated content has to go back in through the paste path, which replays each block through its form: an undeclared key is dropped, and a refused value falls back to the type's default.

## Block purposes

The form says what a block accepts, not what it is for. A model composing a page that sells needs the second, so a block type can say it in one or two sentences:

```php
use ContentBlocks\BlockType\BlockPurposeInterface;

#[AsContentBlock(category: 'commerce')]
final class PricingBlock extends AbstractBlockType implements BlockPurposeInterface
{
    public function getPurpose(): string
    {
        return 'Plans side by side with their price and a buy button. Once per page, near the end.';
    }
    // ...
}
```

Write it in English, for a model, not for an editor. Every kit block declares one. To describe a block the way your site uses it, including a kit block, override it in the configuration:

```yaml
content_blocks:
    schema:
        block_purposes:
            card: 'Our three subscription plans, one card each.'
        examples: 3        # saved sections carried as examples; 0 for none
```

## Roles for colours and presets

Your design rules already live as data: the palette, the section presets, the layouts. To follow them, the model only has to pick from those named sets, as long as it knows what each one is for. Give them a `role`:

```yaml
content_blocks:
    palette:
        - { label: 'Brand', color: '#eb0540', role: 'Brand accent: call-to-action buttons only.' }
        - { label: 'Ink', color: '#111827', role: 'Dark backgrounds, with white text.' }
    section_styles:
        - { name: dark, label: 'Dark band', css_class: 'site-band--dark', role: 'A highlighted section, once per page.' }
```

A palette provider passes it as the fourth argument of `PaletteColor`, and a section style provider as the fifth argument of `SectionStyle`.

A colour field accepts any palette value, an empty string for none, or a free `#rrggbb` when the field allows custom colours. The schema says to prefer the palette.

## Examples from the section library

The sections your editors saved to the library are the best description of your house style. The schema carries the latest ones (three by default) in the exact output shape. A template saved under another `content_version`, or holding a block type the app no longer has, is left out, since it would teach a shape the paste path refuses.

Image fields hold the path of a file already uploaded. A model cannot produce one, so the schema tells it to reuse a path it was given (the examples carry real ones) or leave the field empty.

## A field type of your own

The mapper reads a field's stored value off its children. That does not work for a type with its own data mapper, which stores something other than its children: the core's colour field stores one string while its form has two. Such a type gets a contributor, which autoconfiguration registers:

```php
use ContentBlocks\Schema\FieldSchemaContributorInterface;
use ContentBlocks\Schema\FormSchemaMapper;
use Symfony\Component\Form\FormInterface;

final class MoneyFieldSchema implements FieldSchemaContributorInterface
{
    public function schemaFor(FormInterface $field): ?array
    {
        if (!FormSchemaMapper::is($field, MyMoneyType::class)) {
            return null;
        }

        return ['type' => 'integer', 'description' => 'Amount in cents.'];
    }
}
```

The field's constraints, label, help and default are still added on top. The core's own contributors (colour, upload) run last, so yours can redraw a core type. A field the mapper does not know is described as a plain string, with its help text.
