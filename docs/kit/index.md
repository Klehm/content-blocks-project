---
title: Block Kit
---

# Block Kit

`klehm/content-blocks-kit` is an optional package of **17 ready-to-use block types** for [ContentBlocks](/guide/). Install it and you get a full block palette out of the box — no configuration required.

The kit is **self-contained**: no Tailwind, no Bootstrap, no LiipImagine, no icon library. Every block renders neutral `cb-kit-*` markup styled by a single shipped stylesheet, so it drops into any host regardless of its CSS setup.

## The 17 blocks

| Block | What it is |
|---|---|
| [`title`](./blocks/title.md) | Heading with a visual size decoupled from its semantic tag |
| [`text`](./blocks/text.md) | Plain paragraph text with a palette color |
| [`rich_text`](./blocks/rich_text.md) | WYSIWYG rich text, on TinyMCE or CKEditor |
| [`image`](./blocks/image.md) | Image with size, fit, align, link, caption, rounded corners |
| [`gallery`](./blocks/gallery.md) | Image grid or arrow slider |
| [`button`](./blocks/button.md) | Call-to-action button (variants, sizes, alignment) |
| [`card`](./blocks/card.md) | Image/title/text/button tiles as a grid or list |
| [`list`](./blocks/list.md) | Bulleted / checkmark / numbered list |
| [`icon`](./blocks/icon.md) | A single icon from the shipped icon set |
| [`alert`](./blocks/alert.md) | Info / success / warning / error callout |
| [`divider`](./blocks/divider.md) | Horizontal rule (style + color) |
| [`accordion`](./blocks/accordion.md) | Collapsible panels (native `<details>`, zero JS) |
| [`table`](./blocks/table.md) | Columns + rows data table |
| [`embed`](./blocks/embed.md) | Responsive YouTube / Vimeo embed |
| [`breadcrumb`](./blocks/breadcrumb.md) | Breadcrumb trail |
| [`tabs`](./blocks/tabs.md) | Tabbed panels |
| [`html_raw`](./blocks/html_raw.md) | Raw-HTML escape hatch (**disabled by default**) |

Each block has its own reference page with its full configurable surface — see the sidebar.

## Installation

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

The blocks are auto-registered via Symfony autoconfiguration — no config needed to get all of them (except `html_raw`, which is [disabled by default](./blocks/html_raw.md)).

### Front stylesheet (required)

Kit blocks render with neutral `cb-kit-*` classes styled by a stylesheet the kit serves at a public route. Include it once in your front layout (it also flows into the builder preview):

```twig
<link rel="stylesheet" href="{{ path('content_blocks_kit_asset_css') }}">
```

Retheme by overriding the `--cb-kit-*` custom properties (or the classes) in your own stylesheet loaded after it.

### Stimulus controllers

Two blocks need a Stimulus controller. Enable them in your host `assets/controllers.json` under the `@klehm/content-blocks-kit` package — `rich_text` has one per selectable editor, so enable the one you configured (or both, if you are unsure):

| Controller | Needed by |
|---|---|
| `cb-tinymce` | [`rich_text`](./blocks/rich_text.md) on its default editor |
| `cb-ckeditor` | [`rich_text`](./blocks/rich_text.md) when `options.editor: ckeditor` |
| `cb-gallery` | [`gallery`](./blocks/gallery.md) (slider layout only) |

## Colors

Every color field in the kit — icon and divider colors, the `title` and `text` blocks' text color, and the rich-text editor's swatches — draws from the **one** core palette declared in `content_blocks.palette`. Add a named color there once and it appears everywhere:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    palette:
        - { label: 'Brand', color: '#eb0540' }
```

### The kit's own tokens

The palette covers what an *editor* picks per block. What a **developer** sets once, for every kit block on the site, is a set of seven CSS custom properties declared by `kit.css`:

| Token | Default | Drives |
|---|---|---|
| `--cb-kit-primary` | `#4f46e5` | primary button fill, list markers, icon default |
| `--cb-kit-primary-contrast` | `#ffffff` | text on a primary fill |
| `--cb-kit-secondary` | `#64748b` | secondary button fill |
| `--cb-kit-secondary-contrast` | `#ffffff` | text on a secondary fill |
| `--cb-kit-border` | `#d1d5db` | alert, table and card rules |
| `--cb-kit-text` | `#1f2937` | body text inside kit components |
| `--cb-kit-radius` | `8px` | corner radius across the kit |

