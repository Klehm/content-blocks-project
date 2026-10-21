# Sylius 2

[`klehm/content-blocks-sylius`](https://github.com/klehm/content-blocks-sylius)
is a Sylius plugin bringing the builder to a Sylius 2 shop (2.0 to 2.3):

- **Pages**: a *Content → Pages* admin section, pages translated per Sylius
  locale (title, slug, meta), shown on the channels you tick, at
  `/{locale}/pages/{slug}`.
- **The homepage**: build each channel's homepage with the builder. Until
  something is published, the native Sylius homepage stays.
- **Product and taxon content**: a *Page builder* button on the product and
  taxon edit screens; the content shows below the product details, or above the
  products of a taxon.
- **Catalog blocks**, in a *Shop* group of the block picker: product card,
  products (grid or carousel), price, add to cart, categories.
- **Bootstrap markup** for the kit blocks, like the Sylius shop theme.

## Installation

```bash
composer require klehm/content-blocks-sylius
```

Flex registers the bundles. Without Flex, add to `config/bundles.php`:

```php
ContentBlocks\ContentBlocksBundle::class => ['all' => true],
ContentBlocks\Kit\ContentBlocksKitBundle::class => ['all' => true],
ContentBlocks\Sylius\ContentBlocksSyliusPlugin::class => ['all' => true],
```

Import the routes in `config/routes/content_blocks.yaml`:

```yaml
content_blocks_sylius:
    resource: '@ContentBlocksSyliusPlugin/config/routes.yaml'
```

It mounts the admin screens and the builder under the admin prefix
(`/%sylius_admin.path_name%`, so the admin firewall covers them), the pages
under `/{_locale}/pages/`, and the public CSS/JS routes.

Configure uploads (`config/packages/content_blocks.yaml`) and update the
database:

```yaml
content_blocks:
    upload:
        directory: '%kernel.project_dir%/public/media/content-blocks'
        public_prefix: '/media/content-blocks'
```

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

### Admin assets

The builder runs in the Sylius admin, built with Webpack Encore. With Flex,
`composer require` already did the work: it added `@klehm/content-blocks` and
`@klehm/content-blocks-kit` to `package.json` and their Stimulus controllers to
`assets/controllers.json`, which Sylius-Standard's `webpack.config.mjs` merges
into the admin build (the builder stylesheet comes with them). Install and
build:

```bash
npm install
npm run build
```

Without Flex, add the two `file:` dependencies and the controllers by hand,
see [Installation](installation.md#with-webpack-encore).

The builder's sidebar is a Live component: the plugin sends it through Sylius's
admin Live route (`/admin/{_locale}/_components`), so it needs no route of its
own and stays behind the admin firewall.

### Shop stylesheet

Nothing to do: the plugin links the kit stylesheet on every shop page through
the `sylius_shop.base#stylesheets` hook, for the blocks the Bootstrap markup
does not cover (tabs, gallery, video…). To link it yourself instead, disable the
hookable:

```yaml
sylius_twig_hooks:
    hooks:
        'sylius_shop.base#stylesheets':
            content_blocks_kit:
                enabled: false
```

## The homepage

*Content → Homepage* lists the channels. *Edit* opens the channel's homepage
in the builder; the first visit offers *Start editing with the builder*, which
creates the content. As long as nothing is published, visitors see the native
homepage. Once a section is published, the builder content **replaces** the
native homepage sections of that channel.

## Product and taxon pages

The product and taxon edit screens get a *Page builder* button. The content is
**added** to the native page: below the product details, above the products of
the taxon. A product without content shows exactly as before.

## Catalog blocks

| Block | What it shows |
|---|---|
| Product | one product as the shop's product card, optionally with its add-to-cart form |
| Products | chosen products, the products of a taxon or the latest ones, as a grid or a carousel |
| Price | a product's price in the channel, struck-through original price when discounted |
| Add to cart | the shop's add-to-cart form: variants, quantity, button |
| Categories | the sub-taxons of a taxon, or chosen taxons, as tiles |

The blocks store product and taxon **codes**: prices, promotions and stock are
read when the page renders, for the visitor's channel and locale. A disabled
product, or one not sold in the channel, is left out.

They reuse the shop's own Twig components (`sylius_shop:product:card`…), so a
theme overriding those templates restyles the blocks too.

## Configuration

```yaml
# config/packages/content_blocks_sylius.yaml
content_blocks_sylius:
    kit_markup: bootstrap   # or `kit` for the kit's own cb-kit-* markup
    slots:
        homepage: true
        product: true
        taxon: true
```

Your own `templates/bundles/ContentBlocksKitBundle/` overrides still win over
the Bootstrap markup.

## Who can edit

Every shop administrator (`ROLE_ADMINISTRATION_ACCESS`) can edit every page and
slot. For finer rules, alias your own checker:

```yaml
# config/services.yaml
ContentBlocks\Security\AccessCheckerInterface:
    class: App\ContentBlocks\MyAccessChecker
```

## Translating the content

With [`klehm/content-blocks-i18n`](translation.md) installed, the plugin
answers its seams for you: the translation targets are the shop's locales, the
page URLs come from the page and product slugs (for `hreflang`), and the
workbench's back arrow returns to the admin screen. The source language is the
shop's default locale. Import its routes too:

```yaml
content_blocks_sylius_i18n:
    resource: '@ContentBlocksSyliusPlugin/config/routes/i18n.yaml'
```

The page edit screen then shows a *Translate into…* button per locale, with the
progress of each.
