# Roadmap

Planned and under-consideration work for ContentBlocks. This is a living document — items here are directions, not commitments, and may change. Shipped items move to the packages' `CHANGELOG.md`.

Legend: 🅿️ planned · 🤔 under consideration · 💡 idea

---

## Kit — image optimization seam 🤔

**Context.** The kit is deliberately dependency-free: the `image` block serves the uploaded file **as-is** and only controls the *display* box via CSS (`width`/`height`, `object-fit`, `border-radius`, `loading="lazy"`). It already does the free wins (no CLS, lazy loading), but it does **not** reduce byte size — there is no responsive `srcset`, no WebP/AVIF, no server-side thumbnails. That inherently requires either an image-processing library (LiipImagine/Glide/GD/Imagick) or a transforming CDN, neither of which the kit should hard-depend on.

**Direction.** Add a *seam*, not a dependency — mirroring the project's existing "interface + passthrough default" pattern (`FileStorageInterface`, `AccessCheckerInterface`, `ContentAreaUrlResolverInterface`):

- Introduce `ImageUrlResolverInterface::resolve(string $src, ?int $width, ?int $height): ResolvedImage` where `ResolvedImage` carries `{ src, srcset?, sizes? }`.
- Ship a default `PassthroughImageUrlResolver` returning `src` unchanged → **the current zero-dependency behavior, unchanged and backward-compatible**.
- The `image` view template calls the resolver and emits `srcset`/`sizes` only when the resolver provides them. The kit **already computes** the target display widths (sm=400, md=800, lg=1200, custom) — exactly the input a resolver needs.

**Opt-in optimization then lives outside the kit's `require`:**
- **CDN** (Cloudflare Images, imgix, Cloudinary) — the resolver just builds URLs with transform params. Zero PHP processing dependency; likely the best "optimized, turnkey" path for production hosts.
- **LiipImagine / Glide** — a thin bridge, shipped as a separate package (e.g. `klehm/content-blocks-kit-liip`) or a documented recipe + composer `suggest`. Never a hard dependency.

**Why this shape.** Keeps the kit's "self-contained, drops into any host" promise intact while unblocking real byte-size optimization as a one-line host opt-in. The plumbing (interface + passthrough default + `srcset` in the template) is small, dependency-free, and non-breaking, and can land before any concrete adapter exists.

**Rough scope when picked up:**
- [ ] `ImageUrlResolverInterface` + `ResolvedImage` value object (kit or core)
- [ ] `PassthroughImageUrlResolver` default + autowiring
- [ ] Route the computed display width/height through the resolver in `image/view.html.twig`, render `srcset`/`sizes`
- [ ] Unit test: passthrough emits today's markup exactly; a fake resolver emits `srcset`
- [ ] Recipe: wire a CDN resolver · Recipe/bridge: LiipImagine

---

## Core — per-block form extension API 🅿️

**Context.** A host cannot cleanly add a field to *one* existing block's edit form. `BlockFormType` calls `$blockType->buildForm()` on a single shared top-level builder, so **every** block uses one form type/prefix (`content_block`). A stock Symfony `FormTypeExtension` therefore matches by class and fires for **all** blocks — it can only be scoped to one block by a runtime `instanceof` guard, which is a workaround, not real per-block extension. Introducing a per-block *sub-type* wouldn't help either: Symfony extension matching is by type class, so sub-types sharing a class still can't be distinguished.

**Direction.** Add a ContentBlocks-native seam (mirroring the existing decorator collections), not a twist on Symfony's mechanism:

- A `BlockFormExtensionInterface` declaring **which blocks it targets** — a list of block type ids (`['button', 'card']`) or a wildcard `'*'` for global — plus `buildForm(FormBuilderInterface $builder, array $data, string $blockType): void`.
- `BlockFormType` (or a small collection) invokes, after the block's own `buildForm()`, every tagged extension that supports the current block type.
- Ergonomic attribute: `#[AsBlockFormExtension('button')]` (per block) or `#[AsBlockFormExtension]` (global). Autoconfigured, `priority`-ordered like the other collections.

**One mechanism covers both needs:** global = the extension supports `'*'`; targeted = it lists specific ids. No `instanceof` guard, no forking.

**Why this shape.**
- Consistent with the project's DNA — `SectionDecoratorInterface`, `BlockDecoratorInterface`, `SectionSettingsDefaultsProviderInterface` are all tagged/autoconfigured collections.
- **Keyed by block type id (string), not class** — survives subclassing and matches the config keys (`content_blocks_kit.blocks.<type>`).
- Backward-compatible: no impact on existing blocks; the added field's value persists as-is (block data isn't pruned) and still renders via a host template override.

This is a small, high-leverage addition to the customization API — the current subclass + `instanceof` recipe is the workaround until it lands.

**Rough scope when picked up:**
- [ ] `BlockFormExtensionInterface` + `#[AsBlockFormExtension]` attribute + autoconfigure tag
- [ ] Collection invoked from `BlockFormType::buildForm()`, filtered by supported block type (with `'*'` wildcard), `priority`-ordered
- [ ] Unit tests: global extension hits every block; a targeted one hits only its type; ordering honored
- [ ] Update the [Add a field to a block](https://klehm.github.io/content-blocks-project/guide/recipes/add-block-field) recipe to lead with this API (subclass approach becomes the fallback)

---

## Core — Flex recipe for asset wiring 🅿️

The Stimulus controllers + admin CSS (`assets/controllers.json`) and the `sortablejs` importmap entry are currently a manual install step (documented in [Installation](https://klehm.github.io/content-blocks-project/guide/installation)). A Symfony Flex recipe that injects this automatically is planned — once published, the manual step goes away.

---

## Adding to this roadmap

Keep entries outcome-oriented: what problem, what direction, and (for larger ones) a rough scope checklist. Move an item to the relevant package `CHANGELOG.md` when it ships, and delete it here.
