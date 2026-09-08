# Builder extensions — actions and shell fragments

## Two halves of one seam

A bundle wanting to add something to the builder needs two things, and the
package splits them because a *host* only needs the first:

| | Gives you | Registered by |
|---|---|---|
| `BuilderActionProviderInterface` | a menu entry in the topbar, and a `cb:builder:action` event when it is clicked | autoconfigured |
| `BuilderShellExtensionInterface` | markup rendered inside the shell — a dialog, a panel, a `<script type="module">` | autoconfigured |

An action provider is the whole contract for a host: it owns the page the builder
is mounted in, so it can listen for the event from anywhere.

**A bundle owns no page.** Its only other ways into the builder window were a
Stimulus controller — which every host must enable by hand, under AssetMapper and
Encore alike — or asking the host to write the listener for it. A shell extension
is the missing half: its fragments render wherever the shell renders, so a bundle
can ship its own `<dialog>`, its own status line and a script pointing at a route
it serves, and the host wires nothing. That script can then listen for the
bundle's own action key, call the bundle's endpoints, and tell the builder to
catch up with a `cb:area:changed` event.

Both are passed the area, so either can decide per-area. **Returning nothing is
how something hides itself** — when the current user may not run it, say.

## The form option and the provider interface are both real

`topbar_actions` on `ContentAreaType` is the seam for a *single form*; the
provider interface is the seam for a *bundle*, which reaches every builder in the
application without the host touching each form. The two are merged, and
`BuilderAction::fromArray()` normalises the form's associative-array shape so a
per-form action and a bundle-provided one are the same thing by the time the
template sees them.

Prefer the option for a one-off, the interface for anything a package ships.

## Ordering and collisions

Both collections order by **descending priority**, ties keeping the order things
came in — providers in service order first, then the form's own entries. A bundle
that needs to sit above or below the host's actions says so with a priority
rather than by hoping about registration order.

Actions **deduplicate by key**, first occurrence winning: a form-level action
cannot silently shadow a provider's, and two providers claiming the same key is a
wiring mistake that should not render twice.

Fragments **do not deduplicate**. They carry no key, and two extensions rendering
the same template is unusual but not a conflict the way two menu entries sharing
a key would be.

## What the package renders

The package renders the entry and nothing else. Clicking dispatches one
`cb:builder:action` DOM event carrying the action's `key`; what the action *does*
is the host's business. That keeps the package free of any opinion about what an
editor might want to do with an area.

A fragment's template is rendered with its own `context` and nothing else — none
of the shell's variables leak in — plus the `area` being edited, which the core
always provides and which a fragment therefore may not declare itself.

`icon` on an action, and a fragment's markup, are **rendered raw**. Both must come
from trusted code; never interpolate user input into either.

## Why the Twig extensions are split

`cb_shell_fragments()` and `cb_image()` each live in their own Twig extension
rather than joining `ContentBlocksExtension`, for the same reason: each depends on
one collaborator, so a test can register it standalone instead of standing up the
renderer, the URL resolver and the palette.

`cb_shell_fragments()` is additionally called by the shell template *itself*,
rather than being threaded in as a variable the way `topbarActions` is. A fragment
has to appear wherever the shell is rendered — through `ContentAreaType` or a
host's direct include of the launcher — since the whole point is that the host
wires nothing.
