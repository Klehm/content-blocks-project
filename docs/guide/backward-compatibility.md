# Backward compatibility

ContentBlocks follows [semantic versioning](https://semver.org/). From `1.0.0`, everything this page lists is stable: it will not break in a `1.x` release.

**This page is the whole promise.** Anything it does not list is internal, and may change in any minor release without a deprecation cycle. In PHP almost everything is reachable: a controller can be instantiated, a trait used, a service aliased. Reachable is not the same as supported, and a package that promised everything it happens to expose could never fix anything.

`@internal` in the code is a **reminder** of that rule, put where someone is most likely to reach for the wrong thing: a public setter on an entity, a class next to an interface you implement. It is not the rule itself. A class carrying no marker is internal all the same if it is not listed here.

## What is covered

### PHP — interfaces

These are the extension surface: implement them, alias them, decorate them. Their method signatures are frozen, and so is the meaning of what they return.

**Core** (`klehm/content-blocks`), 38 interfaces:

| Area | Interfaces |
|---|---|
| Access, preview, pickers | `AccessCheckerInterface`, `ContentAreaUrlResolverInterface`, `ContentAreaProviderInterface`, `SectionTemplateManagerInterface`, `AssetReportViewerInterface` |
| Blocks | `BlockTypeInterface`, `BlockPreviewHintInterface`, `BlockDataDefaultsProviderInterface`, `BlockDecoratorInterface`, `BlockFormExtensionInterface`, `TranslatableFieldsInterface` |
| Sections and columns | `SectionDecoratorInterface`, `SectionSettingsDefaultsProviderInterface`, `SectionStyleProviderInterface`, `SectionClonerInterface`, `BlockCloneObserverInterface`, `ColumnCloneObserverInterface` |
| Rendering | `BlockRendererInterface`, `BlockDataResolverInterface`, `ColumnSettingsResolverInterface`, `ImageUrlResolverInterface` |
| Publishing | `ContentAreaPublisherInterface` |
| Builder UI | `BuilderActionProviderInterface`, `BuilderShellExtensionInterface`, `UiIconProviderInterface`, `ColorPaletteProviderInterface` |
| Storage and assets | `FileStorageInterface`, `AssetInventoryInterface`, `AssetResolverInterface`, `AssetReferenceProviderInterface` |
| Clipboard, templates, transfer | `BlockSnapshotSerializerInterface`, `SectionTemplateSerializerInterface`, `SectionTemplateInstantiatorInterface`, `ContentAreaExporterInterface`, `ContentAreaImporterInterface`, `ContentAreaTransferExtensionInterface` |
| Versioning | `ContentVersionUpgraderInterface`, `EnvelopeUpgraderInterface` |

**Kit**: `RichTextEditorInterface`, `IconProviderInterface`.

**i18n**: `TranslationProviderInterface`, `RenderLocaleResolverInterface`, `WorkbenchBackUrlResolverInterface`, `LocalizedPageUrlResolverInterface`.

### PHP — classes you extend, construct or reference

- **Base classes and their documented extension points.** `AbstractBlockType`; the kit's `AbstractKitBlock` (`defaultOptions()`, `option()`, `choiceFields()`, `choices()`, `choiceConstraint()`, `defaults()`, `describe()`, and `getDefaultData()` staying `final`); `AbstractRichTextEditor`.
- **The 19 kit block classes, as subclassable.** Their `protected` methods are covered. See [extending a kit block](../kit/#extending-a-kit-block).
- **Attributes**: `#[AsContentBlock]`, `#[AsBlockFormExtension]`.
- **Form types**:
  - `ContentAreaType` and its options (`enable_replace`, `enable_import_export`, `enable_public_link`, `topbar_actions`).
  - `PaletteColorType`, `ImageUploadType`, `VideoUploadType`, as fields for your own blocks.
  - `BlockFormType`, `SectionSettingsType` and `StylingType` as **targets of a form type extension**, with their `TAB_*` and `PANEL_*` constants. The fields they hold are not frozen: a field may move, but a key you add keeps working.
- **The sidebar form options.** `cb_group`, `cb_panel`, `cb_help_tooltip` and `cb_panels_exclusive` on every form type; `cb_icons`, `cb_icon_layout`, `cb_icon_columns` and `cb_icon_labels` on `ChoiceType`; `cb_open_entries` on `LiveCollectionType`. Also the **names of the shipped UI icons** they refer to ([Laying out sidebar fields](./sidebar-fields.md)): a name may gain a better drawing, but it is not renamed or removed.
- **Value objects your implementation builds or reads.** Their public properties and named constructors are frozen:
  - rendering: `RenderContext`, `RenderMode`, `ResolvedImage`, `BlockPreviewHint`, `BlockDecoration`, `SectionDecoration`
  - publishing: `PublishContext`
  - builder UI and styling: `BuilderAction`, `BuilderShellFragment`, `PaletteColor`, `SectionStyle`
  - storage: `StoredAsset`
  - translation: the kit's `RichTextEditorView`; i18n's `TranslationRequest`, `TranslationOutcome`, `TranslationJob` and `FieldStatus`
- **Values you read but do not build.** `ImportResult`, `InstantiationResult`, `SectionTemplateSnapshot`, and the transfer helpers `AssetTokenizer` and `AssetRewriter` handed to a `ContentAreaTransferExtensionInterface`. Their public reads are frozen; their constructors are `@internal`, so the package can add fields to them.
- **Services you inject.** `BlockTypeRegistry` (`get()`, `has()`, `all()`, `getChoices()`), and the shipped implementations named as defaults in the guides: `LocalFileStorage`, `PassthroughImageUrlResolver`, `DenyOnMismatchUpgrader`, `AllowAllAccessChecker` and `DenyAllAccessChecker`. They are covered as services to alias or decorate; their constructors are not.
- **Exceptions you throw or catch.** `ContentBlocksAccessDeniedException` (a 403), `IncompatibleContentVersionException`, `ImportRefusedException`, `UnsupportedTemplateFormatException`, `IncompatibleTemplateException`.
- **`ContentBlocks\Testing\CrossRequestStateScanner`**, for pointing the [worker-mode](./worker-mode.md) check at your own code.
- **The entities** and their public accessors: `ContentArea`, `Section`, `Column`, `Block`, `SectionTemplate`, and i18n's `BlockTranslation` and `ColumnTranslation`. The exception is the setters of **published state**, which carry `@internal`. `publish()` is the only writer of a published field, so code building content writes the draft and calls `publish()`.

### Configuration

Every key of the three semantic config trees (`content_blocks`, `content_blocks_kit`, `content_blocks_i18n`) **and their default values**. A default is as frozen as a signature: changing one silently changes behaviour for every host that never set it.

### HTTP

- **Route names**, for every route the packages ship. Hosts generate URLs with them and write firewalls around them. Mount *paths* belong to the host: the core ships `config/routes/editor.php` and `config/routes/public.php`, and the i18n package `config/routes/bare.php`, so that a host can mount the routes wherever its firewall covers ([Mounting the routes](./routing.md)).
- **Methods, payloads and CSRF requirement, for the routes a host calls itself:**

  | Route | What is frozen |
  |---|---|
  | `content_blocks_upload` | `POST` multipart: `file` and `area`, the `X-CSRF-Token` header; the JSON answer's `url` (and `error` on a refusal) |
  | `content_blocks_export` | `GET` on an area, answering the export archive; `?assets=0` |
  | `content_blocks_import` | `POST` of an export in one request, the single-request path kept for scripts |
  | `content_blocks_area_publish`, `content_blocks_area_discard` | `POST` with the CSRF header |
  | `content_blocks_asset_layout`, `content_blocks_asset_styling`, `content_blocks_asset_slider`, `content_blocks_kit_asset_css` | `GET`, the stylesheet or script a public page links; `?v=` with the content's version is cached for a year |
  | `content_blocks_asset_report` | `GET`, the read-only asset report page |
  | `content_blocks_i18n_workbench` | `GET`, the translation workbench page |

- **The export format**, `content-blocks/v1`: any 1.x release imports an export written by an earlier 1.x release.

Every other route is how the builder talks to its own server. Its **name** is stable, but its method, payload and response shape are internal.

### Console

The five commands, their names and their options: `content-blocks:assets:gc`, `content-blocks:backfill-collection-ids`, `content-blocks-kit:blocks`, `content-blocks:i18n:status`, `content-blocks:i18n:translate`.

For `content-blocks:assets:gc`, the *shape* of the safety design is part of the promise too: reporting is the default and `--force` is the opt-in. Inverting that would silently turn an existing habit into a deletion, so it will not be inverted.

### Twig

- **Functions**:
  - core: `cb_render_content_area`, `cb_preview_url`, `cb_public_url`, `cb_api_base`, `cb_color_palette`, `cb_color_tone`, `cb_color_is_dark`, `cb_css_color`, `cb_image`, `cb_ui_icon`, `cb_shell_fragments`
  - kit: `cb_embed_url`, `cb_kit_icon`, `cb_kit_token`, `cb_kit_stylesheet_url`
  - i18n: `cb_i18n_workbench_url`, `cb_i18n_locales`, `cb_i18n_progress`
- **Filters** (kit): `cb_kit_safe_url`, `cb_kit_rich_html`. A template override of a kit view keeps the same guards by using them.
- **Template paths.** Every shipped template path, since overriding one under `templates/bundles/` is a supported integration. Their *contents* are not frozen, and a template may be restructured, but the path will resolve.
- **Block names.** The block names a host overrides keep working, including the empty blocks shipped for host additions:
  - builder shell: `cb_shell_topbar_left_end`, `cb_shell_topbar_right_start`, `cb_shell_topbar_right_end`, `cb_shell_end`
  - workbench: `cb_wb_head`, `cb_wb_topbar_left_end`, `cb_wb_topbar_right_start`, `cb_wb_topbar_right_end`, `cb_wb_end`
- **A block view's variables.** A view receives `data` and `block_id`; later versions may pass more, never less.

### Front-end

- **The 16 Stimulus controller names**, which hosts write into `assets/controllers.json`:
  - core (13): `cb-builder-launcher`, `cb-builder`, `cb-autosave`, `cb-section-settings-form`, `cb-block-styling-form`, `cb-spacing-link`, `cb-viewport-tabs`, `cb-range`, `cb-tabs`, `cb-collection-sort`, `cb-condition`, `cb-file-upload`, `cb-tree`
  - kit (3): `cb-tinymce`, `cb-ckeditor`, `cb-gallery`

  Their targets, values and actions are not frozen, except `data-cb-condition` (see [Host services](./host-services.md)).
- **Every documented `--cb-*` CSS custom property** (CI fails on an undocumented one): the chrome tokens and form alias layer in [Styling](./styling#theming-the-builder-chrome), the kit's seven content tokens in the [Block Kit](../kit/#the-kits-own-tokens), and the workbench's fifteen in [Translation](./translation#theming-the-workbench).
- **Seven `cb:*` DOM events**, with the `detail` fields listed in [Builder events](./host-services.md#builder-events). All of them bubble.

  | Event | Direction | Dispatched on | `detail` |
  |---|---|---|---|
  | `cb:ready` | out | the builder element, once the preview is interactive | `areaId` |
  | `cb:block:saved` | out | the builder, after a block's form saved | `blockId` |
  | `cb:section:saved` | out | the builder, after a section's settings saved | `sectionId` |
  | `cb:builder:action` | out | the builder, when a contributed action is clicked | `key`, `areaId`, `button` |
  | `cb:block:rendered` | out | **inside the preview iframe**, on a block's fresh node after an in-place refresh | `blockId` |
  | `cb:area:changed` | in | dispatched by you at the builder after changing the area server-side | `hasUnpublishedChanges` (optional) |
  | `cb:notify` | in | dispatched by you at the builder, to speak in its snackbar | `message`, `link` (optional) |

  A later version may add fields to a `detail`, but will not remove or rename one.

The other `cb:*` events are internal choreography between the preview overlay, the iframe and the builder shell: the `…-requested`, `…:apply`, `…:patch` and `…:desync` families. They are how the builder talks to itself, and they change as it changes. The `postMessage` traffic between the iframe and the builder is internal too.

### Storage

- **The eight table names and their columns**: `cb_content_area`, `cb_section`, `cb_column`, `cb_block`, `cb_section_template`, `cb_action_log`, and i18n's `cb_block_translation` and `cb_column_translation`. Hosts write migrations against these.
- **Conventions in stored data**, which are contracts even though they are not code:
  - the `cb_translatable` field tag
  - the `_id` key on collection entries
  - the reserved `_` prefix in `Block.data`
  - the names of the kit's icon set, since a stored `icon` block holds the name

### Behaviour

Some defaults are load-bearing enough to be API:

- **Secure by default.** `AccessCheckerInterface` defaults to `DenyAllAccessChecker`, `ContentAreaUrlResolverInterface` to a resolver that throws, `SectionTemplateManagerInterface` to one that denies, and `AssetReportViewerInterface` to a viewer that denies (the report route 404s rather than 403s). They stay that way.
- **No uploaded file is ever deleted as a side effect of a builder action**: not on block delete, not on publish, not on discard. Reclaiming storage is an explicit, separate act; see [Asset lifecycle](./asset-lifecycle.md).
- **Nothing the builder does changes the published page** until Publish.
- **The kit's `html_raw` block is registered only with `enabled: true`**, whatever else its config entry holds.
- **`ContentAreaType::buildView()` writes nothing** to the database on a GET.

## What is not covered, by name

Everything missing from the lists above is internal. These are named because they are the likeliest to be mistaken for API:

- **HTTP controllers and their helpers.** The controllers and `CsrfProtectedTrait`: the routes are the contract, not the classes behind them.
- **Wiring.** `ContentBlocks\DependencyInjection\`, the compiler passes, and the bundle classes' methods.
- **Builder internals.** `BlockComponent` (a Live Component driven by the builder's own templates) and everything under `History\`.
- **Collaborator services.** `BlockRenderer`, `ContentAreaPublisher`, `SectionCloner`, `ContentAreaExporter`, `ContentAreaImporter`, `SectionTemplateSerializer`, the `*Collection` and `*Registry` aggregators (except `BlockTypeRegistry`), the decorators and resolvers the core ships, and `BlockTranslationRepository`. Depend on their interface; an implementation's constructor can change in a minor release.
- **Builder-only Twig functions**: `cb_history_state`, `cb_section_layouts`, `cb_section_layout_rects`, `cb_asset_path`.
- **HTTP payloads** of the routes not in the table above, and every `cb:*` event not in the events table.
- **Anything carrying `@internal`.**

## How changes are made

Additive changes land in minor releases: a new interface, a new config key, a new optional constructor argument on a shipped implementation, a new field in an event's `detail` or a value object.

A breaking change to anything on this page waits for the next major. Where a change is unavoidable within `1.x`, the old path is kept working and marked `@deprecated` with the version that will remove it, and the CHANGELOG says so.

Two consequences worth spelling out, because they are the ones that catch hosts:

- **Adding a parameter to a published interface method is a breaking change**, even an optional one: an existing implementor stops satisfying the interface. This is why `BlockRendererInterface` takes a `RenderContext` and `ContentAreaPublisherInterface` a `PublishContext` rather than growing parameter lists: a context object gains fields without touching the signature. New seams follow the same shape.
- **Changing a default is a breaking change.** It reaches every host that never set the value, which is usually most of them.

## See also

- [Upgrade guide (beta → 1.0)](./upgrade)
- [Content versioning](./content-versioning): the *other* compatibility promise, about the shape of stored block data rather than the code
