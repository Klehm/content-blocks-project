# Action history — Ctrl/Cmd-Z

The editor's rescue between the two that already existed: the delete snackbar
undoes exactly one delete for six seconds, Discard throws away the entire
unpublished draft. Everything in between — a move, a duplicate, a paste, a
settings change, a block edit — was final until it was undone by hand.

`Ctrl/Cmd-Z` walks back through the actions of the current builder session, one
at a time, and `Ctrl/Cmd-Shift-Z` (or `Ctrl-Y`) walks forward again. It is
**draft-scoped like every other builder action**: undoing writes to draft fields
only, so the published page is untouched until Publish, and
[PublishedRenderImmutabilityTest](../../packages/content-blocks/tests/Rendering/PublishedRenderImmutabilityTest.php)
holds undo and redo to that promise alongside the rest.

## The delta is a targeted read

An entry is **not** a snapshot of the area. It is the pair of state assignments
that move the draft between before and after, recorded by reading the same
fields twice around the mutation and diffing them
([AreaStateSnapshot](../../packages/content-blocks/src/History/AreaStateSnapshot.php)).

Diffing rather than declaring is the point. Every mutation would otherwise have
to name the entities it was about to touch, and the two operations where that is
hardest — `paste` and `replace-with` — are exactly the ones whose inverse needs
the ids they *produced*. A diff gets those for free, and a controller that grows
a new side effect cannot forget to declare it.

There are two scopes, because reading everything on every keystroke would be
absurd:

| Scope | Reads | Used by |
|---|---|---|
| `structure` | every section, column and block of the area: `deleted`, `previewPosition`, `layout`, `preset`, a block's column and its published twin | create / move / duplicate / delete / restore, paste, replace-with, template insert, import |
| `blockData` / `sectionSettings` | one entity's `draft_data` or `draft_settings` | the sidebar saves |

### Why structure carries no payload

The structure scope records **where things sit and nothing of what they say**.
That is safe because of how a creation is inverted: not by deleting the row, but
by setting its `deleted` flag — the draft-only flag the builder already uses. The
row stays, so its payload is never at risk and redo is a matter of clearing the
flag again.

It also means the heaviest thing in the model, `block.data`, is read only when a
block edit is what is being recorded.

## What an entry holds

One row of `cb_action_log`: the area, the session, a monotonic `seq`, a label, and
the two op lists. An op is `{t, id, set}` — a type, a row id, and the fields to
assign. Only entities that actually moved appear, and only the fields that
changed, so a two-block reorder is two ops of one field each.

A diff where a row present *before* is missing *after* is **incomplete**: the row
left the draft and nothing can put it back. Recording is skipped and the stack is
emptied, because entries older than a hole cannot be trusted to replay over it
either. No shipped controller does this — draft deletions are soft — but the
guard is cheap and the alternative is a silently wrong undo.

## An entry is applied or refused

Before an undo runs, the draft must still look **exactly** as the entry's
after-state describes it; before a redo, exactly as its before-state does
([StateApplier::matches()](../../packages/content-blocks/src/History/StateApplier.php)).
A missing row counts as a mismatch, not as nothing to do.

This is what the roadmap meant by *refused rather than guessed*. Two editors on
one area, or one editor in two tabs, will eventually undo into a world that has
moved; the check turns that into a sentence in the snackbar instead of a silent
overwrite of someone else's work. It is also narrow: the check only covers the
fields the entry recorded, so a colleague reordering sections elsewhere in the
page does not invalidate your block edit.

One field needs care on the way back. `Block::moveTo()` recomputes
`published_column_id` from where the block currently is, so replaying a column
change has to assign the **recorded** published column afterwards rather than
letting the move guess it — the applier writes `columnId` first and the rest of
the set after it, for that reason alone.

## Whose stack is it

The **HTTP session, hashed**. Not a client-generated id: the sidebar's block
editor is a Live Component, whose requests this package does not compose, so a
custom header would have covered the AJAX controllers and quietly missed every
block edit. The session covers all of it with no client plumbing at all.

