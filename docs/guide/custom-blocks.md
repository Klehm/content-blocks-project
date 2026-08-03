---
title: Custom blocks
---

# Building a custom block

A block type is a small service. Extend `AbstractBlockType` (or implement `BlockTypeInterface` directly), annotate it with `#[AsContentBlock]`, and Symfony autoconfiguration does the rest — the `BlockTypeCompilerPass` auto-tags it and the `BlockTypeRegistry` picks it up. No manual registration.

## Minimal example

Extending `AbstractBlockType` gives you sensible defaults for the optional methods (`getIcon()`, `getFormTheme()`, `getViewTemplate()`, `supportsPreviewHotReload()`), so a block only has to declare its type, label, form and default data:

```php
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

#[AsContentBlock]
final class MyBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'my_block';
    }

    public static function getLabel(): string
    {
        return 'My Block';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('content', TextType::class, ['data' => $data['content'] ?? '']);
    }

    public function getDefaultData(): array
    {
        return ['content' => ''];
    }

    public function getViewTemplate(): ?string
    {
        return 'block/my_block.html.twig';
    }
}
```

The block is detected automatically as long as its class lives in a namespace loaded by the service container. It then appears in the block picker, and its `data` shape is whatever `buildForm()` declares.

## Everything a block class can define

`BlockTypeInterface` has eight methods. Four are required; the other four have defaults from `AbstractBlockType`, so you only override the ones you need.

