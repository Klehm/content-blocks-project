---
title: Add a field to a block
---

# Recipe: add a field to an existing / kit block

**Goal:** add a **subtitle** field to the kit's `button` block, without forking the kit.

::: danger Kit config can't add fields
The `content_blocks_kit.blocks.<type>` config (`options`, `choices`, `defaults`) only **restricts, reorders, or overrides existing** fields — it cannot introduce a new one. `defaults` for an unknown key is silently discarded, and `choices` can't invent a field. To add a field you extend the block's form in PHP.
:::

The robust, backward-compatible approach is **subclass the block and disable the original**, so your version takes over the same `button` type (existing stored buttons keep rendering).

## 1. Subclass the block, add the field

A kit block is a normal PHP class. Extend it, call `parent::buildForm()`, then add your field. Merge your key into the default data too:

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

`getType()` is inherited and still returns `'button'`, so this class **is** the button block as far as the registry and stored data are concerned. It is auto-registered like any block (`#[AsContentBlock]` + the standard autoconfigured `App\` services).

::: tip Block field values are stored as-is
Unlike section settings, a block's data is **not** run through an empty-pruning pass — whatever the form produces is written to the block's draft data. So `subtitle: ''` persists as an empty string; read it with `data.subtitle` in the template.
:::

## 2. Disable the kit's original button

Otherwise two services would claim the `button` type. Turn the kit's off so yours wins cleanly:

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        button: { enabled: false } # our App\...\ButtonWithSubtitle takes over 'button'
```

## 3. Render the new field

The kit's `button` block renders through `@ContentBlocksKit/block/button/view.html.twig`. Your subclass inherits that template reference — override just that one file in your app (Symfony bundle template override, no forking):

```twig
{# templates/bundles/ContentBlocksKitBundle/block/button/view.html.twig #}
{# copy the kit's original markup, then add: #}
<a class="cb-kit-button cb-kit-button--{{ data.variant }}" href="{{ data.href }}">
    {{ data.label }}
    {% if data.subtitle %}
        <span class="cb-kit-button__subtitle">{{ data.subtitle }}</span>
    {% endif %}
</a>
```

The view template receives the block's full `data` array, so `data.subtitle` is available. Start from the kit's shipped template (`packages/content-blocks-kit/templates/block/button/view.html.twig`) and add your field, keeping the existing `cb-kit-*` classes.

## Alternatives

::: details Service decoration (button-only, no config toggle)
Symfony service **decoration** of `ContentBlocks\Kit\Block\ButtonBlock` also works, since blocks are container services. It avoids the `enabled: false` toggle but is fiddlier — you must ensure the decorating service still carries the `content_blocks.block_type` tag so the registry picks up your version rather than the inner original. The subclass + disable approach above sidesteps that entirely, which is why it's the recommended one.
:::

::: details A field on *every* block (FormTypeExtension on BlockFormType)
Every block's edit form is built on one shared type, `ContentBlocks\Form\Type\BlockFormType` (there is no per-block form type). A `FormTypeExtension` targeting it therefore fires for **all** blocks — useful for a cross-cutting field, but it **cannot be scoped to `button` via `getExtendedTypes()`**. To limit it to one type, guard at runtime on the injected block instance:

```php
public function buildForm(FormBuilderInterface $builder, array $options): void
{
    if ($options['block_type'] instanceof \ContentBlocks\Kit\Block\ButtonBlock) {
        $builder->add('subtitle', TextType::class, ['required' => false]);
    }
}
```
:::

## Related

Need to add a CSS class or attribute to a block's outer element based on its data (rather than a form field)? Implement `ContentBlocks\Block\BlockDecoratorInterface` (the block-level mirror of the [section decorator](./add-section-field.md#_2-render-the-value)) — tag `content_blocks.block_decorator`, autoconfigured, returns a `BlockDecoration` of classes / attributes / inline styles.
