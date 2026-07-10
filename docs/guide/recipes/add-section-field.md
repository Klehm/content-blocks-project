---
title: Add a section field
---

# Recipe: add a field to the section settings

**Goal:** add a custom field to the section settings sidebar — say an **anchor id** so an editor can give a section an HTML `id` to link to — and render it onto the `<section>` element.

This takes two pieces, and **no forking**:

1. A `FormTypeExtension` that adds the field to the section form.
2. A `SectionDecoratorInterface` that renders the stored value into markup.

## 1. Add the field to the form

The section sidebar form is `ContentBlocks\Form\Type\SectionSettingsType`. Target it with a stock Symfony type extension — extensions are matched by **class name**, so this reaches the section form without subclassing it:

```php
// src/ContentBlocks/SectionAnchorExtension.php
namespace App\ContentBlocks;

use ContentBlocks\Form\Type\SectionSettingsType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class SectionAnchorExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [SectionSettingsType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('anchorId', TextType::class, [
            'required' => false,
            'label' => 'Anchor id',
            'row_attr' => ['class' => 'cb-field'],
        ]);
    }
}
```

Type extensions are autoconfigured — with the standard `App\` service definition (`autoconfigure: true`) there is **nothing to tag**.

::: tip Styling tab vs General tab
Extending `SectionSettingsType` lands your field in the section's **General** settings. If instead you want it inside the **Styling** group (behind the "Customize styling" switch), extend `ContentBlocks\Form\Type\Styling\StylingType` — same pattern, different target. See [Styling → Extending the styling sub-form](../styling.md#extending-the-styling-sub-form).
:::

### Where the value goes

`SectionSettingsType` works on a plain array (`data_class: null`), so your field's value becomes a **top-level key** in the section's settings: `$settings['anchorId']`. On save it is written to the section's `draft_settings` JSON column and promoted to `published_settings` on publish.

::: warning Empty values are pruned
The sidebar controller runs a `normalize()` pass that **recursively drops empty leaves** — `null`, `''`, `false`, and `[]` are removed before persistence (but `0` is kept). So a blank anchor field simply doesn't appear in `$settings` rather than storing `''`. Always read it defensively: `$settings['anchorId'] ?? null`. A field whose only meaningful value is `false`/empty won't persist — encode it as a non-empty value if you need to distinguish "explicitly off".
:::

## 2. Render the value

Implement `SectionDecoratorInterface`. It receives the effective settings and returns a `SectionDecoration` describing what to add to the `<section>` — classes, attributes, and/or inline styles:

```php
// src/ContentBlocks/SectionAnchorDecorator.php
namespace App\ContentBlocks;

use ContentBlocks\Entity\Section;
use ContentBlocks\Section\SectionDecoratorInterface;
use ContentBlocks\Section\SectionDecoration;

final class SectionAnchorDecorator implements SectionDecoratorInterface
{
    public function decorate(array $settings, Section $section): SectionDecoration
    {
        $anchor = $settings['anchorId'] ?? null;
        if (!$anchor) {
            return new SectionDecoration(); // add nothing
        }

        return new SectionDecoration(attributes: ['id' => (string) $anchor]);
    }
}
```

`SectionDecoratorInterface` is autoconfigured (tag `content_blocks.section_decorator`) — again, no manual wiring. All decorators run in service order and their output is **merged**, so yours stacks on top of the built-in one; the merged classes/attributes/inline style are written onto the outer `<section>` at render time.

::: tip The three levers of a decoration
`new SectionDecoration(classes: [...], attributes: [...], inlineStyles: [...])` — add CSS classes, HTML attributes, or CSS declarations respectively. For a value that maps to CSS, prefer `inlineStyles`; for structural/semantic output like an `id`, use `attributes`.
:::

## That's it

Reload the builder: the section sidebar now shows the **Anchor id** field, and saving it emits `<section id="…">` on the front page. The same two-step pattern (form extension + decorator) covers any custom section setting.