| Method | Required? | Purpose |
|---|---|---|
| `getType(): string` | ✅ | Unique machine id (`'my_block'`). Stored in `cb_block.type`. |
| `getLabel(): string\|TranslatableInterface` | ✅ | Label in the picker & block toolbar. String, or a `TranslatableMessage` for a custom translation domain — [see below](#translatable-labels). |
| `buildForm(FormBuilderInterface, array $data): void` | ✅ | The edit form. **This is also the data whitelist & validator** — [see below](#the-form-is-your-data-whitelist). |
| `getDefaultData(): array` | ✅ | Initial `data` for a freshly-added block. |
| `getViewTemplate(): ?string` | default `null` | The Twig template that renders the block front-and-preview — [see below](#the-view-template). `null` ⇒ generic key/value dump. |
| `getIcon(): ?string` | default `null` | Inline SVG shown in the picker — [see below](#an-icon-in-the-picker). `null` ⇒ generic glyph. |
| `getFormTheme(): ?string` | default `null` | A custom Twig form theme for the edit form — [see below](#a-custom-form-theme). |
| `supportsPreviewHotReload(): bool` | default `false` | Swap this block in place instead of reloading the iframe — [see below](#opting-into-preview-hot-reload). |

Beyond the interface, the `#[AsContentBlock]` attribute takes a `priority` that [orders the block in the picker](#ordering-blocks-in-the-picker), and — because a block is a real service — you can [inject dependencies](#injecting-services) through its constructor.

## The view template

`getViewTemplate()` returns the Twig template that renders the block — on the **public page and inside the preview alike**. It receives the block's stored `data` array (rendered with `with_context = false`, so `data` is the only variable in scope):

```twig
{# templates/block/my_block.html.twig #}
<div class="my-block">
    {{ data.content }}
</div>
```

Where the file lives depends on how you ship the block:

| You are… | Put the template at | Return from `getViewTemplate()` |
|---|---|---|
| A **host app** (block in `src/`) | `templates/block/my_block.html.twig` | `'block/my_block.html.twig'` |
| A **distributable bundle** | `<BundleRoot>/templates/block/my_block/view.html.twig` | `'@YourBundle/block/my_block/view.html.twig'` |

Symfony auto-registers a bundle's `templates/` directory under its `@YourBundle` Twig namespace — which is exactly how the kit points at `'@ContentBlocksKit/block/alert/view.html.twig'`.

::: warning A view template is effectively required for a real block
`AbstractBlockType::getViewTemplate()` returns `null` by default, which renders a **generic key/value dump** of the block's data — fine as a placeholder, but not a real front-end. Any block you actually ship should return a template path.
:::

Hosts (or the kit's users) can override your template without forking: drop a file at the matching path under `templates/bundles/YourBundle/…`. See [Overriding render templates](./rendering.md#overriding-render-templates).

::: tip Blocks not detected?
Check that the block class sits in a namespace wired into the container. In the kit, `config/services.php` explicitly loads `ContentBlocks\Kit\Block\` — a custom block in your own app needs the equivalent for its own namespace.
:::

## An icon in the picker

`getIcon()` returns **self-contained inline SVG** shown next to the label in the "+ block" picker. Use `currentColor` for strokes/fills so the icon inherits the picker's theme color:

```php
public static function getIcon(): ?string
{
    return <<<'SVG'
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
        </svg>
        SVG;
}
```

::: danger Trusted markup only
The SVG is injected into the picker DOM **as-is**. It must come from your block-author code — never interpolate user input into it.
:::

Return `null` (the `AbstractBlockType` default) to fall back to a generic glyph.

## Ordering blocks in the picker

The picker grid renders blocks in registration order, which is driven by the `priority` argument of `#[AsContentBlock]` — **higher priority first**. Blocks sharing a priority keep their service-discovery order. Default is `0`.

```php
#[AsContentBlock(priority: 100)]  // floats to the top of the picker
final class MyBlock extends AbstractBlockType { /* … */ }
```

## Translatable labels

`getLabel()` may return a plain string (already-translated) or a `TranslatableInterface`. Return a `TranslatableMessage` when the label lives in a custom translation domain — the renderer translates it at the boundary before exposing it to the front (picker, JSON endpoints):

```php
use Symfony\Component\Translation\TranslatableMessage;

public static function getLabel(): string|TranslatableInterface
{
    return new TranslatableMessage('block.my_block.label', [], 'messages');
}
```

## A custom form theme

By default the edit form renders with the builder's form theme. To customize the markup of your block's fields, ship a Twig form theme and point at it from `getFormTheme()`:

```php
public function getFormTheme(): ?string
{
    return '@YourBundle/form/my_block_theme.html.twig';
}
```

The kit uses this mechanism for its richer fields (image upload, palette color). For simple field tweaks you usually don't need it — a `row_attr`/`attr` on the field or the shared `cb-condition` controller is enough.

## Injecting services

A block type is a fully-fledged autowired service, so you can inject dependencies through its constructor — a repository, the `UrlGeneratorInterface`, a settings service — and use them in `buildForm()` or a custom method:

```php
#[AsContentBlock]
final class LatestPostsBlock extends AbstractBlockType
{
    public function __construct(private readonly PostRepository $posts) {}

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('category', ChoiceType::class, [
            'choices' => $this->posts->findCategories(), // resolved via DI
            'data' => $data['category'] ?? null,
        ]);
    }

    // getType(), getLabel(), getDefaultData(), getViewTemplate()…
}
```

::: warning Introspection instantiates with `new`
Tooling that introspects blocks outside the container (e.g. the kit's `content-blocks-kit:blocks` command) constructs them with `new $class()`. Give every constructor argument a default (or tolerate a bare `new`) if you want your block to stay introspectable — the kit's `AbstractKitBlock` does exactly this.
:::

## The form is your data whitelist

::: info Security by declaration
A block's `data` is never written raw — the block's Symfony form **is** its whitelist and validator. The compound form only maps its declared children (unexpected POST keys are dropped), and each field's `constraints` run on submit (a failure writes nothing).
:::

There is no `sanitizeData()` / `getAllowedDataKeys()` hook to implement — you secure a block's data purely through the fields and constraints declared in `buildForm()`. For a field with a fixed set of allowed values, add an `Assert\Choice` constraint derived from the full coded choice set. See [Security → Block data sanitization](./security.md#block-data-sanitization) for the full rationale.

### Two names the package owns

- **Never declare a field whose name starts with `_`.** The prefix is reserved by ContentBlocks at every level of `Block.data`, including collection entries.
- **`_id`** is what it uses today: every entry of a `CollectionType` / `LiveCollectionType` field gets one automatically, so a reorder, duplicate or delete never shifts what per-entry information points at. You do not declare it, render it, or maintain it — but do not strip it either if you post-process `data` yourself.

## Opting into preview hot reload

By default an inline edit triggers a full iframe reload. If your block's rendered **view** is self-contained (static HTML or CSS-only, no JS init needed once the markup lands in the DOM), opt into in-place swapping:

```php
public function supportsPreviewHotReload(): bool
{
    return true;
}
```

This is about the rendered view, not the edit form — a block whose *form* uses JavaScript (upload widget, rich-text editor) can still opt in, because that JS lives in the sidebar, never in the preview. If the view needs a little JS of its own, re-initialise idempotently from the `cb:block:rendered` event. See [Rendering → Preview hot reload](./rendering.md#preview-hot-reload) for the full details.

## Styling your block

Custom blocks automatically get the shared **Styling** group (padding, margin, background color, max-width) in their edit form, and can reuse the palette color type and the `cb-condition` conditional-field controller. See [Styling](./styling.md) for how to extend the styling sub-form and add block decorators.

## Recipes

Common tasks that build on this page:

- [Add a field to an existing / kit block](./recipes/add-block-field.md) — e.g. a subtitle on the `button` block, without forking.
- [Add a field to the section settings](./recipes/add-section-field.md) — extend the section sidebar and render the value.
