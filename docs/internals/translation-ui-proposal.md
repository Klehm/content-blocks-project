# Translation UI — the workbench

Working document for the front-end half of `klehm/content-blocks-i18n`. The
backend is built and verified; this is what goes on top of it, and it is written
down first because two of the decisions below are hard to reverse once specs
exist.

**Decided:** a full-page **workbench** — every translatable field of the page in
one long list, source left, target right — with the page **preview beside it**,
following the focused field. Retained requirements from the brief:

1. the preview scrolls to and highlights the field being edited;
2. reloading after an edit is **inline HTML only** — never a full iframe reload;
3. the preview can be **hidden**, giving the list the full width.

---

## Why the workbench, and not the alternatives

Three shapes were on the table. The deciding question was the stated goal:
*the fastest possible data entry.*

| Shape | Why not |
|---|---|
| **Bilingual sidebar per block** — keep the builder, click a block, translate it in the existing sidebar | Costs a click-and-wait per block. A 40-block page is 40 round trips through a UI designed for editing one thing carefully. Fine as a secondary path for a one-off fix; hopeless for a first pass. |
| **In-place editing in the preview** — switch the iframe to the target language and type over the text | Most WYSIWYG, and it only reaches text that is *visible as text*. A button's `href`, an image's `alt`, a table cell, a collapsed accordion panel have nowhere to appear. It would translate the easy 70% and silently hide the rest. |
| **Workbench** ✅ | One request, every field, in reading order. `Tab` moves to the next field; nothing else is a round trip. It is the only shape where the unit of work is *the page* rather than *a block*. |

The workbench is also the only one where progress and staleness have somewhere
natural to live: filter chips over a flat list are trivial, whereas "show me the
4 outdated fields on this page" has no home in a sidebar-per-block UI.

---

## Layout

```
┌─ Démo multilingue ─────────────── [FR] → [DE ▾] ──────── [ ◨ hide preview ] ─┐
│ ████████████████░░░░░░  62%     38 ok · 4 outdated · 19 empty     [⚡ all]   │
│ [ All ] [ Empty 19 ] [ Outdated 4 ]                                          │
├──────────────────────────────────────────┬───────────────────────────────────┤
│ SOURCE (FR)            │ DE              │  PREVIEW (DE) — follows focus     │
├────────────────────────┼─────────────────┤                                   │
│ § 1 · Titre            │                 │   ╔═══════════════════════════╗   │
│  Bienvenue dans notre  │ ┌─────────────┐ │   ║ Willkommen in unserem     ║   │
│  boutique              │ │ Willkommen… │⚡│   ║ Shop                      ║   │
│                        │ └─────────────┘ │   ╚═══════════════════════════╝   │
│                        │                 │      ▲ scrolled + highlighted     │
│ § 1 · Texte        ⟲   │ ┌─────────────┐ │                                   │
│  Nous livrons partout  │ │ Wir liefern…│⚡│     Wir liefern überall…          │
│  ⟲ source modifiée     │ └─────────────┘ │                                   │
│                        │                 │   ┌──────────┬──────────┐         │
│ § 2 · Carte 1 · Titre  │ ┌─────────────┐ │   │ Karte 1  │ Karte 2  │         │
│  Livraison rapide      │ │             │⚡│   └──────────┴──────────┘         │
└────────────────────────┴─────────────────┴───────────────────────────────────┘
  Tab → next field · ⚡ translate this field · autosave
```

Both halves are already served by endpoints that exist:

- `GET /_content-blocks/i18n/area/{id}/locales` fills the switcher, with each
  language's percentage next to it.
- `GET /_content-blocks/i18n/area/{id}/{locale}` returns the whole list in one
  request: blocks in reading order, each with its fields, and per field the
  source text, the stored value, the status and a widget hint
  (`text` / `textarea` / `html` / `url` / `email`).

Nothing about the payload assumes this layout, which is deliberate — the same
endpoints back a script or a future sidebar mode.

---

## The three retained requirements

### 1. Scroll-to-field

Every field row carries its `blockId`. Focusing a row posts
`cb:i18n:focus { blockId }` into the preview iframe; the overlay already resolves
`[data-cb-block-id]` for the builder's own click-to-edit, so the lookup exists —
it scrolls the element into view and adds a highlight class.

The sync is **one-way, focus-driven**. Scroll-linking the two panes (preview
scroll ↔ list scroll) was considered and rejected: the two have unrelated
heights, feedback loops are fiddly to damp, and a translator's attention is in
the list. The preview follows; it does not lead.

### 2. Inline reload only

This is the requirement that shaped the endpoint design, and the mechanism
already exists in the core:

```
GET /_content-blocks/block/{id}/render  →  { hotReload: true, html: "…" }
```

`BlockRenderer::renderBlock()` renders **one block** through the same wrapper a
full page render uses, and `RenderContext` carries a locale. So after a field is
saved, the preview updates by fetching that one block in the target locale and
swapping its `outerHTML`. No iframe reload, no scroll position lost, no
re-running the page's JavaScript.

Two things to carry over from the builder's existing hot-swap path rather than
reinvent:

- **`supportsPreviewHotReload()`** — a block type whose view needs its JS to
  re-run answers `hotReload: false`, and the caller falls back to a full reload.
  The translation preview must honour the same flag.
- The endpoint currently hard-codes `RenderContext::forPreview()`. It needs to
  accept a `?locale=` parameter and pass it through — a small, additive change,
  and the only core work the workbench still needs.

### 3. Hideable preview

A toggle in the topbar; the list expands to full width and the source/target
columns widen with it. Persisted per user in `localStorage` (same key convention
as the builder's other view state), because a translator working through a long
page will collapse it once and want it to stay collapsed.

Hiding also stops the postMessage traffic — no focus events, no fragment
fetches — so the collapsed mode is genuinely cheaper, not just narrower.

---

## Behaviour worth pinning now

**Autosave, debounced, per field.** `POST /block/{id}/{locale}` already accepts a
batch, so a debounce window naturally coalesces several fields of the same block
into one request. The write is draft-only, so an autosave is never a publish.

**Empty vs cleared.** The API distinguishes `""` (a deliberate blank — this card
has no subtitle in German) from `null` (no translation — fall back to the
source). The UI needs both: typing nothing leaves the field empty and stores
`""`; a per-row "reset to source" action sends `null`. Collapsing the two would
make it impossible to say "this optional line does not exist in German" without
the English leaking onto the German page.

**Outdated rows** show the ⟲ marker, the current source, and two actions:
re-translate (⚡) or *"still current"*, which re-stamps the digest without
retyping. The second one matters more than it looks: a staleness flag that can
only be cleared by redoing work already done is a flag people learn to ignore.

**Filters are counts, not just chips.** "Empty 19 / Outdated 4" is the whole
job list; clicking one filters the rows and the `Tab` order with it, so
"translate the 19 missing fields" is a single pass.

---

## What is left to build

| | |
|---|---|
| Core | `?locale=` on `GET /_content-blocks/block/{id}/render` (additive) |
| Package | `cb-i18n-workbench` Stimulus controller + Twig shell |
| Package | Topbar entry point (locale switcher with per-locale progress) |
| Sandboxes | Declare the controller in `assets/package.json` + the three `controllers.json` |
| Tests | Vitest on the controller; Playwright on the round trip (the sandbox's deterministic `pseudo` provider makes this offline-safe) |

Everything else the workbench needs — the field list, the statuses, the saves,
the machine translation, the progress — is built, tested and verified end to end
against the sandbox.
