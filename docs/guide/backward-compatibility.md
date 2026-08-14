# Backward compatibility

ContentBlocks follows [semantic versioning](https://semver.org/). From `1.0.0`, everything on this page is stable: it will not break in a `1.x` release. Anything **not** on this page is internal and may change in any minor release, without a deprecation cycle.

That second half is the part worth reading. In PHP almost everything is reachable — a controller can be instantiated, a trait can be used, a private-looking service can be aliased. Reachable is not the same as supported, and a package that promises everything it happens to expose can never fix anything. Where the line falls is marked in the code itself: **anything tagged `@internal` is outside the promise.**

## What is covered

### PHP

- **The 30 core interfaces**, plus the kit's `RichTextEditorInterface` and `IconProviderInterface`, and i18n's `TranslationProviderInterface` and `RenderLocaleResolverInterface`. These are the extension surface — implement them, alias them, decorate them.
- **`AbstractBlockType`, `AbstractKitBlock`, `AbstractRichTextEditor`** and their documented extension points.
- **The 17 kit block classes, as subclassable** — see [extending a kit block](../kit/#extending-a-kit-block).
- **The `#[AsContentBlock]` attribute.**
- **Value objects and enums** — `RenderContext`, `PublishContext`, `RenderMode`, `ResolvedImage`, `BlockPreviewHint`, `FieldStatus`, `ImportResult`, `InstantiationResult`, `SectionTemplateSnapshot`. The last three are frozen as things you **read**; their constructors are `@internal` so the package can add fields to them.
- **The entities** — `ContentArea`, `Section`, `Column`, `Block`, `SectionTemplate`, `BlockTranslation` — and their public accessors.

### Configuration

Every key of the three semantic config trees (`content_blocks`, `content_blocks_kit`, `content_blocks_i18n`) **and their default values**. A default is as frozen as a signature: changing one silently changes behaviour for every host that never set it.

### HTTP and console

Route **names**, their methods, payload shapes and CSRF requirement. Mount *paths* belong to the host — the i18n package ships `config/routes/bare.php` precisely so a host can mount its routes wherever its firewall covers — so the names are the contract, not the URLs.

The four console commands, their names and their options: `content-blocks:backfill-collection-ids`, `content-blocks-kit:blocks`, `content-blocks:i18n:status`, `content-blocks:i18n:translate`.

### Twig

The ten functions — `cb_render_content_area`, `cb_preview_url`, `cb_color_palette`, `cb_image`, `cb_embed_url`, `cb_kit_icon`, `cb_kit_token`, `cb_i18n_workbench_url`, `cb_i18n_locales`, `cb_i18n_progress`.

Every shipped template path, since overriding one under `templates/bundles/` is a supported integration. Their *contents* are not frozen — a template may be restructured — but the path will resolve and the block names a host overrides will keep working.

### Front-end

- The 15 **Stimulus controller names**, which hosts write into `assets/controllers.json`.
- All 90 **`--cb-*` CSS custom properties** — the chrome tokens and form alias layer in [Styling](./styling#theming-the-builder-chrome), the kit's seven content tokens in the [Block Kit](../kit/#the-kits-own-tokens), the workbench's fifteen in [Translation](./translation#theming-the-workbench).
- **Four `cb:*` events**: `cb:ready`, `cb:block:saved`, `cb:section:saved`, `cb:builder:action`.

The other 33 `cb:*` events are internal choreography between the preview overlay, the iframe and the builder shell — the `…-requested`, `…:apply`, `…:patch` and `…:desync` families. They are how the builder talks to itself, and they change as it changes.

### Storage

The six table names and their columns: `cb_content_area`, `cb_section`, `cb_column`, `cb_block`, `cb_section_template`, `cb_block_translation`. Hosts write migrations against these.

Three conventions in stored data, which are contracts even though they are not code: the **`cb_translatable`** field tag, the **`_id`** key on collection entries, and the reserved **`_` prefix** in `Block.data`.

### Behaviour

Some defaults are load-bearing enough to be API:

- `AccessCheckerInterface` defaults to `DenyAllAccessChecker`, and `ContentAreaUrlResolverInterface` to a resolver that throws. Secure by default, and both stay that way.
- The kit's `html_raw` block ships disabled.
- `ContentAreaType::buildView()` writes nothing to the database on a GET.

## What is not covered

- The 14 HTTP controllers and `CsrfProtectedTrait`. The routes are the contract; the classes behind them are not.
- `ContentBlocks\DependencyInjection\` and the block-type compiler pass.
- `BlockComponent` — a Live Component driven by the builder's own templates.
- `BlockTranslationRepository`.
- The constructors of `ImportResult`, `InstantiationResult` and `SectionTemplateSnapshot`.
- Every `cb:*` event outside the four above.
- Anything else carrying `@internal`.

## How changes are made

Additive changes — a new interface, a new config key, a new optional constructor argument on a shipped implementation — land in minor releases.

A breaking change to anything on this page waits for the next major. Where a change is unavoidable within `1.x`, the old path is kept working and marked `@deprecated` with the version that will remove it, and the CHANGELOG says so.

Two consequences worth spelling out, because they are the ones that catch hosts:

- **Adding a parameter to a published interface method is a breaking change**, even an optional one — an existing implementor stops satisfying the interface. This is why `BlockRendererInterface` takes a `RenderContext` and `ContentAreaPublisherInterface` a `PublishContext`, rather than growing parameter lists: a context object gains fields without touching the signature. New seams follow the same shape, and both of those were widened *before* 1.0 precisely because they had known inputs still to come.
- **Changing a default is a breaking change.** It reaches every host that never set the value, which is usually most of them.

## See also

- [Upgrade guide (beta → 1.0)](./upgrade)
- [Content versioning](./content-versioning) — the *other* compatibility promise, about the shape of stored block data rather than the code
