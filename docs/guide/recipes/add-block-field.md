---
title: Add a field to a block
---

# Recipe: add a field to an existing / kit block

**Goal:** add a **subtitle** field to the kit's `button` block, without forking the kit.

::: danger Kit config can't add fields
The `content_blocks_kit.blocks.<type>` config (`options`, `choices`, `defaults`) only **restricts, reorders, or overrides existing** fields — it cannot introduce a new one. `defaults` for an unknown key is silently discarded, and `choices` can't invent a field. To add a field you extend the block's form in PHP.
:::

The recommended way is a **block form extension**: a small class that declares which block type(s) it targets and adds fields to their edit form — no subclassing, no config toggle, and existing stored blocks keep rendering.

## 1. Write the form extension

Implement `BlockFormExtensionInterface` and tag it with `#[AsBlockFormExtension('button')]`. It is autoconfigured — no wiring needed beyond the standard `App\` service registration.

```php
// src/ContentBlocks/Form/ButtonSubtitleExtension.php
namespace App\ContentBlocks\Form;

use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

#[AsBlockFormExtension('button')] // this block only
final class ButtonSubtitleExtension implements BlockFormExtensionInterface
{
    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $builder->add('subtitle', TextType::class, [
            'required' => false,
            'data' => $data['subtitle'] ?? '',
        ]);
    }
}
```

That's it — `BlockFormType` calls your extension **after** the button block's own `buildForm()`, so your field appears alongside the block's fields. The kit's `button` block is untouched and still owns the `button` type.

::: tip One mechanism, both scopes
- **One or more types:** `#[AsBlockFormExtension('button')]` or `#[AsBlockFormExtension(['button', 'card'])]`. The `$blockType` argument tells you which one is being built when you target several.
- **Every block (global):** `#[AsBlockFormExtension]` with no arguments — the extension runs for all block types. Use `$blockType` to branch if needed.
- **Ordering:** `#[AsBlockFormExtension('button', priority: 10)]` — higher priority runs first (fields appear earlier). Extensions sharing a priority keep discovery order.
:::

::: tip Block field values are stored as-is
Unlike section settings, a block's data is **not** run through an empty-pruning pass — whatever the compound block form produces is written to the block's draft data. Only declared children map, so your `subtitle` key round-trips (`subtitle: ''` persists as an empty string) while unexpected POST keys are dropped. Read it with `data.subtitle` in the template.
:::

## 2. Render the new field

The kit's `button` block renders through `@ContentBlocksKit/block/button/view.html.twig`. Override just that one file in your app (Symfony bundle template override, no forking):

```twig
{# templates/bundles/ContentBlocksKitBundle/block/button/view.html.twig #}
{# copy the kit's original markup, then add: #}
<a class="cb-kit-button cb-kit-button--{{ data.variant }}" href="{{ data.url }}">
    {{ data.label }}
    {% if data.subtitle %}
        <span class="cb-kit-button__subtitle">{{ data.subtitle }}</span>
    {% endif %}
</a>
```

The view template receives the block's full `data` array, so `data.subtitle` is available. Start from the kit's shipped template (`packages/content-blocks-kit/templates/block/button/view.html.twig`) and add your field, keeping the existing `cb-kit-*` classes.

## A field on *every* block: pair it with a decorator

A **global** extension (`#[AsBlockFormExtension]`, no arguments) adds the same field to every block — but overriding every block's template to render it would defeat the purpose. Use a `BlockDecoratorInterface` instead: it is called for every block being rendered and contributes classes, attributes or inline styles to the block's outer `<div>`.

```php
// Form half: the field, on every block.
#[AsBlockFormExtension(priority: -100)] // runs last → field lands at the end of the form
final class AnchorIdExtension implements BlockFormExtensionInterface
{
    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $builder->add('anchorId', TextType::class, [
            'required' => false,
            'data' => $data['anchorId'] ?? '',
            'attr' => ['data-cb-group' => 'SEO'], // own tab in the block sidebar
        ]);
    }
}

// Render half: turn the stored key into markup, for every block at once.
final class AnchorIdBlockDecorator implements BlockDecoratorInterface
{
    public function decorate(array $data, Block $block): BlockDecoration
    {
        $anchor = trim((string) ($data['anchorId'] ?? ''));

        return '' === $anchor
            ? new BlockDecoration()
            : new BlockDecoration(attributes: ['id' => $anchor]);
    }
}
```

::: tip Group fields into a tab
`attr: ['data-cb-group' => 'SEO']` puts the field in its own tab of the block edit sidebar (fields without the attribute fall into "General"; "Style" is always last). Handy to keep host-added fields out of the block's own field list.
:::

## Removing and reordering fields

