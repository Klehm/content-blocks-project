# SEO audit (klehm/content-blocks-seo)

The roadmap entry *On-page SEO audit* described two layers. This package is the
first one: a deterministic linter, offline, with no provider and no model. The
second layer (target keywords supplied by the host) is still on the roadmap.

## The panel reads the page a crawler reads

The audit runs on the draft page as the preview URL renders it with
`cb_preview=1&cb_chrome=0`, fetched by the panel and parsed with `DOMParser`.
Three things decided that, against the alternatives:

- **Not the builder's iframe.** The iframe carries the chrome: add buttons,
  handles, the toolbar, and deleted blocks still in the DOM. Every one of them
  is a link or a heading the page does not have. `cb_chrome=0` prunes them and
  keeps `data-cb-block-id` on each block wrapper (that attribute follows
  PREVIEW mode, not chrome), which is all the linter needs to point at a block.
- **Not a server-side render of the area.** A crawler sees the host's page,
  template included. The single-`h1` rule is only meaningful with the host's
  own `h1` in view: the sandbox's page title is in `page/show.html.twig`, and an
  area rendered alone would report "no h1" on every page.
- **Not PHP.** The checks are DOM questions (what a link's name is, which block
  holds an element). The browser answers them natively, and Vitest with jsdom
  tests them without a kernel. PHP keeps only the config and its validation;
  `SeoChecks::ALL` is checked against `CHECKS` in `audit.js` by a test, so the
  two lists cannot drift.

A redirected response is refused rather than audited: an expired session
answers with the login page, which would audit as a page with one form and no
content.

## What the linter reads

Headings are read on the **whole page**, because the outline is the page's.
Everything else (images, links, paragraphs) is read **inside the content area
only** (`.cb-content-area[data-cb-preview]`): a logo without `alt` in the host's
header is not the editor's to fix, and listing it on every page teaches the
editor to ignore the panel.

The same rule decides where a finding lands:

| Scope | Meaning | In the panel |
|---|---|---|
| `block` | inside a `[data-cb-block-id]` | a button; the click dispatches `cb:focus` |
| `area` | inside the area, outside any block | a row, labelled *This content area* |
| `page` | in the host template | a row, labelled *Page template* |

A skipped heading level is reported only when the heading that skips is inside
the area. A `<h4>` in the host's footer after the editor's `<h2>` is the
host's.

### Choices in the checks

- **`alt=""` is a warning, `alt` missing an error.** The kit always writes the
  attribute, empty when the editor left the field blank, so "missing" alone
  would never fire on kit content. An empty `alt` is right for a decorative
  image, hence a warning; `role="presentation"` or `aria-hidden="true"` says
  decorative on purpose and silences it.
- **The link name is a subset of the accessible name computation**: `aria-label`,
  then `aria-labelledby`, then the text (minus `aria-hidden` children), then
  the `alt` of images inside, then `title`. Enough for what blocks render; not
  a full implementation.
- **Generic link texts are compared normalized**: case, accents and
  punctuation folded, so "Cliquez ici !" and "cliquez ici" match, and an arrow
  glyph after "Read more" does not save it.
- **An empty kit title is not a finding.** The kit's `title` view renders
  nothing when the text is blank, so there is no heading to report. The
  `heading_empty` check catches headings with no text in rich text or custom
  blocks.

## Re-running

The panel re-audits, debounced, on `cb:ready` (a full preview reload),
`cb:block:saved`, `cb:section:saved` and `cb:area:changed`, and only while it is
open. A run overtaken by a newer one is dropped, so a slow response cannot
paint stale findings over fresh ones.

## Why `cb:focus` went into the core

The navigator selected a block through `cb:tree:select`, which is internal
choreography. A satellite had no public way to say "open this block", so the
core gained a third inbound event, `cb:focus` (`{kind, id}`), routed to the
same code as a navigator row. On a core without it the panel still lists the
findings; the click does nothing.

## The Actions menu entry

`SeoActionProvider` contributes the menu entry, collected by the shell itself
(`cb_builder_actions`), so it shows on a direct `launcher.html.twig` include
too. The panel is a shell fragment and renders either way.
