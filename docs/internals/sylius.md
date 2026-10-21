# Sylius plugin (klehm/content-blocks-sylius)

The plugin makes the core and the kit work in a Sylius 2 shop with nothing for
the integrator to write: CMS pages, builder content on native shop pages,
catalog blocks, Bootstrap markup for the kit, and the host services the core
requires (`AccessCheckerInterface`, `ContentAreaUrlResolverInterface`).
Supported: Sylius 2.0 to 2.3, each minor tested by CI on the `sylius/test-application`
of the same line.

## Who may edit

`AdminAccessChecker` grants every area to a shop administrator
(`ROLE_ADMINISTRATION_ACCESS`). Sylius has no per-resource rights, so checking
ownership would only have refused areas a host attaches to its own entities.

The admin is not always the user of the current firewall. The builder's preview
iframe is a shop page and Live components post to `/_components`, both on the
`shop` firewall, while the administrator logged in on `admin`, a different
firewall context. `AdminVisitor` therefore reads the admin token from the
session (`_security_admin`) when the current token is not an admin's. That is
the approach the first Sylius integration (bocklip) proved in production; the
alternative, sharing one firewall context between admin and shop, changes the
host's security model and was not the plugin's call.

## Native pages

A *slot* (`ContentSlot`, table `cb_sylius_slot`) attaches an area to a page
Sylius renders itself: a channel's homepage, a product, a taxon. It is keyed by
type and **code** (channel code, product code, taxon code), not by a foreign
key, so no Sylius model is overridden and a host with its own product class
needs nothing. The price is that deleting a product leaves its slot behind; it
is harmless (nothing renders it) and keeps the content if the code comes back.

Opening a slot's admin screen writes nothing, the same rule as
`ContentAreaType` (no row per GET): the slot and its area are created by an
explicit POST, CSRF-protected.

On the shop, a slot renders **nothing at all** until it has a published
section, so the page stays exactly as Sylius made it. In preview it always
renders, empty, so the builder has its chrome to add the first section.

Product and taxon content are hookables added to Sylius's own hooks
(`sylius_shop.product.show.content` between the details and the associations,
`sylius_shop.product.index.content.body.main` between the header and the
filters). The taxon listing names its taxon only by the route's slug, so
`cb_sylius_taxon_slot()` resolves it from the request.

## The homepage replaces a hook

The homepage is different: content there should *replace* the native sections
(banner, latest products…), and Twig hooks have no runtime condition — a
hookable is enabled or not at compile time. `HomepageHookRenderer` decorates
`sylius_twig_hooks.renderer.hook` and, when the hook being rendered is
`sylius_shop.homepage.index` and the channel's slot has something to show,
returns the area instead of the hook's hookables. An empty draft in preview
renders the builder chrome *above* the native sections, so an editor starting
from scratch sees what they are about to replace.

Decorating the renderer was preferred to moving the native hookables into a
fallback hook through prepended config: that would have copied Sylius's
homepage configuration into the plugin, and drifted at the first minor that
changes it.

## Catalog blocks

The blocks store **codes** (product, taxon) and read the catalog at render time
through `Catalog`, for the current channel and locale. A disabled product, or
one not sold in the channel, is left out silently; an editor sees a dashed
placeholder in preview instead. Prices, promotions and stock are therefore
always the shop's current ones, and the data survives an export/import and a
reindexed database.

Rendering reuses the shop's own components (`sylius_shop:product:card`,
`sylius_shop:product:price`, `sylius_shop:product:add_to_cart_form`) so the
markup, the catalog promotions and the variant logic are Sylius's, whatever the
minor. The add-to-cart form is the Live component with the plugin's wrapper
template: the native wrapper reaches its parts through a *relative* hook, which
only resolves inside the product page, so the plugin's copy calls the hook by
its absolute name with `_prefixes: []`. The parts (variants, quantity, submit)
stay Sylius's.

The pickers are `CatalogCodeType`, a `ChoiceType` whose values are codes, not
Sylius's `ProductAutocompleteType`. UX Autocomplete looks submitted values up by
primary key, while Sylius's types publish codes as values, so the Sylius type
cannot round-trip a code through a submit. The plugin's loader only ever loads
the codes a field holds or is sent (`CatalogCodeLoader`), and searching goes to
Sylius's existing endpoint (`sylius_admin_entity_autocomplete`) with
`extra_options.choice_value: code` signed into the URL, which Sylius's
`TranslatableAutocompleteType` honours.

## Bootstrap markup for the kit

The Sylius 2 shop is Bootstrap 5. `kit_markup: bootstrap` (the default) serves
the plugin's Bootstrap variant of a kit view instead of the kit's own, through
`BootstrapKitTemplateLoader`, a Twig loader ahead of the filesystem one. The
variants keep the kit's data contract line for line (same fields, same
`cb_lang`, same `cb_kit_safe_url`); only classes change.

A loader rather than a prepended Twig path: a prepended path would sit ahead of
the host's `templates/bundles/ContentBlocksKitBundle/` and silently disable its
overrides (the trap i18n fell into once). The loader checks that directory
first and steps aside when the host has its own file. Blocks without a variant
(text, rich text, tabs, gallery, video, icon, html_raw) keep the kit's markup,
so `kit.css` is still linked.

## Testing

The package boots a real Sylius application in PHPUnit:
`sylius/test-application` is the kernel, the plugin adds itself through
`BUNDLES_TO_ENABLE` / `CONFIGS_TO_IMPORT` / `ROUTES_TO_IMPORT`
(`tests/TestApplication/.env`). The bootstrap recreates the schema once per
run; each test runs in a transaction rolled back at the end, the browser never
rebooted so it shares that connection. Fixtures go through Sylius's own
factories (`tests/Integration/ShopFixtures.php`). Encore's `strict_mode: false`
lets the shop and the admin render without a `yarn build`.

The CI job installs each Sylius minor with the test application of the same
line, because the test application's config follows its Sylius version.
