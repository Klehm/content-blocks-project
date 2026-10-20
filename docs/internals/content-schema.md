# Content schema

Maintainer notes on `ContentBlocks\Schema`. The integrator guide is [docs/guide/content-schema.md](../guide/content-schema.md); the direction is in `ROADMAP.md`, "A content schema for AI".

## Derived from the form

A block's data is validated by its form (`BlockComponent::persistDraft()`, `BlockDataReplayer`). The schema is read off that same form, built the way the replay builds it: `BlockFormType` over the type's defaults merged with `BlockDataDefaults`. A hand-written schema would drift from the form the first time a field changed; one read from the form cannot.

`FormSchemaMapper` walks the built form, not the builder, because a choice field's values only exist once its choice list is resolved (`choice_list` attribute). It reads the **model** values (`2`, not the `"2"` the browser posts), since the schema describes stored data.

Rules worth knowing before changing it:

- **Contributors before the generic walk.** A type with its own data mapper (`PaletteColorType`) has children that are not its stored shape. Walking them would describe `{palette, custom}` instead of a string.
- **A plain `HiddenType` is skipped**, because what the editor cannot see is not the author's to write (`columnWidths`). A type *built on* `HiddenType` (the upload fields) goes to its contributor.
- **A collection without a prototype** (UX's `LiveCollectionType` builds none) gets an entry built alone from `entry_type` + `entry_options`.
- **An optional choice takes its empty value**, whatever the field's empty data is, or the field's own default would sit outside its enum (the sandbox's `rel` field).
- **A null default is left out**: `"type": "integer", "default": null` contradicts itself.
- **A `Choice` constraint narrows, never widens.** The kit's constraint is the union of the coded and offered values on purpose (`kit.md#why-the-constraint-is-a-union`); the enum is the field's offered set, intersected with the constraint.
- **Untranslated keys are not titles.** A label that comes back from the translator unchanged and looks like a key (`cb_kit.block.title.field.text`) is dropped. The property name says more.

Keys a type declares in `getDefaultData()` without a field are added, typed from their default value. The replay keeps them verbatim, so they are part of the data.

## One document, two halves

The document has a vocabulary (`layouts`, `palette`, `sectionStyles`, `blocks`, `examples`) and a pure JSON Schema (`schema`). Keeping them apart keeps the JSON Schema free of non-standard keywords: providers' structured-output modes reject keywords they do not know, and the vocabulary is prose a model reads, not constraints it is held to.

Shared subtrees are written once under `$defs` (`blockStyling`, `sectionSettings`), so the 19 kit blocks do not each repeat 2 KB of spacing fields. The block union is a `oneOf` on `type` (`const`), one `$defs` entry per type.

## Examples from the section library

`SectionTemplateExamples` reads `cb_section_template` latest first and scans four times the limit, since unusable rows are skipped:

- another `content_version`, or `NULL`: the upgrader would refuse it on the way in, so it would teach a refused shape;
- another payload `format`;
- a block type this app no longer registers.

Reserved keys (`_id`, `_order`, `_lang`…) are stripped recursively: the schema declares none, and an example contradicting the schema would teach the model to break it. Under `sections: hidden` no examples are carried, since a saved section is a section.

## What is deliberately absent

- **No screenshots.** They go stale with the CSS and teach little a purpose does not. A visual review belongs to the generation loop (render with `cb_chrome=0`, capture, critique).
- **No preset settings.** A model picks a preset by `styleName`; repeating its settings would invite it to copy them into `styling` and lose the preset.
- **No way back in yet.** The schema describes the `section-v1` payload the paste path replays. Writing a generated page to the draft is the next step of the roadmap entry.
