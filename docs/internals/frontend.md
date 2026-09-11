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

Three flows can patch the preview in place instead of reloading it: insert,
duplicate and reorder.

Insert and duplicate ship server-rendered markup **only when the block opts into
hot reload** ([why it is opt-in](blocks.md#preview-hot-reload-is-opt-in)); a
JS-dependent block returns no `html` and the builder falls back to a full reload
so its scripts run. A section qualifies only when every one of its blocks does.

Reorder is different: it **moves the live node**, so the block's DOM and JS state
survive. A re-render or a reload would discard both.

Every one of these falls back to a full reload if the iframe cannot be reached.

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

After any iframe reload the parent asks the overlay to **re-pin** focus, or the
outline and toolbar would vanish on every autosave. When the pinned element no
longer exists — a section delete that cascaded to a focused child block — the
overlay replies `cb:focus:not-found` and the parent clears the stale form.

A newly inserted section wins over the restored scroll position: it lands at the
end of the area, often below the fold, and restoring the old scroll would hide
the one thing the editor wants to see.

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

- **`response:error`** — the server answered with a non-component response (500,
  expired session). Live's default raw-HTML error modal is suppressed in favour
  of the topbar banner.
- **a network failure** — Live's request promise has no rejection handler at all,
  so the save dies silently. One is attached through `loading.state:started`.

Live also never resets `backendRequest` when its request rejects, so every later
action would queue behind a dead request forever: the component is wedged, and
the editor's retry silently does nothing. It is cleared explicitly.

A failure is dispatched as `cb:save:error` on the form's **autosave wrapper**,
which does two things at once: `cb-autosave` resets its dirty-detection baseline
so the next interaction re-attempts the save rather than treating the failed state
as already saved, and the event bubbles up to show the banner.

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