They are declared inside `:where(.cb-kit-btn, .cb-kit-alert, .cb-kit-list, .cb-kit-icon)`, and `:where()` carries **zero specificity** — a single rule anywhere in your theme wins without `!important`:

```css
/* your front stylesheet, loaded after kit.css */
.cb-kit-btn, .cb-kit-alert, .cb-kit-list, .cb-kit-icon {
    --cb-kit-primary: #eb0540;
    --cb-kit-radius: 2px;
}
```

These names are public surface, covered by the package's semver guarantee. Note they style **content**, on the published page — they have nothing to do with the `--cb-*` tokens that theme the builder chrome ([styling guide](../guide/styling.md#theming-the-builder-chrome)).

One block adds tokens of its own, because its colors have no equivalent elsewhere in the kit — `tabs`, whose open tab has to sit on the panel it opens:

| Token | Default | Drives |
|---|---|---|
| `--cb-kit-tabs-line` | `#e5e7eb` | panel border, and the rule under the tab row |
| `--cb-kit-tabs-tab` | `#6b7280` | a closed tab's label |
| `--cb-kit-tabs-tab-active` | `#1f2937` | the open tab's label (and any hovered one) |
| `--cb-kit-tabs-panel-bg` | `#ffffff` | panel fill, reused by the open tab so it joins the panel |
| `--cb-kit-tabs-accent` | `#4f46e5` | keyboard focus ring |

Same rule as above: declared inside `:where(.cb-kit-tabs)`, so `.cb-kit-tabs { --cb-kit-tabs-line: … }` in your stylesheet wins.

## Extending a kit block

Overriding a template changes what a block *renders*. When you need it to **edit** something the kit does not offer — one extra field, a different default, a narrower choice set — subclass the block instead. This is a supported path: the 17 block classes are deliberately non-final, and their `protected` methods are covered by the package's semver guarantee.

Disable the kit's service and register yours in its place, keeping the same type id so stored content keeps working:

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        button: { enabled: false }
```

```php
#[AsContentBlock]
final class AppButtonBlock extends ContentBlocks\Kit\Block\ButtonBlock
{
    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        parent::buildForm($builder, $data);
        $builder->add('trackingId', TextType::class, ['required' => false, 'data' => $data['trackingId'] ?? '']);
    }

    protected function defaults(): array
    {
        return parent::defaults() + ['trackingId' => ''];
    }
}
```

Three things to know before you do:

- **`getDefaultData()` is `final`.** It merges `defaults()` with the host's `content_blocks_kit.blocks.<type>.defaults` config, so replacing it would silently drop that config. Grow `defaults()` instead.
- **Keep `getType()` inherited, and disable the kit's service.** Two services claiming one type id is a silent conflict — the registry keeps whichever was registered last, and which one that is depends on container order.
- **The config still applies.** `enabled: false` only un-registers the kit's *service*; `options`, `choices` and `defaults` declared under that same type reach your subclass, merged over its own coded defaults. So subclassing to add one field does not cost you the configuration of everything else:

  ```yaml
  content_blocks_kit:
      blocks:
          divider:
              enabled: false                                     # yours takes over
              choices: { style: { solid: 'Solid', double: 'Double' } }
              defaults: { style: 'double' }
  ```

  A subclass that gives itself a new `getType()` is configured under that new id instead — the config is keyed by the type, never by the class.

The full contract, including `choiceFields()` and `describe()`, is in the [package README](https://github.com/klehm/content-blocks-kit#extending-a-kit-block).

## Discovering the surface from the CLI

Every block's options, choice fields (default marked `*`) and data defaults are introspectable — read straight from the code, so the output never goes stale:

```bash
bin/console content-blocks-kit:blocks              # all blocks, human-readable
bin/console content-blocks-kit:blocks button       # one block
bin/console content-blocks-kit:blocks --format=json   # machine-readable (drives these docs)
```

The per-block reference pages in this section are **generated** from that JSON, so they always match the installed version.

## Next

- **[Configuring blocks](./configuration.md)** — the four per-block levers (`enabled`, `options`, `choices`, `defaults`) and how they combine.
- **Individual block pages** — see the sidebar for all 17.
- **[Overriding block templates](./configuration.md#overriding-block-templates)** — swap any block's markup.
