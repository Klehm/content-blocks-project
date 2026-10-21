# ContentBlocks for Sylius

A Sylius 2 plugin (2.0 to 2.3) bringing the [ContentBlocks](https://github.com/klehm/content-blocks)
page builder to the shop:

- **Pages**: a *Content → Pages* admin section, translated per locale (title,
  slug, meta), shown on the channels you choose, at `/{locale}/pages/{slug}`
- **The homepage**: each channel's homepage built with the builder; the native
  one stays until something is published
- **Product and taxon content**, below the product details or above a taxon's
  products
- **Catalog blocks**: product card, products (grid or carousel), price, add to
  cart, categories, reading prices and stock at render time
- **Bootstrap markup** for the kit blocks, like the Sylius shop theme

```bash
composer require klehm/content-blocks-sylius
```

```yaml
# config/routes/content_blocks.yaml
content_blocks_sylius:
    resource: '@ContentBlocksSyliusPlugin/config/routes.yaml'
```

Then add the builder's Stimulus controllers to the admin build and generate a
migration. Full guide: [Sylius 2](https://klehm.github.io/content-blocks-project/guide/sylius).

## Configuration

```yaml
# config/packages/content_blocks_sylius.yaml
content_blocks_sylius:
    kit_markup: bootstrap   # or `kit`
    slots: { homepage: true, product: true, taxon: true }
```

## Tests

The plugin boots a real Sylius application (`sylius/test-application`) in
PHPUnit, against MySQL:

```bash
composer install
echo 'DATABASE_URL=mysql://root:secret@127.0.0.1/content_blocks_sylius_test?serverVersion=8.0' \
    > tests/TestApplication/.env.test.local
vendor/bin/phpunit
```

The bootstrap recreates that database on each run.

## License

MIT
