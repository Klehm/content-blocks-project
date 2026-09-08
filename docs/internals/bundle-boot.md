# Bundle boot — prepends, autoconfiguration, compiler passes

## The AssetMapper prepend

`prependExtensionConfig('framework', ['asset_mapper' => ['paths' => …]])` looks
harmless and is not.

`framework.asset_mapper` is declared with `canBeEnabled()`, whose normalization
turns **any non-empty array into `enabled: true`**. So prepending `paths` on a
host that builds with Webpack Encore — no `symfony/asset-mapper` installed, and
this package deliberately does not require it — would *enable* the component and
make `FrameworkExtension` throw at boot.

Hence the `class_exists(AssetMapper::class)` guard. Encore hosts read the same
controllers out of `assets/package.json` through `@symfony/stimulus-bridge`
instead.

This is the bug that motivated the second Playwright suite: it lived for a long
time without any test seeing it, which is why
`content-blocks-encore-sandbox` puts `symfony/asset-mapper` in its Composer
`conflict` — so that leg can never quietly re-derive onto the path already
covered.

## The other two prepends

The **form theme** is prepended so `form_row(form.contentArea)` renders the
builder out of the box. The `@ContentBlocks` namespace itself is auto-detected by
`AbstractBundle` from `<BundleRoot>/templates/`, which is also what gives
`templates/bundles/ContentBlocksBundle/` priority for host overrides.

The **Twig Component namespace** is prepended so `cache:clear` does not fail on a
missing namespace right after `composer require`. `ux-twig-component` is a hard
dependency, so the extension is always loaded and the prepend is always safe.

## Autoconfiguration

Host implementations of the extension points are globally auto-tagged, so a host
needs nothing beyond `autoconfigure: true` in its own `services.yaml`.

Priority is only load-bearing in two places, and for opposite reasons:

- **`BlockDataResolverInterface`** — the chain threads *one payload* through
  every resolver, so an implementation that must run before the shipped seeding
  step declares `priority` on the tag explicitly. See
  [rendering.md](rendering.md#resolving-what-a-block-renders).
- **`AsContentBlock`** — the registry's insertion order is what the block-picker
  grid renders. See [blocks.md](blocks.md#registration-order-is-the-picker-order).

Everywhere else — decorators, style providers, defaults providers — order affects
only merge precedence, which each collection documents on its own terms.

`AsBlockFormExtension` is the one attribute carrying data beyond "tag me": the
targeted block type ids and a priority. `BlockFormExtensionPass` pairs each
service with its ids so a host writes a single attribute and implements only
`buildForm()`. See [forms.md](forms.md#why-form-extensions-are-a-package-seam).

## Doctrine listeners are tagged, not attributed

`ContentAreaTouchListener` is registered with the `doctrine.event_listener` tag
in `services.php` rather than `#[AsDoctrineListener]`, so the package needs no
hard dependency on DoctrineBundle.
