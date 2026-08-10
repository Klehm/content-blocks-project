// Hand-written prose for each kit block. The config surface (options / choices /
// defaults) is NOT here — it is generated from `content-blocks-kit:blocks --format=json`
// so it never drifts from the code. This file only holds what a human must write:
// what the block is, when to reach for it, and the notable runtime facts
// (front markup class, required Stimulus controller, gotchas).
//
// `order` drives the sidebar ordering. `controller` names a Stimulus controller
// the host must enable for the block to work. `disabledByDefault` is sourced from
// the JSON, not here.

export const blocksMeta = {
  title: {
    order: 1,
    tagline: 'Heading with a visual size decoupled from its semantic tag.',
    intro:
      'Renders a heading whose **visual size** (`size`) is independent of its **semantic tag** (`tag`). That split lets an editor place, say, an `<h2>`-looking title that is actually an `<h1>` for the document outline — or a large visual lead that is semantically a `<p>`. The text color is picked from the project color palette.',
    whenToUse: 'Section headings, page titles, visual leads — anywhere the heading level for SEO/accessibility should not dictate how big the text looks.',
    markup: '`.cb-kit-title` (the element tag is whatever `tag` resolves to).',
    notes: [
      'Both `size` and `tag` accept `h1`–`h6`; `tag` additionally accepts `span` and `p` for non-heading semantics.',
      'The `color` field draws from `content_blocks.palette` (empty = inherit).',
    ],
  },
  text: {
    order: 2,
    tagline: 'A plain paragraph of text with a palette-driven color.',
    intro:
      'A single block of plain paragraph text — no rich formatting, no HTML. For formatted copy (bold, links, lists) reach for [`rich_text`](./rich_text.md) instead. The text color is picked from the project palette.',
    whenToUse: 'Body copy, intros, captions — any place where you want plain, safe text without a WYSIWYG editor.',
    markup: '`.cb-kit-text`',
    notes: ['`color` draws from `content_blocks.palette` (empty = inherit).'],
  },
  rich_text: {
    order: 3,
    tagline: 'WYSIWYG rich text, on TinyMCE or CKEditor.',
    intro:
      'A full WYSIWYG editor for formatted copy: bold/italic, links, lists, headings, colors and images. Which editor mounts it is the host\'s choice — **TinyMCE** (default) or **CKEditor 5** — set once in configuration. Whichever runs, the block stores the same `{ content: "<html>" }`, so switching editors is a config change and never a data migration. The color swatches are wired to the project palette (`cb_color_palette()`), so rich text stays on-brand under either.',
    whenToUse: 'Long-form content, marketing copy, anything needing inline formatting or links.',
    controller: ['cb-tinymce', 'cb-ckeditor'],
    markup: '`.cb-kit-rich-text` (the stored HTML is rendered as-is).',
    sections: [
      {
        title: 'Choosing an editor',
        body: `
\`\`\`yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        rich_text:
            options:
                editor: ckeditor      # tinymce (default) | ckeditor
\`\`\`

Both editors are wired the same way, so everything below applies to either.
`,
      },
      {
        title: 'Where the editor is loaded from',
        body: `
By default the kit loads the editor from a CDN, so the block works on a fresh
install with nothing to build. Two other paths are supported, and neither adds
a dependency to the kit:

\`\`\`yaml
content_blocks_kit:
    blocks:
        rich_text:
            options:
                # 1. Self-host the same build — for a strict CSP, or an admin
                #    with no outbound internet access.
                cdn_url: '/assets/vendor/ckeditor5.umd.js'
                cdn_style_url: '/assets/vendor/ckeditor5.css'   # CKEditor only

                # 2. Bundle it yourself: the kit then loads nothing and expects
                #    the editor's global (window.tinymce / window.CKEDITOR) to
                #    be there when the sidebar opens.
                cdn: false
\`\`\`

With \`cdn: false\` it is on the host to \`npm install\` the editor and import it
in the entry that serves the admin — for example
\`import 'tinymce/tinymce.min.js'\` (or CKEditor's UMD build), so that the
global is defined. If it is missing, the block logs a precise message to the
console and leaves a plain textarea holding the HTML: the content is always
editable and never lost, whatever happens to the editor.

::: tip Pinned versions
The default CDN URLs point at TinyMCE 7 and CKEditor 5.48. CKEditor's editor
factory changed in 48; the controller reads \`window.CKEDITOR_VERSION\` and calls
the older signature for an older self-hosted build.
:::
`,
      },
      {
        title: 'Images',
        body: `
Image upload is wired by default and goes through the builder's own endpoint —
the same \`/_content-blocks/upload\`, CSRF token, size cap and MIME allow-list
the \`image\` block uses, so a picture pasted into rich text lands in the same
storage as every other asset. It follows that uploads need
[file storage](../../guide/host-services.md#file-storage) configured; without
it the endpoint refuses and the editor reports the error.

Turn it off with \`options: { uploads: false }\` — the image button disappears
and pasted images are no longer sent anywhere.
`,
      },
      {
        title: 'Configuring the editor itself',
        body: `
\`options.config\` is merged over the kit's init config in the browser, so
anything the editor accepts can be set without forking the block:

\`\`\`yaml
content_blocks_kit:
    blocks:
        rich_text:
            options:
                config:
                    height: 500
                    toolbar: 'bold italic | link'    # TinyMCE spelling
\`\`\`

Objects merge key by key; arrays and scalars replace. A toolbar you spell out
is a replacement, never an append.

Two things the merge deliberately protects, because losing them silently would
cost data rather than looks: the write-back that keeps autosave in sync (a
\`setup\` callback of your own runs *after* it rather than replacing it), and
the upload adapter, which survives a replaced plugin list — use
\`uploads: false\` if you mean to drop it.

Whatever JSON cannot carry — callbacks, plugin instances — belongs in a host
Stimulus controller extending \`cb-tinymce\` / \`cb-ckeditor\`, or in an editor
adapter of your own (see below).
`,
      },
      {
        title: 'Adding a third editor',
        body: `
Neither editor is special. A host registers Quill, Trix or an in-house editor
by implementing \`ContentBlocks\\Kit\\RichText\\RichTextEditorInterface\` —
extending \`AbstractRichTextEditor\` gives the shared option handling for free —
and shipping a Stimulus controller with the name it returns. The service is
auto-tagged, so it becomes selectable as \`options.editor: <name>\` with no
further wiring, and nothing in the kit changes.
`,
      },
    ],
    notes: [
      'The editor JavaScript lives in the **sidebar**, never in the preview — so the block opts into preview hot-reload.',
      'Color swatches read `content_blocks.palette` via `cb_color_palette()`, under both editors.',
      'The stored HTML is rendered with `|raw`: like `html_raw`, the block trusts whoever may edit it. Unlike `html_raw` it stays enabled by default, because a toolbar — not a free HTML field — is what produces the markup.',
    ],
  },
  image: {
    order: 4,
    tagline: 'A single image with size, fit, alignment, link, caption and rounded corners.',
    intro:
      'Displays one image with a rich set of presentation controls: a size preset (`sm`/`md`/`lg`/`full`) or a fully custom width/height, object-fit (`cover`/`contain`), horizontal alignment, an optional link wrapper, a caption, and per-corner border radius. Uploads go through the core upload brick (`ImageUploadType` + `/_content-blocks/upload`).',
    whenToUse: 'Any standalone image: hero, inline figure, logo, illustration.',
    markup: '`.cb-kit-image` — wraps a `<figure>` when a caption is present.',
    notes: [
      'Picking `size: custom` reveals `customWidth` / `customHeight` (with a `customHeightAuto` toggle) via conditional fields.',
      'Requires [file storage](../../guide/host-services.md#file-storage) to be configured for uploads to work.',
      'Opts into preview hot-reload — the upload JS is in the sidebar, not the view.',
    ],
  },
  gallery: {
    order: 5,
    tagline: 'A set of images as a responsive grid or an arrow slider.',
    intro:
      'Renders a collection of images either as a **grid** (column count is configurable) or as a **slider** with prev/next arrows. Shared controls: object-fit and rounded corners. The `max_columns` **option** caps how many columns the editor can choose.',
    whenToUse: 'Portfolios, product shots, logo walls, before/after strips.',
    controller: 'cb-gallery',
    markup: '`.cb-kit-gallery` (grid) / `.cb-kit-gallery--slider` (slider).',
    notes: [
      'The `cb-gallery` controller is only needed for the **slider** layout; a pure grid is CSS-only.',
      'Use the `max_columns` option to restrict the `columns` choice list for a given host.',
    ],
  },
  button: {
    order: 6,
    tagline: 'A call-to-action button with variants, sizes and alignment.',
    intro:
      'A styled link-button for calls to action. Choose a visual `variant` (e.g. primary/secondary/outline), a `size`, and horizontal alignment. The label and target URL are free text.',
    whenToUse: 'CTAs, "Learn more" links, form entry points.',
    markup: '`.cb-kit-button` with a `--<variant>` modifier.',
    notes: ['Restrict the `variant` / `size` choice lists per host to enforce a design system (see [Configuration](../configuration.md)).'],
  },
  card: {
    order: 7,
    tagline: 'Image / title / text / button tiles laid out as a grid or list.',
    intro:
      'A repeatable set of cards, each combining an image, a title, some text and an optional button. Lay them out as a **grid** (capped by the `max_columns` option) or a vertical **list**. Great for feature rows and teaser sections.',
    whenToUse: 'Feature grids, service tiles, article teasers, pricing tiers.',
    markup: '`.cb-kit-card` items inside `.cb-kit-cards`.',
    notes: ['The `max_columns` option caps the grid column choice for a given host.'],
  },
  list: {
    order: 8,
    tagline: 'A bulleted, checkmark or numbered list.',
    intro:
      'A simple list of items rendered as bullets, checkmarks, or numbers depending on the chosen style. Each item is plain text.',
    whenToUse: 'Feature lists, requirement checklists, step summaries.',
    markup: '`.cb-kit-list` with a style modifier.',
    notes: [],
  },
  icon: {
    order: 9,
    tagline: 'A single icon from the shipped icon set, in a palette color.',
    intro:
      'Renders one icon from the kit\'s **self-contained icon set** (no external icon library needed). Size and color (from the palette) are configurable.',
    whenToUse: 'Accents next to headings, feature bullets, inline glyphs.',
    markup: '`.cb-kit-icon` (inline SVG from the shipped `IconSet`).',
    notes: ['`color` draws from `content_blocks.palette`. The icon list is large — run `content-blocks-kit:blocks icon` to see the full set.'],
  },
  alert: {
    order: 10,
    tagline: 'An info / success / warning / error callout.',
    intro:
      'A colored callout box for drawing attention: informational, success, warning or error variants, each with its own accent color and (optional) icon.',
    whenToUse: 'Notices, tips, warnings, inline status messages.',
    markup: '`.cb-kit-alert` with a variant modifier.',
    notes: [],
  },
  divider: {
    order: 11,
    tagline: 'A horizontal rule with a configurable style and color.',
    intro: 'A horizontal separator between content. The line style (solid/dashed/dotted) and its color (from the palette) are configurable.',
    whenToUse: 'Visual breaks between content groups within a column.',
    markup: '`.cb-kit-divider`',
    notes: ['`color` draws from `content_blocks.palette`.'],
  },
  accordion: {
    order: 12,
    tagline: 'Collapsible panels built on native `<details>` — zero JavaScript.',
    intro:
      'A set of collapsible panels, each with a header and a body. Built on the **native `<details>`/`<summary>` elements**, so it needs no JavaScript at all and works even with JS disabled.',
    whenToUse: 'FAQs, disclosure sections, long content you want to fold away.',
    markup: '`.cb-kit-accordion` wrapping native `<details>` elements.',
    notes: ['No Stimulus controller required — the open/close behavior is browser-native.'],
  },
  table: {
    order: 13,
    tagline: 'A data table from configurable columns and rows.',
    intro: 'A straightforward data table: define columns and rows and the block renders a semantic `<table>`.',
    whenToUse: 'Specs, comparison tables, structured tabular data.',
    markup: '`.cb-kit-table` wrapping a native `<table>`.',
    notes: [],
  },
  embed: {
    order: 14,
    tagline: 'A responsive YouTube / Vimeo video embed.',
    intro:
      'Embeds a YouTube or Vimeo video responsively (16:9 by default) from a paste-in URL. URL parsing uses the core `cb_embed_url` helper, so both watch-page and share URLs work.',
    whenToUse: 'Product videos, tutorials, any hosted video.',
    markup: '`.cb-kit-embed` with a responsive iframe wrapper.',
    notes: ['Only YouTube and Vimeo are recognized; other providers are not embedded.'],
  },
  breadcrumb: {
    order: 15,
    tagline: 'A breadcrumb trail.',
    intro: 'Renders a breadcrumb navigation trail from a list of label/URL pairs.',
    whenToUse: 'Page hierarchy context near the top of a content area.',
    markup: '`.cb-kit-breadcrumb` (semantic `<nav>`).',
    notes: [],
  },
  html_raw: {
    order: 16,
    tagline: 'A raw-HTML escape hatch — renders unescaped markup.',
    intro:
      'Outputs its content **verbatim and unescaped** (`{{ html|raw }}`). It is the escape hatch for embedding third-party snippets (widgets, forms, custom markup) that no other block covers.',
    whenToUse: 'Trusted embeds and one-off markup that no structured block can express — only when you trust the editors.',
    markup: '`.cb-kit-html-raw` (content rendered as-is).',
    warning:
      'This block renders unescaped HTML and therefore **trusts its editors** (stored-XSS surface). It is **disabled by default** and must be explicitly opted in with `content_blocks_kit.blocks.html_raw.enabled: true`. Only enable it for roles you trust.',
    notes: [],
  },
  tabs: {
    order: 17,
    tagline: 'Tabbed panels.',
    intro: 'A set of labeled tabs, each revealing its own panel of content.',
    whenToUse: 'Grouping alternative content (specs vs. reviews, plans, platforms) in one compact area.',
    markup: '`.cb-kit-tabs`',
    notes: [],
  },
};