Hashed because the raw id is a credential and only its identity is needed.

The trade is that two tabs of the same browser share one stack. That is a real
edge, and the state check above is what keeps it safe rather than merely
unlikely: an entry recorded in one tab and undone from the other only applies if
the draft still matches it.

Without a session — nothing has started one — `record()` runs the mutation and
writes nothing, and the endpoints answer `unavailable`. Undo degrades; nothing
else does.

## Coalescing a run of typing

The sidebar autosaves. A naive journal turns one paragraph into forty undo
steps, so a block-data or section-settings entry carries a `coalesce_key`
(`block.data:42`) and merges into the entry above it when the key matches and the
run is still going: at most `COALESCE_IDLE_SECONDS` since the last merge, and at
most `COALESCE_SPAN_SECONDS` since the run began.

Two ceilings, because one alone fails in a different direction. Idle-only lets
steady typing grow one entry without limit — undo would then discard ten minutes
of work. Span-only splits a run that paused for a coffee into two arbitrary
halves.

Merging keeps the entry's **before** and replaces only its after, which is what
makes the whole run revert to where it started.

## Recording is a wrapper, not a call site

`ActionJournal::record()` takes the mutation as a closure and runs it between the
two reads. Nothing inside the mutation knows about the journal, which is why a
controller's early returns — a refused payload, a no-op reorder, a form that
failed validation — need no special handling: they simply produce an empty diff,
and **an empty diff records nothing**.

The wrapped chokepoints are the AJAX controllers under `/_content-blocks/*`
(`BlocksController`, `SectionsController`, `ClipboardController`,
`ReplaceController`, `SectionTemplateController`, `ImportExportController`,
`SectionSidebarController`) and `BlockComponent::persistDraft()`.

## Where the journal lives

A table, not the builder's memory. The roadmap's own argument decided it: a
refresh is exactly when an editor panics, and an in-memory stack is gone at
precisely that moment.

[ActionLogStoreInterface](../../packages/content-blocks/src/History/ActionLogStoreInterface.php)
separates the stack's storage from the journal's policy — the diffing, the
coalescing, the refusals — so that policy is unit-tested against an in-memory
store rather than against a database or a mock of one.

### Publish and discard end the stack

Publish removes soft-deleted rows and promotes every draft field; discard reverts
them. Either way the draft an entry describes is gone, so
[JournalPruningPublisher](../../packages/content-blocks/src/Publishing/JournalPruningPublisher.php)
empties the area's history — *after* the inner publisher returns, since one that
throws leaves a draft the stack still describes correctly.

It decorates the concrete `ContentAreaPublisher` rather than the interface, so a
host decoration of the interface (which is what `content-blocks-i18n` does) still
wraps this one instead of racing it.

Two more ceilings keep the table from growing: `KEEP_ENTRIES` per (area,
session), and a `RETENTION_DAYS` window that sweeps what a session which never
published left behind.

## The keyboard side