The builder an extension receives is the block's **own** form builder, so the seam is not add-only: you can also take fields away and change their order.

### Remove a field

```php
#[AsBlockFormExtension('button', priority: 20)] // early: prune before the rest runs
final class ButtonFieldRemovalExtension implements BlockFormExtensionInterface
{
    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        if ($builder->has('fullWidth')) { // survives a kit upgrade that renames it
            $builder->remove('fullWidth');
        }
    }
}
```

Two consequences, both worth knowing:

- **No data loss.** The form's model data *is* the block's data array, so a removed field's stored value is **frozen, not deleted** — blocks created before the extension keep their `fullWidth: true` and keep rendering that way. Reset them with a migration if the rule must apply retroactively.
- **It doubles as a hard whitelist.** Only declared children map, so a crafted POST still carrying `fullWidth` is dropped (it lands in the form's extra data) instead of reaching the draft.

### Reorder fields

A form has no "set order" API — children render in **insertion order**, so reordering means re-adding them in the order you want. Re-add the child *builder* rather than the field name: its type, options, data and constraints come along, so nothing has to be re-declared.

```php
#[AsBlockFormExtension('button', priority: -1000)] // last: sees fields added by others
final class ButtonFieldOrderExtension implements BlockFormExtensionInterface
{
    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $first = array_values(array_filter(['url', 'text'], fn ($n) => $builder->has($n)));
        $rest = array_values(array_diff(array_keys($builder->all()), $first));

        foreach ([...$first, ...$rest] as $name) {
            $child = $builder->get($name);  // keeps type + options + data
            $builder->remove($name);
            $builder->add($child);          // re-added at the end
        }
    }
}
```

::: warning Ordering only shows within a tab
Fields are grouped into sidebar tabs by `data-cb-group`, so a reorder is visible **inside** a group, not across groups. And the styling sub-form is added by `BlockFormType` *after* every extension has run, so the "Style" tab is always last whatever an extension does.
:::

::: tip Reference implementation
All four examples are wired in the two sandboxes and covered end to end — copy from there:
- `ButtonRelExtension.php` (targeted add) + the app's override of `templates/bundles/ContentBlocksKitBundle/block/button/view.html.twig`
- `AnchorIdExtension.php` (global add) + `src/ContentBlocks/Block/AnchorIdBlockDecorator.php` (render)
- `ButtonFieldRemovalExtension.php` (remove) and `ButtonFieldOrderExtension.php` (reorder)

Under `apps/content-blocks-sandbox/src/ContentBlocks/Form/`, and the same files under `apps/content-blocks-sylius-sandbox/`.
:::

## Alternatives

::: details Subclass the block (when you also need to change its behaviour)
If you need to override more than the form — e.g. the block's default data, or one of its methods — subclass the block and disable the original so your version takes over the same `button` type.

```php
// src/ContentBlocks/Block/ButtonWithSubtitle.php
namespace App\ContentBlocks\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Block\ButtonBlock;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

#[AsContentBlock(priority: 55)] // same priority the kit uses, to keep picker order
final class ButtonWithSubtitle extends ButtonBlock
{
    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        parent::buildForm($builder, $data); // keep all of button's fields
        $builder->add('subtitle', TextType::class, [
            'required' => false,
            'data' => $data['subtitle'] ?? '',
        ]);
    }

    public function getDefaultData(): array
    {
        return [...parent::getDefaultData(), 'subtitle' => ''];
    }
}
```

`getType()` is inherited and still returns `'button'`, so this class **is** the button block as far as the registry and stored data are concerned. Then disable the kit's original so two services don't claim the `button` type:

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        button: { enabled: false } # our App\...\ButtonWithSubtitle takes over 'button'
```

For a form-only change, prefer the `AsBlockFormExtension` approach above — it needs neither the subclass nor the config toggle.
:::

::: details `FormTypeExtension` on `BlockFormType` (why it can't scope to one block)
Every block's edit form is built on one shared type, `ContentBlocks\Form\Type\BlockFormType` (there is no per-block form type). A stock Symfony `FormTypeExtension` targeting it therefore fires for **all** blocks and **cannot be scoped to `button` via `getExtendedTypes()`** — you'd need a runtime `instanceof` guard on `$options['block_type']`. The `AsBlockFormExtension` API exists precisely to make this per-block scoping first-class, so reach for it instead.
:::

## Related

Need to add a CSS class or attribute to a block's outer element based on its data (rather than a form field)? Implement `ContentBlocks\Block\BlockDecoratorInterface` (the block-level mirror of the [section decorator](./add-section-field.md#_2-render-the-value)) — tag `content_blocks.block_decorator`, autoconfigured, returns a `BlockDecoration` of classes / attributes / inline styles.
