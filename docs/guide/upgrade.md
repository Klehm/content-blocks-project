---
title: Upgrade guide (beta → 1.0)
---

# Upgrade guide: `0.1.0-beta.x` → `1.0.0`

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

```bash
# after copying the migration(s) into your app and fixing the namespace
php bin/console doctrine:migrations:migrate
```

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

## Additive (no action needed)

These landed in `1.0.0` but are backward-compatible — nothing to change:

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
      `Version20260715130000` (styling viewports) and `Version20260729120000`
      (content-version columns); run `doctrine:migrations:migrate`.
- [ ] (Pre-beta.6 only) add `cb_content_area.updated_at` via `Version20260518120000`.
- [ ] Rename `content_blocks.styles` → `section_styles` and `upload.dir` → `upload.directory`.
- [ ] Spell out `d`/`t`/`m` → `desktop`/`tablet`/`mobile` in any preset `settings`.
- [ ] Rename kit `defaults`/`choices` config keyed by a renamed field.
- [ ] Update any forked kit block templates to the new data keys.
- [ ] Find-and-replace `ContentBlocks\Service\` with the new namespaces (§4).
- [ ] Adjust any direct call to `import()` / `serialize()` to their value objects (§5).
- [ ] Rebuild the container and clear the cache; verify pages render.
