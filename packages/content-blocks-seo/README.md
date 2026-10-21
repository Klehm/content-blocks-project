# ContentBlocks SEO audit

An **SEO audit** panel for the [ContentBlocks](https://github.com/klehm/content-blocks)
builder: it checks the draft page the way a search engine reads it and points
at the block to fix.

- Heading outline: no `h1`, a second `h1`, a skipped level, an empty heading
- Images without alternative text
- Links with no text, or vague text ("click here", "en savoir plus"…)
- Paragraphs that run too long

The audit runs offline, in the editor's browser, on the draft page with your
template around it. Nothing is sent anywhere, and nothing a visitor sees
changes.

```bash
composer require klehm/content-blocks-seo
```

The panel opens from the builder's Actions menu. Each finding inside a block
selects that block when clicked, and the panel checks again after each save.

## Configuration

```yaml
# config/packages/content_blocks_seo.yaml
content_blocks_seo:
    paragraph_max_words: 150        # 0 turns the check off
    generic_link_texts: [...]       # replaces the default list (en, fr, de, es)
    disabled_checks: []             # e.g. [image_alt_empty]
```

Full guide: [SEO audit](https://klehm.github.io/content-blocks-project/guide/seo).

## License

MIT
