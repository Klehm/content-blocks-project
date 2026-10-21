# SEO audit

The builder shows what a page looks like, not how a search engine reads it. An
editor picks a title size for its looks and ends up with two `h1`s, or an `h2`
followed by an `h4`, without noticing. The satellite package
[`klehm/content-blocks-seo`](https://github.com/klehm/content-blocks-seo) adds
an **SEO audit** panel to the builder that checks the draft page and points at
the block to fix.

```bash
composer require klehm/content-blocks-seo
```

Installing it changes nothing a visitor sees. The audit runs offline, in the
editor's browser: no provider, no external service, nothing sent anywhere.

## What it checks

| Check | Severity | Reported when |
|---|---|---|
| `heading_h1_missing` | error | the page has no `h1` at all |
| `heading_h1_multiple` | warning | a second `h1` appears |
| `heading_level_skipped` | warning | a heading goes down more than one level (`h2` then `h4`) |
| `heading_empty` | error | a heading has no text |
| `image_alt_missing` | error | an image has no `alt` attribute |
| `image_alt_empty` | warning | an image has an empty `alt` (right only for a decorative image) |
| `link_text_empty` | error | a link has no text, label or image `alt` |
| `link_text_generic` | warning | a link says "click here", "read more", "en savoir plus"… |
| `paragraph_too_long` | warning | a paragraph is over 150 words |

The audit reads **the draft page as a visitor would get it**, with your page
template around the content area. The headings of your template count, so the
`h1` you print for the page title is seen, and a title block set to `h1` is
reported as a second one.

Images, links and paragraphs are checked **inside the content area only**: your
template's logo or menu is yours to fix, not the editor's. A heading level
skipped in your template is not reported either.

Each finding inside a block is a button: clicking it selects the block, opens
its sidebar and scrolls the preview to it. The panel checks again after each
save, while it is open. Under the findings, *Heading outline* shows every
heading of the page, indented by level.

## Opening the panel

The package adds an **SEO audit** entry to the builder's Actions menu.

If you mount the builder with `ContentAreaType`, there is nothing to do. If you
include `@ContentBlocks/builder/launcher.html.twig` directly, bundle actions are
not collected for you: add the entry to your own `topbarActions`, with the key
the panel listens for.

```twig
{% include '@ContentBlocks/builder/launcher.html.twig' with {
    area: page.contentArea,
    iframeUrl: cb_preview_url(page.contentArea),
    topbarActions: [
        { key: 'content-blocks-seo.audit', label: 'app.builder.seo_audit' },
    ],
} only %}
```

## Routes

The panel's stylesheet and scripts are served by the package, so there is no
Stimulus controller to declare and nothing to rebuild. With Flex the route file
is imported for you; otherwise:

```yaml
# config/routes/content_blocks_seo.yaml
content_blocks_seo:
    resource: '@ContentBlocksSeoBundle/config/routes.php'   # under /_content-blocks/seo
```

To mount it elsewhere, import `config/routes/bare.php` with your own prefix,
as for the core ([Mounting the routes](./routing.md)). The route name,
`content_blocks_seo_asset`, is the same either way.

## Configuration

```yaml
# config/packages/content_blocks_seo.yaml
content_blocks_seo:
    paragraph_max_words: 150        # 0 turns the check off
    generic_link_texts:             # replaces the default list
        - click here
        - read more
        - klik hier
    disabled_checks:                # by name, from the table above
        - image_alt_empty
```

`generic_link_texts` is compared ignoring case, accents and punctuation:
`Cliquez ici !` matches `cliquez ici`. The default list covers English, French,
German and Spanish; setting the key replaces it, so include the defaults you
want to keep.

An unknown name in `disabled_checks` is refused when the container builds,
with the list of known checks.

## What it does not do

- **No target keyword.** Checking a keyword's presence in the `h1` and the first
  paragraph needs the keyword, which lives on your entity, not on the content
  area. That second layer is on the [roadmap](https://github.com/klehm/content-blocks-project/blob/master/ROADMAP.md).
- **No meta tags.** Title, description and canonical are your template's; the
  audit does not read `<head>`.
- **No rendering checks.** Contrast, font sizes and layout shifts need a real
  layout engine; the audit reads markup.
