# AI actions

Maintainer notes on `ContentBlocks\Ai` and `assets/builder/ai.js`. The integrator guide is [docs/guide/ai.md](../guide/ai.md); the schema the actions build on is [content-schema.md](content-schema.md).

## In the core, not a satellite

Translation is a satellite because it owns tables. The AI actions own none: they are a seam, a writer, three endpoints and a builder feature, and the writer is the paste path. A satellite package would have had to reach into the builder's sidebar and topbar from outside. Without a provider, everything is inert: no button, the endpoints answer `404`, and no request leaves the server.

## Two switches per action

`AiFeatures::isEnabled()` is the single answer, read by the endpoints, the Twig functions and the form extension:

- the host's configuration, `content_blocks.ai.actions.<action>`, which is static;
- the provider's `supports()`, which may vary per request (the sandbox's reads a cookie, so e2e can pin a refusal).

A disabled action answers `404 {"error": "disabled"}`, not `403`: from the outside it does not exist. `apply` calls no model, so only the configuration gates it.

## The way back in

`GeneratedContentWriter` turns `{"sections": [...]}` into draft content through `ClipboardPaster`, so a generated block meets exactly what a pasted one meets: `RestoredSectionBuilder` for the structure, `BlockDataReplayer` for the data. What the writer adds on top:

- **`format` is filled in.** Models drop constants; refusing a whole page for a missing `content-blocks/section-v1` would be pedantry.
- **Section settings are whitelisted by key**, against the children of `SectionSettingsType`. A paste keeps settings verbatim and leaves their values to the render-time guards (`CssColor::safe()`, the layout registry). A model invents keys more freely than a clipboard, so the keys go through a whitelist too.
- **Structure modes.** `fixed` refuses: a generation adds sections, which this mode forbids. `hidden` flattens the document into blocks appended to the area's one column (`AppendTarget`), because a model told "blocks only" still answers in sections.
- **A section with no surviving block is dropped**, and the result is refused as `empty` only when nothing at all survives. A page with one bad section out of five is four good sections.
- **A revision goes in before its section goes out.** `replacing` pastes the answer right after the target and deletes the target only once something survived. A refused answer (`empty`) leaves the section exactly where it was.
- **Replace is a draft delete** (`ContentManipulator::deleteSection()`), so *Revert to published* brings the sections back and the published render never moves. `PublishedRenderImmutabilityTest` and the e2e spec carry the case.

The controller wraps the write in `ActionJournal::record()`, so a generation is one undo step, and flushes inside the closure so the diff sees the ids it produced.

## Field context

A text request names the open block and the field's path (`items.0.title`, read from the input's `name` by `aiFieldPath()`). The controller resolves the block *against the area in the URL*, as paste does, then asks `ContentSchemaBuilder::fieldSchema()` for that field's `maxLength` and label. A forged block id from another area gives no context. It does not fail, since context is a hint.

## The builder side

- **The ✦ trigger is server-rendered** (`_cb_ai_trigger` in `cb_form_theme`), inside the Live Component's markup, so a re-render keeps it. Injecting it from JS would lose it at every morph.
- **The popover lives outside the Live Component** (`ai.html.twig`, a child of the shell), so a re-render of the form during a slow request cannot remove it. A sequence number drops an answer that arrives after the popover closed.
- **A proposal is shown as text**, even for an HTML field: the model's markup is not trusted in the builder's own document.
- **Writing the value goes through `cb:field:set`.** A rich-text editor owning the textarea cancels the event and sets its own content, otherwise its next sync would put the old text back. Without a taker, the value is set and `input` + `change` are dispatched: a value set from JS fires no event, so Live would post the stale one and its morph would revert the field.
- **Text requests bypass the mutation queue** (`_performJsonRequest`): they write nothing, and a slow model must not hold up the editor's saves. Generation goes through the queue like any structural change.
- **The section toolbar's ✦ comes from the builder, not the render template.** The overlay learns about it from a `cb:ai:capabilities` message the builder posts after each `cb:ready`, so the public render template knows nothing about AI. Its label still goes through `cb-overlay-labels`, like every toolbar title (`PreviewOverlayLabelsTest`). A click posts `cb:ai:section-requested`, which opens the dialog on that section. The target lives only as long as the dialog.
- **`.cb-ai` is `display: contents`.** It is a data carrier among the shell's grid children; as a box it took a grid row.