`Ctrl/Cmd-Z` is relayed from the preview iframe rather than handled there,
because the stack belongs to the parent window — see
[frontend.md](frontend.md#keyboard-and-clipboard). The chord table lives in one
place (`shortcutIntent`) so the shell and the relayed path cannot drift.
`Ctrl-Shift-V` stays the browser's paste-as-plain-text: only `z` answers to Shift.

The snackbar — the same one the delete offer and the copy acknowledgement share
— is where every refusal is said out loud.

### The buttons and their state

The chord is not the only way in, unlike the clipboard's. Three things make undo
different from copy: a greyed button answers "is there anything to undo?" before
the gesture, a visible pair is how the feature is discovered at all, and on a
touch device there is no `Ctrl-Z` to press. Both buttons carry their chord in the
`title`, which makes the pair the place the shortcut is learned.

They are **greyed, never hidden** — a control that vanishes when idle cannot
teach anyone it exists. That is the opposite of the Discard button beside them,
which is hidden until there is something to revert, because it is destructive and
only ever wanted deliberately.

Their state is mirrored, never computed twice. `_applyDraftState` is the one
place every mutation already passes through, and a mutation means exactly one
thing for the stack: something to undo, and no future left to redo — which is
`dropUndone()` on the server, expressed client-side. Undo and redo pass the real
counts instead, refusals included: "nothing to undo" is precisely what the button
needs to go grey. So no other endpoint had to grow a payload.

The starting state is the exception, and it has to come from the server: the
stack is a table and survives a reload, so a fresh page can legitimately open
with an undo available. `cb_history_state(area)` reads it, called by the shell
itself rather than passed in — the same reason `cb_shell_fragments` is, so a host
that includes `launcher.html.twig` directly still gets it right.

### Why "Revert to published", not "Discard changes"

The Discard button used to read "Annuler les modifications" / "Discard changes".
Beside an undo arrow that is the same verb for two unrelated scopes — one step of
this session against every unpublished change since the last publish — and it
read as "undo, but bigger".

It now names its destination instead of its action: **Revert to published**. That
is also the more honest description. It does not undo anything in the stack's
sense; it takes the area back to what is live.

### Undo yields less than copy

Copy and paste stand back from any focused field and from a live text selection:
stealing a real copy is worse than not having the shortcut. Undo was given the
same rule at first, and that was wrong by a wide margin. It made `Ctrl-Z` dead
inside a `<select>` or a colour swatch — the editor had to blur the field before
the chord did anything, which is not a rule anyone can guess.

So `_hasNativeUndo` asks the narrower question: does this element have an undo
stack of its own to step on? A textarea, a text input and a contenteditable do —
TinyMCE and CKEditor are the ones that matter, and both are contenteditable.
A `<select>`, a colour swatch, a range and a checkbox do not, so the chord is
the builder's there. The list is written the other way round, as the input types
that take *no* typing, so an unknown or future type is assumed to take some and
the chord stays the browser's.

A text selection no longer blocks undo either. There is nothing to steal: a
selection inside an editable is caught by the active-element test above, and one
over static preview text has no undo of its own.

### The open edit is committed first

Widening the chord created a race the blur requirement used to hide. A colour
picked and undone within the autosave's debounce window would have undone the
*previous* action, and then the pending save would have landed on top of it.

So `_runHistory` flushes first: it clicks the open form's save through
`cb-autosave`'s `flush()` and waits for the save's own event before posting the
undo. A flush that saved nothing does not wait, and a save that never answers
gives up after `SAVE_FLUSH_TIMEOUT_MS` — a stuck save must not cost the editor
their `Ctrl-Z`.

### The sidebar survives an undo when it can

Resetting the sidebar on every undo was the safe thing to do and too blunt to
live with: undoing a move three sections away closed the form the editor was
working in, losing their place and their caret.

The reset was there for a real hazard, though — the undone entry may have
deleted the very row the sidebar has open, and a form left showing pre-undo
values autosaves them straight back on the next keystroke. So the decision is
made per undo rather than assumed, and it is made **server-side**: the builder
sends what it has open, and `SidebarOutcome` answers one of three verdicts.

| Verdict | When | What the builder does |
|---|---|---|
| `keep` | the applied ops name nothing the open form renders | nothing at all — caret included |
| `reload` | they moved one of its own fields | refetch the form, then restore focus by field name |
| `close` | the row is gone, draft-deleted, or under a deleted ancestor | reset to the empty state |

Two things make this the server's call rather than the client's. Ancestry is one:
a section delete marks the section, not its blocks, so a block's sidebar has to
close on a step that never names the block. Which fields a form actually renders
is the other — `previewPosition` moving is not worth a repaint, while a column's
`preset` is, because `columnWidths` is one of the section form's own fields.

An answer carrying no verdict closes, so an older server degrades to the old
behaviour rather than to a stale form.
