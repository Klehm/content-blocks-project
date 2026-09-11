# Internals

Maintainer documentation: the *why* behind the implementation — the bug a design
prevents, the trap the next reader would step in, the option that was rejected
and what it cost.

This is **not** part of the published site (`srcExclude` in
[.vitepress/config.mjs](../.vitepress/config.mjs)). Integrator-facing material
belongs in [../guide/](../guide/) instead; anything here is for people editing
the packages.

It exists because the same rationale used to be written four or five times over
— once in a source docblock, once in `docs/`, once in `CLAUDE.md`, once in a
CHANGELOG entry, once in test names — and the source copy was always the
longest. Five copies drift, and a docblock that lies is worse than one that is
absent. One home, pointers from everywhere else. The pointers are `@see`
annotations in the code; the budget that keeps them short is in
[CLAUDE.md](../../CLAUDE.md) under *Conventions → Comments*.

## Pages

| Page | Covers |
|---|---|
| [rendering.md](rendering.md) | `BlockRenderer`, render modes, the published/draft split at render time |
| [publishing.md](publishing.md) | Publish / Discard, the draft twins, `PublishContext` |
| [clipboard.md](clipboard.md) | Copy / paste, the untrusted payload, `BlockDataReplayer` |
| [assets.md](assets.md) | Upload, storage, reference detection, the GC |
| [forms.md](forms.md) | Block forms as the whitelist, form extensions, styling types |
| [blocks.md](blocks.md) | `BlockTypeInterface`, view templates, hot reload, preview hints |
| [versioning.md](versioning.md) | Content version vs envelope format, and who owns which |
| [builder-extensions.md](builder-extensions.md) | Topbar actions and shell fragments |
| [bundle-boot.md](bundle-boot.md) | Prepends, autoconfiguration, compiler passes |
| [section-templates.md](section-templates.md) | The section library, snapshots, posters |
| [transfer.md](transfer.md) | Area export / import, the payload format, embedded assets |
| [worker-mode.md](worker-mode.md) | Cross-request state, what may be cached on a service |
| [i18n.md](i18n.md) | The translation satellite: storage, staleness, the workbench |
| [kit.md](kit.md) | Kit block conventions, the config surface, rich-text editors |
| [history.md](history.md) | The undo stack: the delta, the refusals, coalescing |
| [frontend.md](frontend.md) | Stimulus controllers, the `cb:*` event contract, the preview bridge |
