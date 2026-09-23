# Frontend — the builder shell, the overlay, and the seam between them

Integrator-facing version: the *UI Admin* section of
[../../CLAUDE.md](../../CLAUDE.md).

## Two documents, one editor

The builder is a parent admin window and an **iframe** rendering the host's own
public page in PREVIEW mode. They are separate documents, which is the single
fact most of this design follows from.

| | Runs | Does |
|---|---|---|
| `preview-overlay.js` | inside the iframe | signals intents, never acts |
| `cb-builder_controller.js` | the parent window | owns every AJAX call and all state |

The overlay is **plain JS, not Stimulus**, so the host's front theme does not
have to carry a Stimulus loader. `BlockRenderer` injects it, and `builder.css`
alongside it, only in PREVIEW.

It does no AJAX at all. It signals `cb:ready`, shows a hover toolbar over
anything carrying a `data-cb-*-id` marker, forwards toolbar clicks as typed
`postMessage` events, and blocks intra-iframe navigation so an editor cannot
accidentally leave the page being edited. Everything else happens in the parent.

### The cb:* event contract

**Five events are public API and stable across 1.x.** Four outbound, which a host
may listen to on the builder element:

| Event | Means |
|---|---|
| `cb:ready` | the preview iframe has mounted and is interactive |
| `cb:block:saved` | a block's draft was persisted |
| `cb:section:saved` | a section's draft was persisted |
| `cb:builder:action` | a host-contributed topbar action was invoked |

One is **inbound**, dispatched *at* the builder from the shell element or
anything inside it — a [shell fragment](builder-extensions.md), say:

- `cb:area:changed` — the area was written server-side behind the builder's
  back (restored, replaced, mass-edited), so it reloads the preview and re-syncs
  Publish/Discard. `detail.hasUnpublishedChanges` is optional and **defaults to
  true**, since such a change is a draft write.

Every other `cb:*` event across these two files is **internal choreography** —
the `…-requested`, `…:apply`, `…:patch`, `…:desync` and `cb:tree:*` families in
particular. They may be renamed, split or removed in any minor release.

A `cb:area:changed` **supersedes** a pending debounced reload rather than
coalescing with it: the area just changed wholesale, and waiting out the quiet
period would show the stale preview for that much longer.

## Endpoint URLs come from the router

The mount point belongs to the host ([routing guide](../guide/routing.md)), so no
script spells `/_content-blocks`. The shell carries one value,
`data-cb-api-base`, and `cb-builder`, `cb-tree` and `cb-file-upload` append a
path to it. PHP never builds a path either: it generates by route name.

**One base, not a URL per endpoint.** The i18n workbench hands its JS one
`path()` per URL, which is right for a handful. The builder calls about thirty
endpoints, and a thirty-entry map on the shell would be one more table to keep
in step with the controllers. A single base can express that because
`routes/editor.php` is imported as a unit: every editor route shares its prefix.

`cb_api_base()` derives the base from the router, by generating one argument-free
anchor route (`content_blocks_block_types`) and stripping its known suffix. That
keeps the app's base URL for free (an app under `/shop/index.php`). If the host
re-pathed that route alone, the suffix no longer matches and the function
**throws** instead of guessing a base that would silently 404 every call.

**The public assets are a separate import**, not a sub-prefix of the editor
mount. A public page links `layout.css` and `styling.css`, so they must live
outside whatever firewall the editor mount sits behind. With one import, a host
moving the builder under `/admin` would have broken every visitor's page.

The JS falls back to `/_content-blocks` when the attribute is absent, so a host
that overrides `shell.html.twig` from an older version keeps working on the
default mount.

## Mutations are serialized

`_jsonRequest()` funnels **every** structural mutation through one in-flight
slot: each request waits for the previous one to settle.

Every reorder, duplicate, create and delete endpoint does a read-modify-write of
a whole sibling set's `previewPosition`. Two overlapping requests would each read
the other's pre-commit state, and whichever committed last would clobber the
earlier reorder — a classic lost update.

The visible symptom was a drag that looked like it worked and silently snapped
back after a reload: intermittent, and worse on large pages where slower requests
widen the overlap window. The added latency is invisible for click- and
drag-driven actions.

Two details in the queue itself:

- the chain runs on **both** fulfil and reject, so a prior failure still releases
  the slot;
- rejections are swallowed **on the tail only**, so one failed request cannot
  wedge every later mutation behind a permanently-rejected promise.

