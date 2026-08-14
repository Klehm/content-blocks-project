---
title: Rich text block
---

# `rich_text` — Rich text

> WYSIWYG rich text, on TinyMCE or CKEditor.

A full WYSIWYG editor for formatted copy: bold/italic, links, lists, headings, colors and images. Which editor mounts it is the host's choice — **TinyMCE** (default) or **CKEditor 5** — set once in configuration. Whichever runs, the block stores the same `{ content: "<html>" }`, so switching editors is a config change and never a data migration. The color swatches are wired to the project palette (`cb_color_palette()`), so rich text stays on-brand under either.

## When to use

Long-form content, marketing copy, anything needing inline formatting or links.

## Configuration

Configure under `content_blocks_kit.blocks.rich_text` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Options

Block-level knobs, set under `options:`.

| Option | Default |
| --- | --- |
| `editor` | `tinymce` |
| `cdn` | `true` |
| `script_url` | `null` |
| `style_url` | `null` |
| `cdn_url` | `null` |
| `cdn_style_url` | `null` |
| `uploads` | `true` |
| `config` | `[]` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `content` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        rich_text:
            options: { editor: tinymce }
            defaults: { content: '' }
```

## Front-end

Rendered markup: `.cb-kit-rich-text` (the stored HTML is rendered as-is). Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

::: tip Requires a Stimulus controller
Enable `cb-tinymce` / `cb-ckeditor` under `@klehm/content-blocks-kit` in your host `assets/controllers.json` (whichever you select — both, if you are unsure).
:::

## Choosing an editor

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        rich_text:
            options:
                editor: ckeditor      # tinymce (default) | ckeditor
```

Both editors are wired the same way, so everything below applies to either.

## Where the editor is loaded from

By default the kit loads the editor from a CDN, so the block works on a fresh
install with nothing to build. Two other paths are supported, and neither adds
a dependency to the kit:

```yaml
content_blocks_kit:
    blocks:
        rich_text:
            options:
                # 1. Self-host the same build — for a strict CSP, or an admin
                #    with no outbound internet access. `asset:` runs the path
                #    through your asset packages, which is what a versioned
                #    filename needs (see below).
                script_url: 'asset:vendor/ckeditor5.umd.js'
                style_url: 'asset:vendor/ckeditor5.css'   # CKEditor only

                # 2. Bundle it yourself: the kit then loads nothing and expects
                #    the editor's global (window.tinymce / window.CKEDITOR) to
                #    be there when the sidebar opens.
                cdn: false
```

`cdn_url` and `cdn_style_url` are the names these two replaced and still work.
They read as "another CDN", which is not what self-hosting is.

With `cdn: false` it is on the host to `npm install` the editor and import it
in the entry that serves the admin — for example
`import 'tinymce/tinymce.min.js'` (or CKEditor's UMD build), so that the
global is defined. If it is missing, the block logs a precise message to the
console and leaves a plain textarea holding the HTML: the content is always
editable and never lost, whatever happens to the editor.

::: tip Pinned versions
The default CDN URLs point at TinyMCE 7 and CKEditor 5.48. CKEditor's editor
factory changed in 48; the controller reads `window.CKEDITOR_VERSION` and calls
the older signature for an older self-hosted build.
:::

## Images

Image upload is wired by default and goes through the builder's own endpoint —
the same `/_content-blocks/upload`, CSRF token, size cap and MIME allow-list
the `image` block uses, so a picture pasted into rich text lands in the same
storage as every other asset. It follows that uploads need
[file storage](../../guide/host-services.md#file-storage) configured; without
it the endpoint refuses and the editor reports the error.

Turn it off with `options: { uploads: false }` — the image button disappears
and pasted images are no longer sent anywhere.

## Configuring the editor itself

`options.config` is merged over the kit's init config in the browser, so
anything the editor accepts can be set without forking the block:

```yaml
content_blocks_kit:
    blocks:
        rich_text:
            options:
                config:
                    height: 500
                    toolbar: 'bold italic | link'    # TinyMCE spelling
```

Objects merge key by key; arrays and scalars replace. A toolbar you spell out
is a replacement, never an append.

Two things the merge deliberately protects, because losing them silently would
cost data rather than looks: the write-back that keeps autosave in sync (a
`setup` callback of your own runs *after* it rather than replacing it), and
the upload adapter, which survives a replaced plugin list — use
`uploads: false` if you mean to drop it.

### Naming a versioned asset

A digested filename — `/assets/styles/wysiwyg-8f3a2c.css` — cannot be written
by hand in a static YAML file, and `asset()` only exists in Twig. Prefix the
path with `asset:` and the kit resolves it through your asset packages
(AssetMapper, Encore, any package you configured), at any depth in the config:

```yaml
options:
    config:
        # The usual case: the editing surface should look like the page.
        content_css: 'asset:styles/wysiwyg.css'
```

The list form works too (`['asset:a.css', 'https://cdn.example/b.css']`), and a
value without the prefix is left exactly as written. If no asset packages are
configured, an `asset:` value raises an explicit error rather than emitting a
URL that would 404 inside the editor.

::: warning Needs symfony/asset
Resolution goes through `assets.packages`, so `symfony/asset` must be installed
and `framework.assets` enabled. AssetMapper does **not** pull it in on its own —
an app that only ever uses `importmap()` can be missing it and not know.
:::

### Anything JSON cannot carry

Callbacks, custom buttons, plugin instances, a stylesheet list only your
bundler knows: those never travel through YAML. Listen for
**`cb-rich-text:configure`** instead — it fires on the field wrapper, bubbling,
once the config is merged and before the editor is created. `detail.config` is
the live object:

```js
// your admin entry — no controller to fork, no adapter to write
document.addEventListener('cb-rich-text:configure', (event) => {
    if (event.detail.editor !== 'tinymce') return;

    event.detail.config.content_css = window.MY_EDITOR_CSS;   // hashes included
    event.detail.config.setup = (editor) => {
        editor.ui.registry.addButton('twocolumns', { text: '2 Cols', onAction: () => { /* … */ } });
    };
});
```

The two protections above still hold after the event: your `setup` runs after
the autosave write-back, and the upload adapter is appended after your plugin
list.

## Adding a third editor

Neither editor is special. A host registers Quill, Trix or an in-house editor
by implementing `ContentBlocks\Kit\RichText\RichTextEditorInterface` —
extending `AbstractRichTextEditor` gives the shared option handling for free —
and shipping a Stimulus controller with the name it returns. The service is
auto-tagged, so it becomes selectable as `options.editor: <name>` with no
further wiring, and nothing in the kit changes.

## Notes

- The editor JavaScript lives in the **sidebar**, never in the preview — so the block opts into preview hot-reload.
- Color swatches read `content_blocks.palette` via `cb_color_palette()`, under both editors.
- The stored HTML is rendered with `|raw`: like `html_raw`, the block trusts whoever may edit it. Unlike `html_raw` it stays enabled by default, because a toolbar — not a free HTML field — is what produces the markup.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
