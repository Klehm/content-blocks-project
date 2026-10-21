# Sylius shop sandbox

A Sylius-Standard 2.3 shop with `klehm/content-blocks-sylius`: the demo of the
plugin and the fixture of the Sylius Playwright suite. The packages come from
`../../packages` through Composer path repositories.

```bash
make up DEMO=shop      # from the monorepo root: install, seed, serve on :8006
```

The first `up` loads Sylius's `default` fixtures, then `app:demo:load`: the
Fashion homepage built with the builder, an *About us* page in English and
French, a size guide on the *Bold Beach Cap*. Admin: `/admin`, `sylius` /
`sylius`.

By hand:

```bash
composer install
echo 'DATABASE_URL="mysql://root:secret@127.0.0.1:3306/content_blocks_sylius_shop?serverVersion=8.0"' > .env.local
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
php bin/console sylius:fixtures:load default -n
php bin/console app:demo:load
npm install && npm run build
php -S 127.0.0.1:8006 -t public public/router.php
```

What differs from a fresh Sylius-Standard, and why:

- `config/routes/content_blocks.yaml`, `config/packages/content_blocks.yaml`
  and the plugin in `config/bundles.php`: the install steps of the guide.
- `package.json` and `assets/controllers.json`: written by Flex from the
  packages' `assets/package.json`.
- `webpack.config.mjs` adds this app's `node_modules` to `resolve.modules`, and
  `package.json` pins webpack: monorepo artefacts (symlinked packages, a
  webpack newer than `@symfony/stimulus-bridge` supports), see the comments.
- `assets/shop/styles/content-blocks.css`: the space between blocks, which the
  kit leaves to the host.
- `SANDBOX_SUFFIX` (database and uploads): the Playwright instance runs on its
  own `_e2e` copy.
