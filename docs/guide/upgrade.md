---
title: Upgrade guide (beta → 1.0)
---

# Upgrade guide: `0.1.0-beta.x` → `1.0.0`

::: warning `1.0.0` is not released yet
Release candidates are tagged (`composer require klehm/content-blocks:^1.0@RC`);
the stable `1.0.0` is not. Everything below applies to the candidates, and the
per-candidate detail lives in each package's CHANGELOG.
:::

The `1.0.0` release freezes the public surface — `Block.data` JSON keys, config
YAML keys, and the styling `settings` shape become a stable contract. Converging
onto it from the beta line means a few **breaking changes**, grouped below by the
kind of action they need:

1. **Data migrations** — stored JSON is rewritten (run a Doctrine migration).
2. **Config renames** — edit your `content_blocks*.yaml` by hand (no data to migrate).
3. **Template overrides** — if you copied a kit block template, update the data keys it reads.

Everything else in `1.0.0` is **additive** and needs no action (see [Additive](#additive-no-action-needed)).

::: tip Order of operations
Do the config + template edits first (they're quick and cause build/render errors
if missed), then run the data migrations. Migrations are **reversible** (`down()`
restores the beta shape), so you can roll back if needed.
:::

---

## 1. Data migrations

Two reference Doctrine migrations rewrite existing rows. They ship in both
sandboxes — copy them into your host app's migrations directory and **adjust the
namespace** to match yours (e.g. `DoctrineMigrations`). Both decode → transform →
re-encode the JSON in PHP (not SQL JSON functions), so nested collection items are
handled the same as top-level keys, and both are safe to re-run.

### 1a. Kit `Block.data` key unification — `Version20260715120000`

The same concept was stored under different keys across kit blocks. `1.0.0`
reconciles them so the persisted schema is coherent:

| Block | Old key | New key |
|-------|---------|---------|
| `image` | `data.link` | `data.url` |
| `button` | `data.href` | `data.url` |
| `alert` | `data.message` | `data.content` |
| `tabs` | `data.tabs` (collection) | `data.items` |
| `gallery` | item `link` | item `url` |
| `card` | item `buttonUrl` | item `url` |
| `card` | item `buttonLabel` | item `buttonText` |
| `table` | column `align`: `left` / `right` | `start` / `end` (`center` unchanged) |

**Blessed exceptions (not renamed):** the `title` block keeps `text` for its
heading (composite blocks use `title` for a *sub-heading* — a different role);
`icon.size` stays an integer (px); `src` / `fit` keep their conventional HTML/CSS
spellings.

### 1b. Styling viewport keys `d`/`t`/`m` → `desktop`/`tablet`/`mobile` — `Version20260715130000`

The responsive styling sub-tree (`styling.padding` / `margin` / `gap`) stored
viewport keys tersely. `1.0.0` spells them out in stored JSON:

- `cb_section.draft_settings` / `published_settings`
- `cb_block.draft_data` / `published_data` (blocks: `padding` / `margin`)

The **emitted CSS custom-property names stay terse** (`--cb-s-pad-d-t`, `--cb-gap-d`) —
the decorators map long→short — so `styling.css` and any host CSS override are
**unaffected**. Only the stored data changes.

### 1c. Already need `cb_content_area.updated_at`? — `Version20260518120000`

If you're upgrading from *before* beta.6, the "Insert content" replace flow relies
on `cb_content_area.updated_at` (auto-touched by `ContentAreaTouchListener`). Add
that column too if your schema predates it — reference migration
`Version20260518120000` in the sandboxes.

### 1d. Content-version columns — `Version20260729120000`

Adds `cb_content_area.content_version` and `cb_section_template.content_version`,
both nullable and left `NULL`. They carry the host-owned
`content_blocks.content_version` (default `1`), stamped as content is written so
that *your* later migrations can target what predates a change of your own
making. Read [Content versioning](./content-versioning) before you rely on the
column — in particular, the area stamp means *"last written under version N"*,
not *"conforms to version N"*, and `NULL` means "predates versioning", which is
not the same as `0`.

### 1e. The published render becomes immutable — `Version20260831120000` <Badge type="danger" text="do not skip" />

Public pages now show the **last published state and nothing else**, so that no
action in the builder can change a live page before Publish. Two consequences
need a migration.

**`cb_block.published_column_id` (new, nullable).** Dragging a block into
another column writes `column_id` immediately — that FK is what the builder, the
preview and Doctrine's cascades navigate, so it is the *draft* location. This
column is the note the block leaves behind saying where the public page should
keep showing it until Publish. `NULL` everywhere means "not moved", which is
what every existing row already is.

**A backfill of `cb_section.published_at` / `cb_column.published_at`.** These
columns were introduced without one, so rows that predate them carry `NULL`
while being very much live — and a section without a `published_at` is now read
as a draft addition and left off the public page. Left alone, those rows would
**disappear from your live site the moment you deploy**. The migration stamps
exactly the rows the old renderer put on the public page (every non-deleted
section and column), so the live page after the upgrade is the live page before
it, unchanged.

::: warning Drafts that are open when you deploy
Under the old behaviour an unpublished section was *already* on the live page,
so the backfill stamps it as published too — that is what "freeze the current
public page" means. If you would rather those additions stay pending, ask
editors to Publish or Discard before you deploy.
:::

Nothing else is required: the behaviour change is in the renderer, and no host
code, template or config refers to it.

### 1f. The builder's undo stack — `Version20260910120000`

Adds one table, `cb_action_log`, holding the `Ctrl/Cmd-Z` history per content
area and per (hashed) HTTP session. It touches no existing table, so it is safe
on a live site. Without it the builder still works, and undo reports itself
unavailable. Rows are short-lived: Publish and Discard empty an area's stack.

The same release adds the **navigator**, a new Stimulus controller `cb-tree`.
Flex writes it to `assets/controllers.json` on `composer update`; if you manage
that file by hand, enable `@klehm/content-blocks` → `cb-tree` there, or the
topbar's Navigator button does nothing.

```bash
# after copying the migration(s) into your app and fixing the namespace
php bin/console doctrine:migrations:migrate
```

### 1f-bis. Column draft twins — `Version20260917120000` <Badge type="danger" text="do not skip" />

Columns can now be added, removed and named from the builder, which needs the
same draft/published split every other field has: `cb_column` gains
`published_preset`, `published_settings` and `draft_settings`. The migration
also backfills `published_preset` from `preset` for published columns, so what
the live page shows is pinned before anyone edits a column.

Without the columns, Doctrine cannot load a `Column` at all, so this one is not
optional. Copy it from the sandbox like the others.

### 1f-ter. Translated tab titles (i18n only) — `Version20260917130000`

If you use `klehm/content-blocks-i18n`, it now also translates the titles of
tabs sections, in a new table `cb_column_translation`. Create it with the
sandbox migration. Without it, the workbench and any translated page fail as
soon as they query the table.

### 1g. Join columns no longer follow your naming strategy

Join columns used to take their name from the host's Doctrine naming strategy.
Under the underscore strategy (Symfony Flex's default) that gave the documented
`content_area_id`; under Doctrine's own `DefaultNamingStrategy` it gave
`cb_section.contentArea_id`, and RC6's `cb_action_log` could not be created at
all. Every join column is now named explicitly, so the schema is the same on
every host.

**Using the underscore strategy? Nothing to do** — `doctrine:migrations:diff`
finds no change. On the default strategy it generates the rename of
`cb_section.contentArea_id`:

```sql
ALTER TABLE cb_section DROP FOREIGN KEY FK_97D63ADCE3B7EE2F;
DROP INDEX IDX_97D63ADCE3B7EE2F ON cb_section;
ALTER TABLE cb_section CHANGE contentArea_id content_area_id INT NOT NULL;
ALTER TABLE cb_section ADD CONSTRAINT FK_97D63ADC6207992F FOREIGN KEY (content_area_id) REFERENCES cb_content_area (id) ON DELETE CASCADE;
CREATE INDEX IDX_97D63ADC6207992F ON cb_section (content_area_id);
```

Check the generated migration shows that `CHANGE` line before you run it. It is
a rename, so every section keeps its area. A `DROP COLUMN` followed by an
`ADD` would mean Doctrine did not detect the rename. That only happens when the
same diff also adds or removes another `INT NOT NULL` column on `cb_section`, which makes
the match ambiguous; generate the rename on its own.

If RC6's `cb_action_log` was created through a local patch naming its column
`content_area_id`, the table already matches: drop the patch.

---

## 2. Config renames (edit YAML by hand)

Config is not migratable — update these keys in your host configuration.

### 2a. `content_blocks.styles` → `content_blocks.section_styles`

The section-style-presets key now matches the emitted parameter.

```yaml
# config/packages/content_blocks.yaml
content_blocks:
-   styles:
+   section_styles:
        - name: boxed
          label: 'Boxed'
          css_class: 'my-section--boxed'
```

### 2a-bis. `content_blocks.upload.dir` → `.directory`

The one abbreviated key in the tree, next to three spelled-out neighbours.

```yaml
content_blocks:
    upload:
-       dir: '%kernel.project_dir%/public/uploads/content-blocks'
+       directory: '%kernel.project_dir%/public/uploads/content-blocks'
        public_prefix: '/uploads/content-blocks'
```

A stale `dir:` stops the container build with `Unrecognized option "dir" under
"content_blocks.upload"`, so you cannot miss it — but note the failure mode if
you *removed* the key instead of renaming it: uploads silently fall back to
`NullFileStorage`, which throws only when an editor actually uploads.

### 2b. Preset `settings` is now a **typed** node

`section_styles[].settings` was a free-form `variableNode`; it is now a typed,
validated config node. Two consequences:

- **Viewport keys** inside a preset's `styling` must be spelled out
  (`desktop`/`tablet`/`mobile`, not `d`/`t`/`m`) — the data migration does *not*
  touch config.
- **Unknown keys / bad enum values now fail at container build** instead of
  silently persisting. If your build errors after upgrading, remove the offending
  key — it was never actually applied.

```yaml
content_blocks:
    section_styles:
        - name: boxed
          label: 'Boxed'
          settings:
            styling:
              padding:
-               d: { top: 40, bottom: 40 }
+               desktop: { top: 40, bottom: 40 }
```

### 2c. Kit block config keyed by a renamed field

If you used `content_blocks_kit.blocks.<type>.defaults` or `.choices` keyed by a
field that was renamed in §1a, update the key:

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        button:
            defaults:
-               href: 'https://example.com'
+               url: 'https://example.com'
        table:
            choices:
                align:
-                   [left, center, right]
+                   [start, center, end]
```

---

## 3. Template overrides

If you **override a kit block's view template**
(`templates/bundles/ContentBlocksKitBundle/block/<type>/view.html.twig`), update
the data keys it reads to match §1a — otherwise the field renders empty:

```twig
{# button/view.html.twig #}
- <a href="{{ data.href }}">{{ data.label }}</a>
+ <a href="{{ data.url }}">{{ data.label }}</a>

{# card item #}
- <a href="{{ item.buttonUrl }}">{{ item.buttonLabel }}</a>
+ <a href="{{ item.url }}">{{ item.buttonText }}</a>

{# alert #}
- {{ data.message }}
+ {{ data.content }}

{# tabs — collection wrapper #}
- {% for tab in data.tabs %}
+ {% for tab in data.items %}
```

The shipped kit templates already use the new keys — you only need this if you
forked one. (Translation *label* keys such as `…field.link` were intentionally
**not** renamed — they are identifiers, not the data contract.)

### 3a. Overridden builder or render templates in the core

Only if you copied one of these into `templates/bundles/ContentBlocksBundle/`:

- **`builder/shell.html.twig`** — add the attribute the builder reads its
  endpoint mount from, next to `data-cb-csrf-token`:

  ```twig
       data-cb-csrf-token="{{ csrf_token('content_blocks') }}"
  +    data-cb-api-base="{{ cb_api_base() }}"
  ```

  Without it the builder falls back to `/_content-blocks`, which is correct only
  as long as you keep the default mount ([Mounting the routes](./routing.md)).
- **`render/content_area.html.twig`** — keep a single `.cb-add-section-tray`
  inside `.cb-content-area`: adding a section inserts it in place before that
  tray, and falls back to a full preview reload without it. Add the
  `add_section` and `empty_cta` entries to `window.__cbOverlayLabels` too, or
  the tray label keeps its old wording when the area empties or fills.

### 3b. Overridden translation workbench

Only if you have a file at
`templates/bundles/ContentBlocksI18nBundle/workbench/workbench.html.twig`. Until
`1.0.0-RC9` that override was **never used**: the bundle registered its own
templates ahead of the override directory. It is used now, so check it still
matches the shipped page before upgrading — or, better, replace the copy with an
`{% extends '@!ContentBlocksI18n/workbench/workbench.html.twig' %}` that fills
only the empty blocks you need ([Adding to the workbench](./translation.md#adding-to-the-workbench)).

---

## 4. `ContentBlocks\Service\` is gone (update your `use` statements)

The six services that lived in the catch-all `ContentBlocks\Service\` namespace
moved next to the rest of their domain — every other extension point already sat
in one (`Rendering\`, `Security\`, `Storage\`, `Replace\`…). Nothing else changed
about them: same class names, same methods, same behaviour.

| Before | After |
|---|---|
| `ContentBlocks\Service\ContentAreaPublisher` | `ContentBlocks\Publishing\ContentAreaPublisher` |
| `ContentBlocks\Service\SectionCloner` | `ContentBlocks\Section\SectionCloner` |
| `ContentBlocks\Service\ContentAreaExporter` | `ContentBlocks\Transfer\ContentAreaExporter` |
| `ContentBlocks\Service\ContentAreaImporter` | `ContentBlocks\Transfer\ContentAreaImporter` |
| `ContentBlocks\Service\SectionTemplateSerializer` | `ContentBlocks\SectionTemplate\SectionTemplateSerializer` |
| `ContentBlocks\Service\SectionTemplateInstantiator` | `ContentBlocks\SectionTemplate\SectionTemplateInstantiator` |

The matching `…Interface` classes follow the same mapping. A find-and-replace of
`ContentBlocks\Service\` is enough — and if you only ever injected these by
type-hint, prefer switching to the interface while you are there:

```php
- use ContentBlocks\Service\SectionCloner;
+ use ContentBlocks\Section\SectionClonerInterface;

- public function __construct(private readonly SectionCloner $cloner) {}
+ public function __construct(private readonly SectionClonerInterface $cloner) {}
```

You will get a clear `Class "ContentBlocks\Service\…" not found` at container
build if you miss one — there is no silent-failure mode here.

---

## 5. Two return types changed (only if you call these services yourself)

The importer and the section-template serializer used to return bare
arrays/ints; they now return value objects, matching `InstantiationResult` which
already worked that way.

```php
- $count = $importer->import($area, $payload);
+ $result = $importer->import($area, $payload);
+ $count  = $result->sectionCount;

- $payload    = $serialized['payload'];
- $blockTypes = $serialized['blockTypes'];
+ $payload    = $snapshot->payload;
+ $blockTypes = $snapshot->blockTypes;
```

`ImportResult` also carries `skippedBlockCount`, `skippedBlockTypes` and
`unknownFields` — what the payload referenced that this installation could not
take in. `InstantiationResult` (section-template insert) now reports the same
three, under the same names.

Both restores are **optimistic**: everything usable comes in, the rest is
reported. "Compatible" is judged per **block**:

- a block whose **type is not registered here** is **skipped**. Importing it
  would leave an inert placeholder — no view template, no edit form — and
  nothing is lost, since the JSON file (or the stored template payload) remains
  the archive: install the block type and re-import.
- a **stored key** no registered type declares is **kept** and merely reported.
  The block itself is fine, and the key may be a field you are about to add.

The only content-level refusal left is a section template that had blocks and
kept none — there would be nothing to insert.

In the library picker, a template that is only *partly* usable stays clickable
and its tooltip says how many blocks will be skipped; a row is disabled only when
nothing would come in.

::: warning Section templates saved by an older payload structure
The section-template payload declares an envelope format, and it is now actually
checked. Templates saved by any released version carry the current one, so
nothing breaks today — but if a future release bumps it, the library picker will
grey out the old snapshots instead of inserting them blind. Plan a migration of
`cb_section_template.payload` when that happens.

Note this versions the payload *structure*, which the package owns. It says
nothing about the shape of your block data, which follows your block types.
:::

---

## 6. `BlockRendererInterface` takes a `RenderContext`

Only relevant if you **call the renderer directly** or **implement / decorate**
`BlockRendererInterface`. The three render methods took a bare `RenderMode`;
they now take a `RenderContext`, which carries the mode *and* an optional locale.

```php
- $renderer->render($area, RenderMode::PUBLIC);
+ $renderer->render($area, RenderContext::forPublic());

- $renderer->renderBlock($block, RenderMode::PREVIEW);
+ $renderer->renderBlock($block, RenderContext::forPreview());
```

`render($area)` with no second argument is unchanged — mode is still
auto-detected from the request. `resolveMode()` is untouched. A `RenderContext`
whose `mode` is `null` means "decide for me", so
`RenderContext::forLocale('fr')` pins the language while leaving preview
detection alone.

Implementors must update their signatures; the container will not boot otherwise,
so there is no silent failure mode here.

**Why now:** adding a parameter to a published interface method breaks every
implementor, so the render pipeline had to get room to grow inputs *before* the
1.0 freeze. Locale is the first passenger — see `TRANSLATION-SPIKE.md` in the
repository for the reasoning.

::: tip Changing rendered data no longer needs a renderer replacement
If you replaced `BlockRendererInterface` only to alter a block's data on the way
to its template, `BlockDataResolverInterface` is a much smaller surface to own.
See [Changing what a block renders](./rendering#changing-what-a-block-renders).
:::

---

## 7. Builder chrome restyled

The admin UI ships a new skin — cool blue-grey surfaces, a teal accent, 8px
corners, tinted fields. **No action needed**, and **content rendering is
untouched**: the preview iframe still draws your page from
`content_blocks.palette` and the block styling settings.

If you had overridden the builder's CSS by targeting its colors, the literals
are gone: every rule now reads `var(--cb-*)` from a token layer. Redeclare the
tokens instead — fewer rules, and it survives upgrades. See
[Theming the builder chrome](./styling#theming-the-builder-chrome).

---

## 8. Collection entries gained a stable `_id` — run the backfill

Entries of a collection field (the kit's `card`, `list`, `accordion`, `tabs`,
`gallery`, `breadcrumb`, `table`) now carry a `_id`. Until now an entry was
only a position in a list, so anything keyed per entry pointed at the wrong one
after a reorder, a duplicate or a delete.

New and re-saved content gets ids automatically. Content written before the
upgrade needs one pass:

```bash
php bin/console content-blocks:backfill-collection-ids --dry-run   # report only
php bin/console content-blocks:backfill-collection-ids
```

It is idempotent, so a partial or repeated run is harmless. Blocks whose type is
no longer registered are skipped and reported — install the block type and re-run
if you still need them.

::: warning If your own block types declare a field starting with `_`
The `_` prefix is now reserved by the package at every level of `Block.data`.
Rename such a field before upgrading.
:::

It is a command rather than a Doctrine migration on purpose: which JSON keys
hold a *collection* is knowledge that lives in the block types' forms, and SQL
cannot ask them. A migration would have to hard-code a list of block-type/field
pairs, which would be wrong the moment you ship your own collection block.

---

## Additive (no action needed)

These landed in `1.0.0` but are backward-compatible — nothing to change:

- **The route mount is yours** — `/_content-blocks` is only the default.
  `config/routes/editor.php` and `config/routes/public.php` carry the routes
  without a prefix, so the builder can live under `/admin` and your firewall
  pattern covers it as is. Route names are unchanged; keeping
  `config/routes.php` changes nothing. See [Mounting the routes](./routing.md).
- **Adding or deleting a section no longer reloads the preview** — nothing to
  wire, unless you forked the templates listed in §3a.
- **"View page" in the builder topbar** — opens the published page, from the
  URL your `ContentAreaUrlResolverInterface` already returns. On by default;
  `enable_public_link: false` on `ContentAreaType` hides it. See
  [Toggling topbar features](./host-services.md#toggling-topbar-features-insert-content-import-export-view-page).
- **`content_blocks.section.initial_settings`** — settings every section added
  from the builder starts with (padding, *Customize styling*, a preset…). If you
  wrote a listener or a defaults provider to get there, it can go; don't keep a
  defaults provider declaring the same values. See
  [Initial settings of a new section](./host-services.md#initial-settings-of-a-new-section).
- **Empty Twig blocks** in the builder shell and the translation workbench, for
  adding markup without copying a template.
- **Two i18n seams** — `WorkbenchBackUrlResolverInterface` (where the
  workbench's back arrow leads) and `LocalizedPageUrlResolverInterface` (links
  to the published page in each language). Both default to today's behaviour.
- **The translation workbench's preview is resizable** — drag its left edge or
  use the arrow keys; nothing to wire.
- **Tone classes on coloured backgrounds** — a section or block with a
  background colour now also carries `cb-section--bg-dark|light` or
  `cb-block--bg-dark|light`. No styles come with them. Only check your CSS if
  it already used those class names. See
  [Dark and light backgrounds](./styling.md#dark-and-light-backgrounds).
- **A kit image set to *Full width* now fills its column** (`width: 100%`)
  instead of stopping at the file's own width. An image narrower than its
  column is scaled up: pick another size for those. The new *Aspect ratio*
  field defaults to the original proportions.

- **`BlockDataResolverInterface`** — an autoconfigured pipeline for changing what
  a block renders (translation, token expansion, computed values) without
  touching the renderer. With none registered, output is unchanged.
- **`cb_translatable` form option** — blocks declare which fields hold
  language-dependent values, read back through `TranslatableFieldsInterface`. It
  carries no behaviour: content translation lives in a satellite package, and
  this is the convention it will read. The kit already tags 29 fields.
- **The `_` prefix is reserved in `Block.data`** — at every level, including
  collection entries. Only an issue if one of your own block types already
  declares a field whose name starts with `_`; rename it if so.

- **Per-block form extension API** (`BlockFormExtensionInterface` +
  `#[AsBlockFormExtension]`) — a new, cleaner way to add fields to a block's edit
  form. Existing subclass-based customizations keep working. See
  [Add a field to a block](./recipes/add-block-field).
- **`BlockRendererInterface`** — the central renderer is now an interface + alias,
  so you can decorate/replace it. The concrete `BlockRenderer` service id still
  resolves.
- **Interfaces for the six other core services** — publisher, section cloner,
  exporter, importer, section-template serializer and instantiator each gained an
  interface aliased to the shipped class, so they can be decorated or replaced.
  Concrete service ids still resolve; the payload-format constants moved onto the
  interfaces but stay readable from the classes through inheritance. See
  *Replacing or decorating a core service* in the package README.
- **Transparent background default** — already shipped earlier in the beta line, but
  worth re-checking on upgrade: `backgroundColor` defaults to `''` (transparent),
  not `#ffffff`. A section/block that persisted a literal `#ffffff` will render a
  real white background.

---

## Checklist

- [ ] Copy + re-namespace `Version20260715120000` (kit data keys),
      `Version20260715130000` (styling viewports), `Version20260729120000`
      (content-version columns), **`Version20260831120000`** (published-render
      immutability — skipping it hides already-live sections) and
      `Version20260910120000` (undo stack), **`Version20260917120000`** (column
      draft twins — pins published column presets) and, with the i18n package,
      `Version20260917130000` (tab titles); run `doctrine:migrations:migrate`.
- [ ] On Doctrine's default naming strategy only: generate the
      `cb_section.contentArea_id` rename with `doctrine:migrations:diff` and
      check it is a `CHANGE` (§1g).
- [ ] Check `assets/controllers.json` enables `cb-tree` (Flex does it for you).
- [ ] (Pre-beta.6 only) add `cb_content_area.updated_at` via `Version20260518120000`.
- [ ] Rename `content_blocks.styles` → `section_styles` and `upload.dir` → `upload.directory`.
- [ ] Spell out `d`/`t`/`m` → `desktop`/`tablet`/`mobile` in any preset `settings`.
- [ ] Rename kit `defaults`/`choices` config keyed by a renamed field.
- [ ] Update any forked kit block templates to the new data keys.
- [ ] Forked `builder/shell.html.twig`? Add `data-cb-api-base` (§3a).
- [ ] Find-and-replace `ContentBlocks\Service\` with the new namespaces (§4).
- [ ] Adjust any direct call to `import()` / `serialize()` to their value objects (§5).
- [ ] Swap `RenderMode` for `RenderContext` if you call or implement the renderer (§6).
- [ ] Run `content-blocks:backfill-collection-ids`; rename any own field starting with `_` (§8).
- [ ] Rebuild the container and clear the cache; verify pages render.
