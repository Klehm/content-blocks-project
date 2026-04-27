# ContentBlocks — Page Builder Modulaire pour Symfony

ContentBlocks est un page builder modulaire pour Symfony. Il permet de construire des zones de contenu composées de sections, colonnes et blocs, avec une architecture extensible.

## Packages

| Package | Description | Install |
|---|---|---|
| [`klehm/content-blocks`](packages/content-blocks/) | Entités, UI admin, formulaires, controllers Stimulus | `composer require klehm/content-blocks` |
| [`klehm/content-blocks-kit`](packages/content-blocks-kit/) | Blocs par défaut (Text, Title, Image) | `composer require klehm/content-blocks-kit` |

## Structure Monorepo

```
content-blocks/
├── packages/
│   ├── content-blocks/              # Package principal
│   └── content-blocks-kit/          # Blocs par défaut
├── apps/
│   ├── content-blocks-sandbox/      # App Symfony de dev/test
│   └── content-blocks-sylius-sandbox/  # App Sylius de dev/test
└── composer.json
```

Ce projet est un **monorepo**. Chaque package est publié séparément sur Packagist via [splitsh/lite](https://github.com/splitsh/lite). Les repos read-only sont générés automatiquement par la CI :

- `klehm/content-blocks` → miroir de `packages/content-blocks/`
- `klehm/content-blocks-kit` → miroir de `packages/content-blocks-kit/`

## Prérequis

- PHP >= 8.2 avec `pdo_mysql`
- MySQL 8.0+
- Symfony 7.x
- Node.js >= 18 (pour les tests JS)

## Contribuer

### Installation

```bash
git clone https://github.com/klehm/content-blocks-project.git
cd content-blocks-project

# PHP : installer les dépendances (packages liés en symlink)
cd apps/content-blocks-sandbox
composer install

# Base de données
cp .env .env.local  # configurer DATABASE_URL
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:schema:create

# JS : installer les dépendances de test
cd ../../packages/content-blocks
npm install
```

### Lancer la sandbox

```bash
cd apps/content-blocks-sandbox
php bin/console asset-map:compile
php -S 127.0.0.1:8000 -t public
# → http://127.0.0.1:8000
```

### Lancer les tests

```bash
cd packages/content-blocks

# Tests JS unitaires (Vitest)
npm run test:unit

# Tests JS E2E (Playwright — démarre la sandbox automatiquement)
npm run test:e2e

# Tous les tests JS
npm test
```

## Architecture UI

Le page builder utilise un mix **Live Components + Stimulus** :

- **Live Components** pour le CRUD serveur (ajout/suppression de sections et blocs)
- **Stimulus** pour le contrôle DOM (drag & drop, réordonnancement, intégration d'éditeurs JS tiers)

> **Règle** : ne pas utiliser de LiveAction pour des opérations qui réordonnent des composants Live enfants (limitation morphdom/Idiomorph).

## Licence

MIT