`_performJsonRequest()` never rejects — it catches network failures and non-OK
responses and resolves to null — so callers keep getting a result, or null, in
submission order. Without that catch a network failure would propagate to callers
that never handle it and the editor would get no feedback at all.

An endpoint answering a refusal with a **reason the editor can act on** (a paste
with nothing selected, a copy from another content generation) opts its status
out of the generic banner through `tolerate`, and the caller reads the body to
say something specific.

## Hot reload and when it is refused

Five flows patch the preview in place instead of reloading it: block insert,
duplicate, reorder, and adding or deleting a section.

Insert and duplicate ship server-rendered markup **only when the block opts into
hot reload** ([why it is opt-in](blocks.md#preview-hot-reload-is-opt-in)); a
JS-dependent block returns no `html` and the builder falls back to a full reload
so its scripts run. A section qualifies only when every one of its blocks does.

Reorder is different: it **moves the live node**, so the block's DOM and JS state
survive. A re-render or a reload would discard both.

Adding a section always ships its markup: a new section has no block, so there
is no script to run. It lands ahead of the add-section tray, pinned and scrolled
into view. The overlay refuses (`cb:reorder:desync`, hence a reload) when the
page holds more than one tray, since it cannot tell which area grew.

Deleting a section **flags** it rather than removing it: `data-cb-deleted` and
the `--deleted` class on the section, its columns and its blocks — the markup a
reload renders, hidden by CSS. Both flows re-sync the area's empty state, and
hidden sections do not count towards it, on either side.

Every one of these falls back to a full reload if the iframe cannot be reached.

Undo of a section delete and `Ctrl-Z` still reload: a restored section brings its
whole subtree and position back, and a history step can touch any of the area.

`replaceBlock()` re-pins any hover or focus from the old node onto the fresh one,
so the outline and toolbar survive the swap, and dispatches `cb:block:rendered`
on the new element — the one place page scripts do not re-run on their own, since
HTML is injected rather than the document reloaded.

Section style hot-reload copies only the freshly-rendered **wrapper** attributes
(the `<section>` and each column's class and style) onto the existing nodes,
leaving the inner blocks untouched. That is always safe, because a section's
settings never change its structure. Overlay-owned classes are re-applied after
the copy.

## Focus and the sidebar

Clicking a block or a section is what opens its sidebar, so **the sidebar *is*
the selection** — there is no second notion of "focused entity" to keep in sync.
The open entity is read from the sidebar's `data-*` mount markers.

The sidebar is permanent: it always occupies its grid column and only its content
swaps. There is no open/close lifecycle any more, because forms autosave.

`hoveredEl` follows the cursor; `focusedEl` is pinned by an explicit click and
stops hover from moving the toolbar elsewhere. Hover is also suppressed during a
drag, so the toolbar does not pop up over everything the cursor passes on the way
to a drop target.

After any iframe reload, and after an in-place section delete, the parent asks
the overlay to **re-pin** focus, or the outline and toolbar would vanish on every
autosave. When the pinned element no longer exists or is flagged deleted — a
section delete that cascaded to a focused child block — the overlay replies
`cb:focus:not-found` and the parent clears the stale form.

**Only the latest sidebar request lands.** Adding a section opens its settings,
and a click on it right after asks again; clicking outside clears the sidebar
while a form is still on its way. A late response used to replace what the
editor had picked since (or bring back the form they had just dismissed), so
`_mountSidebarFrom` drops any response that is not the latest request, and
`_resetSidebarToEmptyState` cancels the one in flight.

A newly inserted section wins over the restored scroll position, on the reload
fallback too: it lands at the end of the area, often below the fold, and
restoring the old scroll would hide the one thing the editor wants to see.

## The tree is a second view, not a second state

The outline panel (`cb-tree`) answers "what is in this area, in order" and lets
an editor move a block from the top of a long page to the bottom without
dragging past everything in between. It owns **no** state of the content:

- **Selection** is still the sidebar's mount markers. The panel highlights
  whatever the sidebar has open, whether the click landed in the tree or in the
  preview, and it never records a selection of its own. Same reason the
  clipboard reads its selection there.
- **Mutations** are the endpoints that already existed — `move`, `duplicate`,
  `delete` on sections and blocks. The panel signals; `cb-builder` performs, so
  everything still funnels through the one serialized queue.
- **The outline** is re-fetched from `GET /_content-blocks/area/{id}/tree`, and
  `_applyDraftState()` is where the panel is told the area moved under it —
  every mutation path passes through it, so no call site can forget.

The panel is **not a modal**: the sidebar is where a selected node is edited, so
the two are used together. Hence a floating panel over the preview rather than a
second sidebar mode, no backdrop, and Escape reaching it only after every real
modal has had its turn.

Its DOM home is beside `<main>`, not in the topbar: `.cb-shell` is the
positioning context it needs, and the topbar is 56px tall. (Anchored inside the
topbar, `max-height: calc(100% - 84px)` resolves against 56px and the panel
collapses to nothing.) The toggle button stays in the topbar and therefore
outside the panel's Stimulus scope, so the two talk through `cb:tree:toggle` and
`cb:tree:state` like everything else here.

**The panel is movable, and that is not a nicety.** It floats over the very
content it describes, so it has to be draggable off whatever it is hiding — by
its header, on pointer events so mouse, pen and touch share one path. Two rules
keep it usable: the position is **clamped** to the shell on every move, on open,
and on window resize (a panel dragged fully out has no way back), and it is only
written as an inline `left`/`top` **once the editor has moved it** — until then
the CSS default applies, which follows the sidebar's width and its collapsed
state. That is also why `max-width` stopped being derived from
`--cb-sidebar-width`: after a drag, `left` is a pixel value and the sidebar has
nothing to do with how wide the panel may be.

**A block row is a glyph plus a line of text.** The text comes from
`BlockPreviewHintInterface` — the same seam the section library's thumbnails
read — and falls back to the type's label when the block has nothing to
summarise. The glyph is `BlockTypeInterface::getIcon()`, trusted block-author
SVG injected as-is, exactly as the in-preview block picker does it.

Naming the type *beside* that label was the first cut, and it read as noise: a
gallery with no caption showed "Galerie" twice over. The icon says the type
without spending a word on it, and it is the same glyph the picker used when the
block was added — so the row is recognisable rather than merely labelled. A type
shipping no icon gets a generic square; `cb-tree` carries its own copy of that
fallback because the overlay is a separate document and the two share no module
graph.

**Columns are read-only nodes.** They are derived from the section's layout and
have no CRUD of their own; deleting or duplicating one would mean deciding what
happens to the layout. Showing them keeps the outline structurally honest
without inventing operations the model does not have.

### Keyboard and clipboard

Shortcuts act on the **pinned** element, so they only ever fire after an explicit
click, and each maps to the exact same intent its toolbar button posts — one
source of truth in the parent.

Copy and paste are **relayed, not handled**. The preview is a separate document,
so a Ctrl/Cmd-C pressed inside it never reaches the builder window; the overlay
forwards the intent and no clipboard state lives in the preview. What gets copied
is decided in the parent, because only that side knows what the sidebar has open.

Two things must never be stolen: a keystroke typed into a form field the page
itself renders, and a **genuine text selection** someone is copying. Stealing
that second one would be worse than not having the shortcut.

A block wins over its section — it is the more specific of the two, and what the
editor was last looking at.

`Ctrl/Cmd-Z` and `Ctrl/Cmd-Shift-Z` (plus `Ctrl-Y`) join the same table and obey
the same two rules; the stack they walk is the server's, and what it holds is in
[history.md](history.md). One chord table, `shortcutIntent`, answers both the
shell's own keydown and the relayed one, so the two paths cannot drift.
`Ctrl-Shift-V` stays the browser's paste-as-plain-text: only `z` reads Shift.

The clipboard lives in `localStorage` under one key, because "copy here, paste
over there" usually means leaving this page. The flip side is that the payload is
user-writable, which is why the paste endpoint
[replays it through each block's own form](clipboard.md#why-the-clipboard-needs-a-replayer).
A `localStorage` write can fail (private mode, quota, storage disabled), and
saying so beats a paste that mystifyingly does nothing.

An entry the server **cannot read** is cleared: it will never paste, here or
anywhere, and keeping it only lets the editor hit the same wall again. A stale
content version is the same — only a fresh copy fixes it. `no_target` is the
exception, since the entry is fine and only the selection was missing.

## Feedback: what stays and what flashes

| | Behaviour |
|---|---|
| "Saved" flash | transient |
| Save-error banner | **persistent** until a later save succeeds |
| Undo snackbar | ~6s, single slot |

The error banner stays because the editor must know their latest edits are not
stored. The undo window is the editor's only one-click recovery from a delete
short of discarding the whole draft, since deletes are immediate with no confirm.

The snackbar is also used with its button hidden, to acknowledge a copy or state
a refusal. It shares the timer and the single slot with the undo offer — two bars
stacking would be worse than the newer message winning — so it clears any pending
offer it replaces rather than leaving an invisible one armed.

Loading is **reference-counted**, so overlapping operations stack and the
progress bar only goes away once the last finishes.

**Discard is gated behind a confirm.** It throws away every unpublished edit at
once: far more destructive than a single delete, which has its own Undo, and
irreversible.

Discard is *hidden* when nothing is pending rather than rendered disabled — the
editor only sees it when it is actionable. Publish stays visible and disabled, so
they know it exists.

After any structural op the draft state is flipped on **proactively** rather than
round-tripping to discover the area is dirty, since every such op leaves at least
one unpublished change.

### Live Component failures need two hooks

A Live Component in the sidebar fails in two ways, and neither surfaces on its
own:

- **`response:error`** — the server answered with a non-component response (a
  500, or an expired session, see below). Live's default raw-HTML error modal is
  suppressed in favour of the topbar banner.
- **a network failure** — Live's request promise has no rejection handler at all,
  so the save dies silently. One is attached through `loading.state:started`.

Live also never resets `backendRequest` when its request rejects, so every later
action would queue behind a dead request forever: the component is wedged, and
the editor's retry silently does nothing. It is cleared explicitly.

A failure is dispatched as `cb:save:error` on the form's **autosave wrapper**,
which does two things at once: `cb-autosave` resets its dirty-detection baseline
so the next interaction re-attempts the save rather than treating the failed state
as already saved, and the event bubbles up to show the banner.

### An expired session is said, not followed

The editor leaves the builder open, comes back an hour later, and the session
is gone. The host's firewall does not answer the builder's calls with a 401: a
form-login entry point **redirects** to the login page, and `fetch()` follows
redirects silently. The builder saw a `200` carrying HTML. The section form
took it for a save and emitted `cb:section:saved`; the hot reload then failed
to parse JSON and fell back to reloading the iframe, whose preview URL the
firewall redirected too: a front page with nothing to do with the edit, and an
edit that was never stored.

Two halves, so neither depends on the other:

- **Server — `SessionExpiredResponseListener`.** A redirect answering a
  *fetch* (`Sec-Fetch-Mode` other than `navigate`, or JSON / Live / XHR
  headers when fetch metadata is absent) on a package route (`_route` starting
  `content_blocks_`, or a `ContentBlocks:` Live Component) becomes
  `401 {"error":"session_expired","login":…}` with
  `X-Content-Blocks-Session: expired`. None of these endpoints redirects on its
  own, so nothing legitimate is lost; a navigation (the workbench page, the
  asset report) still reaches the login form. It runs at priority 10, ahead of
  `LiveComponentSubscriber`, which would otherwise turn the redirect into
  `X-Live-Redirect` and send the whole admin tab to the login page.
- **Client — `isSessionLoss()`.** A 401, the marker header, or
  `response.redirected` (so a host whose redirect escapes the listener is still
  caught). `cb-builder` checks it on every fetch it makes; the section form, the
  tree and the upload widget report it as `cb:save:error` with
  `detail.sessionExpired`, so the autosave baseline resets exactly as for any
  failed save.

A lost session raises its own banner in place of the generic save error, and
**`reload()` does nothing** until the session is back: reloading is what put a
login or a front page in the preview. The banner's link reopens the *current*
page in a new tab rather than the login URL: the firewall remembers the target
path, so the editor lands back on their page, and the tab with the unsaved
edit stays where it was.

**Checking on the way back.** The first interaction after `sessionCheckAfter`
(60 s) of inactivity — window focus, the tab becoming visible, a pointer or key
in the shell, a message from the preview — sends `GET /area/{id}/state`. The
banner then shows *before* the editor types into a dead session. While the
session is known lost, every interaction rechecks, spaced by
`SESSION_RECHECK_MS`. A live answer carries `csrfToken`, written back to
`data-cb-csrf-token`: a renewed session (a new login, or remember-me) issues a
new token, and without it every later mutation would fail its CSRF check. When
the session is found back, the banner goes, the open sidebar form is flushed —
its baseline was reset, so the swallowed edit is sent again — and the preview
reloads.

This is not a keep-alive: nothing pings on a timer, so the host's session
lifetime still decides. A check only follows the editor's own activity.

## What autosave compares

`cb-autosave` saves only when the serialized form differs from what it last
sent, so one edit that fires `input`, `change` and `focusout` is one save. Two
fields are left out of that comparison because code rewrites them, not the
editor:

- `[linked]`: cb-spacing-link ticks the box on connect when four sides match.
- `[_token]`: Symfony's `csrf_protection_controller.js`, which the Flex recipe
  installs in the host, swaps the placeholder for a real token on the first
  submit. Compared, it made the next focusout post the same values again, and
  when that focusout was the click on *Revert to published*, the late save
  landed after the discard and marked the draft as changed.

## Why the range field debounces locally

The number input is the submitted field, so an editor can type a value finer
than the slider's step grid — a `range` input cannot hold a value off its step.
The slider mirrors it and writes its snapped value back.

The number input is also the **only model-bound field**, and setting
`number.value` programmatically fires no event, so a slider move would never
reach the server and the morph would revert it. The slider's own `input` and
`change` are therefore re-dispatched onto the number input, as if typed there.

Typing is debounced **locally**, ahead of autosave. Otherwise each keystroke's
`input` bubbles to autosave, whose shorter debounce flushes a `change` on the
still-focused field — clamping the partial value, snapping the slider and
triggering a Live morph *between two keystrokes*. That mid-typing commit is the
"jump" that makes the field hard to fill. The slider drag path keeps its
immediate commit on release.

## The Import / Export dialog

A native `<dialog>` opened with `showModal()`, rather than one more absolutely
positioned panel: focus is trapped and restored, the backdrop and `Escape` come
with it, and it sits in the top layer above the builder's own `<dialog>`. The
shell's `Escape` handler still sees the key first and cancels the default, so
`_closeTopModal()` closes the dialog itself — except while an import runs, when
closing would leave half the files sent.

The logic is in `assets/transfer/`, as plain modules `cb-builder` imports, not
as a Stimulus controller: a new controller name is one more line every host
must add to `controllers.json`, and a relative import resolves under AssetMapper
and Encore alike. `zip-reader.js` reads an archive's directory from a `Blob`
(stored and deflated entries, ZIP64 included); `import-source.js` turns a zip or
a pre-RC17 JSON into the same manifest-plus-files shape; `import-flow.js` runs
the steps against the server and holds no DOM; `transfer-dialog.js` is the UI.

**The import says what it will do before doing it.** The file is read and the
plan asked for before the *Import* button: sections and blocks, media already
here, to send, missing or too large, block types unknown here. That review
replaces the `window.confirm` the panel used, which said nothing about the file.

**The export is a link, not a fetch.** An `<a download>` pointing at the
streamed zip lets the browser's own download UI show the progress the exact
`Content-Length` allows, and keeps the archive out of the page's memory.

The dialog's strings arrive as one JSON attribute (`data-cb-transfer-strings`)
rather than one `data-i18n-*` attribute per key: there are some thirty, and the
dialog is the only reader.

## Smaller decisions worth keeping

**Autosave debounces the reload, not the save.** Typing fires many `cb:*:saved`
events per second; the iframe refreshes only after a pause.

**Viewport buttons hide when the shell is too narrow.** Emulating an iPad-width
preview on a phone-sized screen only clips the iframe. If the active viewport is
the one that just got hidden, it falls back to desktop rather than staying stuck
at a clipped size.

**One backdrop for all three modal pickers.** They are mutually exclusive, and a
single node means the dimming can never stack or be left behind by a picker that
forgot to clean up.

**Outside-click closes the menu, but a picker only closes on its backdrop**, so a
click inside one — or on its list's scrollbar — never dismisses it. Escape closes
the topmost thing open.

**Focus returns to the toggle when the Actions menu closes.** Without it focus
falls to `<body>` and the next Tab restarts from the top of the shell.

**The overlay toolbar has no Edit button.** Clicking the section or block itself
opens the sidebar; the toolbar carries only structural actions — drag, move,
duplicate, delete.

**Drag starts on `pointerdown`, not a click.** Pointer events mean touch, pen and
mouse share one code path, with `touch-action: none` on the handle so a
touch-drag is not hijacked by the page's scroll gesture. The click is swallowed
so a drag-start is not read as an outside-click that closes the sidebar.

**The toolbar is clamped to the viewport**, so an element flush with the top of
the iframe does not push its chip off-screen.

**Overlay labels are injected server-side**, with English fallbacks as a safety
net. The fallbacks are not the source: a string that only exists in JS can never
be translated.

**Host topbar actions emit one generic event** carrying `detail.key`, rather than
per-key event types, which keeps `add`/`removeEventListener` simple on the host
side.
