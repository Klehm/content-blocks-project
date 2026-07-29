# ContentBlocks — Page Builder Modulaire pour Symfony

## Objectif

ContentBlocks est un page builder modulaire conçu pour Symfony.
Il permet de construire des zones de contenu composées de sections, colonnes et blocs,
avec une architecture extensible basée sur un système de types de blocs.
L'entité `ContentArea` est un conteneur générique de sections, attachable à n'importe quelle entité applicative (page, produit, catégorie, etc.).

**Vendor Packagist** : `klehm`

## Structure Monorepo

```
content-blocks/
├── packages/
│   ├── content-blocks/          # Package principal : entités, UI admin, formulaires
│   │   ├── src/                 # PHP (Bundle, entités, controllers)
│   │   ├── assets/
│   │   │   ├── controllers/     # Stimulus controllers
│   │   │   └── test/            # Vitest (unit) + Playwright (e2e)
│   │   ├── config/              # Services, routes
│   │   ├── templates/           # Twig components
│   │   ├── composer.json        # PHP deps
│   │   └── package.json         # JS deps (vitest, playwright)
│   └── content-blocks-kit/      # ~17 blocs prêts à l'emploi, autonomes (kit.css)
│
├── apps/
│   ├── content-blocks-sandbox/          # App Symfony de dev/test (fixture Playwright)
│   └── content-blocks-sylius-sandbox/   # App Sylius de dev/test
│
├── composer.json                # Composer racine avec repositories path
└── CLAUDE.md                    # Cette documentation
```

## Publication & Monorepo Split

Ce projet est un **monorepo**. Les packages sont publiés séparément sur Packagist via [splitsh/lite](https://github.com/splitsh/lite).

- **Repo principal** : `github.com/klehm/content-blocks-project` (monorepo, on travaille ici)
- **Repos read-only** (générés automatiquement par la CI) :
  - `github.com/klehm/content-blocks` → miroir de `packages/content-blocks/`
  - `github.com/klehm/content-blocks-kit` → miroir de `packages/content-blocks-kit/`

Les utilisateurs installent via Composer normalement :
```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

Les contributeurs clonent le monorepo et ont tout (packages + sandboxes + tests).

### Règles de split
- Chaque push sur `main` déclenche le split CI
- Les tags (ex: `v1.0.0`) sont propagés aux repos read-only
- Les sandboxes (`apps/`) ne sont **pas** publiées

## Prérequis Techniques

- PHP >= 8.2 avec extension `pdo_mysql` (PHP 8.4+ requis pour Symfony 8.0)
- MySQL 8.0+
- Symfony 6.4 LTS, 7.x ou 8.x
- Composer 2.x
- Node.js >= 18 (pour les assets Stimulus et les tests JS)

## Conventions

### Language
- **All code comments must be written in English** (inline comments, PHPDoc, JSDoc, Twig comments, etc.)

### Nommage
- **Namespace PSR-4** : `ContentBlocks\` (package principal), `ContentBlocks\Kit\` (kit de blocs)
- **Bundle** : `ContentBlocksBundle` (principal), `ContentBlocksKitBundle` (kit)
- **Entités** : singulier, dans le namespace `Entity`
- **Blocs** : suffixe `Block` (ex: `TextBlock`), implémentent `BlockTypeInterface`
- **Vendor Packagist** : `klehm`

### Architecture & responsabilités
- **content-blocks** : package unique contenant les entités Doctrine (`ContentArea`, `Section`, `Column`, `Block`), le système de blocs (`BlockTypeInterface`, `AsContentBlock`, `BlockTypeRegistry`, `BlockTypeCompilerPass`), l'UI admin (Live Components, Stimulus), et le `ContentAreaType` (FormType Symfony)
- **content-blocks-kit** : dépend de content-blocks. Fournit ~17 blocs prêts à l'emploi, **autonomes** (aucune dépendance Tailwind/Bootstrap/LiipImagine/icônes ; markup neutre `cb-kit-*` stylé par une feuille `kit.css` servie à la route publique `content_blocks_kit_asset_css`) : title (taille visuelle découplée du tag sémantique + couleur palette), text (+ couleur palette), rich_text, image (avec resize/fit/légende/coins arrondis), gallery (grille/carrousel), button, card, list, icon (IconSet livré), alert, divider, accordion (`<details>` natif), table, embed (`cb_embed_url` YouTube/Vimeo), breadcrumb, html_raw (**désactivé par défaut** — rend `{{ html|raw }}`, opt-in via `enabled: true`), tabs. Les champs couleur (title/text/icon/divider + swatches TinyMCE) puisent tous dans la palette core `content_blocks.palette`.
  - **Config sémantique** (`config/packages/content_blocks_kit.yaml`) : `content_blocks_kit.blocks.<type>.{enabled, options, choices, defaults}` — `enabled:false` dé-enregistre le service (jamais dans le picker ; `html_raw` est off par défaut, cf. `ContentBlocksKitBundle::DEFAULT_DISABLED`) ; `options` = knobs bloc (ex. `gallery`/`card` `max_columns`) ; `choices` = allow-list qui restreint/réordonne un `ChoiceType` (fallback sur le set complet si vide/invalide) ; `defaults` = override des valeurs initiales. Base `AbstractKitBlock` : `choiceFields()` (source unique des maps, consommée par `choices()`/`choiceConstraint()` — contrainte = superset complet, restreindre le picker n'invalide jamais une donnée stockée), `defaults()` (mergé par le `getDefaultData()` final), `describe()` (introspection). Gating + merge dans `resolveBlocks()` (pur, testable). Commande `content-blocks-kit:blocks [type]` documente toute la surface (lit `describe()`, jamais périmée).
  - Les champs couleur des blocs réutilisent le `PaletteColorType` du core ; le `color_map` TinyMCE lit la palette via `cb_color_palette()`.
  - **CI/tests** : le kit a son propre PHPUnit + Vitest, et un path repository vers le core local (composer.json) — il teste toujours le core courant du monorepo, pas la dernière release Packagist.

### Installation locale
Les packages sont liés en symlink via les repositories `path` de Composer.
Pas besoin de Packagist pour le développement local.

## Modèle de Données

```
ContentArea → Section → Column → Block
```

`ContentArea` est un conteneur sans titre ni slug — c'est à l'application hôte de fournir sa propre entité (ex: `Page`) avec une relation vers `ContentArea`.

### Entités

| Entité        | Table              | Champs clés                              |
|---------------|--------------------|------------------------------------------|
| `ContentArea` | `cb_content_area`  | id                                       |
| `Section`     | `cb_section`       | id, content_area_id, layout, position    |
| `Column`      | `cb_column`        | id, section_id, preset, position         |
| `Block`       | `cb_block`         | id, column_id, type, data, position      |

- **Section.layout** : `full`, `two_cols`, `three_cols`
- **Column.preset** : `col-12`, `col-6`, `col-4`, etc.
- **Block.type** : identifiant du BlockType (ex: `text`, `title`, `image`)
- **Block.data** : JSON libre, structure dépend du type de bloc

## Système de Blocs

### Principe
1. Chaque type de bloc implémente `BlockTypeInterface`
2. Il est annoté avec `#[AsContentBlock]`
3. Le `BlockTypeCompilerPass` auto-tag les services via l'attribut
4. Le `BlockTypeRegistry` centralise tous les types disponibles

### Créer un bloc custom
```php
use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockTypeInterface;

#[AsContentBlock]
final class MyBlock implements BlockTypeInterface
{
    public static function getType(): string { return 'my_block'; }
    public static function getLabel(): string { return 'Mon Bloc'; }
    public function buildForm(FormBuilderInterface $builder, array $data): void {
        $builder->add('content', TextType::class, ['data' => $data['content'] ?? '']);
    }
    public function getDefaultData(): array { return ['content' => '']; }
}
```

Le bloc sera automatiquement détecté et enregistré dans le `BlockTypeRegistry` grâce à l'autoconfiguration Symfony.

## UI Admin

### Live Components (CRUD serveur)
- **ContentAreaBuilder** : composant principal, gère l'ajout/suppression de sections (1, 2 ou 3 colonnes)
- **Column** : gère l'ajout/suppression de blocs dans une colonne
- **Block** : mode édition inline (modale simplifiée)
- **Section** : Twig Component simple (rendu statique des colonnes)

### Stimulus Controllers (contrôle DOM)
- `cb-builder-launcher` : ouvre le `<dialog>` du builder depuis le widget hôte
- `cb-builder` : orchestration de la fenêtre builder (sidebar, postMessage iframe, sauvegarde)
- `cb-block-edit-keys` : raccourcis clavier dans la sidebar d'édition de bloc
- `cb-section-settings-form` : sync live de la sidebar de settings de section
- `cb-condition` : affichage conditionnel générique de champs (`data-cb-condition="field:value1|value2"` sur une row ; checkbox → `true`/`false` ; `field` seul → non-vide). Plusieurs clauses se combinent en **ET** via `;` (ex. `size:custom;customHeightAuto:false`), chaque clause gardant son **OU** via `|`. Les instances s'imbriquent (scope = plus proche ancêtre) ; le controller est aussi posé sur la **racine du form d'édition de bloc** ([Block.html.twig]) pour qu'un `<select>` puisse gater des rows sœurs (resize image). Utilisé par le switch « Personnaliser le style » et `PaletteColorType` ; réutilisable dans les forms de blocs custom
- `cb-file-upload` : upload AJAX vers `/_content-blocks/upload` (preview + status), utilisé par `ImageUploadType`

Ces controllers doivent être déclarés dans `assets/controllers.json` côté host (jusqu'à publication d'une recette Flex officielle). Voir `packages/content-blocks/README.md`.

### ContentAreaType (FormType)
Un FormType Symfony prêt à l'emploi pour intégrer un ContentArea dans n'importe quel formulaire :
```php
$builder->add('contentArea', ContentAreaType::class);
```
Le type est rendu via un form theme (`@ContentBlocks/form/content_area_widget.html.twig`) auto-prepend par le bundle.

**Lifecycle (important)** : `ContentAreaType::buildView()` n'écrit **rien** en DB sur un GET. Si l'entité hôte n'a pas encore de `ContentArea` (création en cours ou donnée legacy), le widget rend un placeholder "save first" à la place du builder. Sur submit, `reverseTransform()` crée un `ContentArea` transient (persist sans flush) que le host commit via :
- `cascade: ['persist']` sur la relation côté entité hôte (recommandé), ou
- un `$em->flush()` explicite dans le controller du host.

Cette règle évite les rangées `cb_content_area` orphelines créées à chaque visite GET d'un formulaire de création.

### Templates
Les templates utilisent le namespace Twig `@ContentBlocks`.

## Configuration sémantique du bundle

Le bundle expose un arbre de config (`ContentBlocksBundle::configure()`), raccourci déclaratif des interfaces (qui restent la voie power-user et se cumulent avec la config) :

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    content_version: 1                  # génération du schéma de contenu de l'hôte
    section:
        default_width_mode: full        # 'full' | 'centered'
        default_max_width: 1320
    palette:                            # couleurs nommées du PaletteColorType
        - { label: 'Primaire', color: '#eb0540' }
    section_styles:                             # presets de style de section
        - name: boxed
          label: 'Boxed'
          css_class: 'my-section--boxed'
          settings: { styling: { padding: { desktop: { top: 40, bottom: 40 } } } }
    upload:
        directory: null                 # non-null → active LocalFileStorage
        public_prefix: '/uploads/content-blocks'
        max_size: 10485760
        allowed_mime_types: [...]
```

**Versionnage de contenu** (`content_version`, int, défaut 1) : la forme de `block.data` est décidée par les types de blocs (hôte + kit), pas par le core — c'est donc l'hôte qui déclare sa génération de schéma et écrit ses migrations. Estampillé sur `cb_content_area.content_version` par `ContentAreaTouchListener` et sur `cb_section_template.content_version` à la sauvegarde d'un snapshot. **Le stamp d'une zone veut dire « dernière écriture sous la version N », pas « conforme à N »** : éditer un bloc ré-estampille toute la zone (index de ciblage, pas garantie) — migrer avant de laisser les éditeurs reprendre. `NULL` = antérieur au versionnage, jamais `0` (le node refuse `0`). Deux seams : `ContentVersionUpgraderInterface` (hôte, snapshots seulement — l'import ignore un numéro étranger) avec `DenyOnMismatchUpgrader` par défaut (refuse un écart connu, accepte `NULL`), et `EnvelopeUpgraderInterface`/`EnvelopeUpgradeChain` (core, structure du payload, chaîne vide aujourd'hui). Doc complète : [docs/guide/content-versioning.md](docs/guide/content-versioning.md).

Points clés du styling :
- **`PaletteColorType`** (`cb_palette_color`) : dropdown palette + « Personnalisé… » (colorpicker libre), stocke un `#hex` simple (`''` = aucune). Remplace le `ColorType` dans `StylingType`. Interfaces : `ColorPaletteProviderInterface` (autoconfiguré) + `ColorPaletteRegistry`.
- **Défaut de fond transparent** : `CoreStylingDefaults` / `CoreBlockStylingDefaults` valent `''` (le hack `#ffffff` de l'époque `<input type=color>` est supprimé). Attention en upgrade : un `#ffffff` déjà persisté rend désormais un vrai fond blanc.
- **Presets de section avec settings** : `SectionStyle` porte `cssClass` **et** `settings` (même forme que `draft_settings`), mergés *sous* les settings de la section au rendu (`BlockRenderer::applyPresetSettings`) — les valeurs explicites de l'utilisateur gagnent clé par clé.
- **Switch « Personnaliser le style »** (`stylingCustom`) : les champs styling de la sidebar section sont cachés tant qu'il est off (progressive disclosure via `cb-condition`) ; off à la sauvegarde → le sous-arbre `styling` est purgé (le preset s'applique tel quel) ; on → champs préremplis depuis le preset. Les feuilles vides/false sont élaguées récursivement avant persistance (`SectionSidebarController::normalize`).

## Required host services

Two interfaces have no useful default and **must** be wired by the host:
- `ContentBlocks\Security\AccessCheckerInterface` — authorization (IDOR protection)
- `ContentBlocks\Preview\ContentAreaUrlResolverInterface` — maps a `ContentArea` to the public URL of its owner (for the iframe preview); the default `NullContentAreaUrlResolver` throws

## Optional host services

- `ContentBlocks\Replace\ContentAreaProviderInterface` — drives the "Insert content" picker (topbar). Default impl filters by id and labels rows as `#<id> — <updatedAt>`; override to search on title/slug and return meaningful labels via a join through the host's owning entity.

See [packages/content-blocks/README.md](packages/content-blocks/README.md) for full examples.

## Replace-content flow

Editors can overwrite an area's content with another area's content via the topbar "Insert content" button (`cb-shell__replace`). Backend endpoints:

- `GET /_content-blocks/area/{id}/replace-candidates?q=&page=` — filtered/paginated list (10 per page + 1 sentinel for `hasMore`), excludes the target area itself. Labels come from `ContentAreaProviderInterface::getLabel()`.
- `POST /_content-blocks/area/{id}/replace-with/{sourceId}` — soft-deletes the target's existing sections and inserts deep clones (`SectionCloner`) of the source's non-deleted sections in `previewPosition` order. Writes to draft only — publish commits the swap, discard reverts.

`ContentArea::updatedAt` is auto-touched by `ContentBlocks\Doctrine\ContentAreaTouchListener` (Doctrine `onFlush`) whenever any descendant Section / Column / Block is inserted, updated, or deleted. The default provider uses it for "latest-first" ordering.

When upgrading existing projects, add a migration for `cb_content_area.updated_at` (see [apps/content-blocks-sandbox/migrations/Version20260518120000.php](apps/content-blocks-sandbox/migrations/Version20260518120000.php) for the SQL).

## Security

### Access Control (IDOR protection)

ContentBlocks does not know the host app's auth model. It exposes an `AccessCheckerInterface` that the app must implement.

- **Default**: `DenyAllAccessChecker` — blocks all access (secure by default)
- **Dev/sandbox**: `AllowAllAccessChecker` — allows everything

**Setup in host app:**
```yaml
# config/services.yaml
ContentBlocks\Security\AccessCheckerInterface:
    class: App\Security\PageAccessChecker
```
```php
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Entity\ContentArea;

final class PageAccessChecker implements AccessCheckerInterface
{
    public function canEdit(ContentArea $contentArea): bool
    {
        // Check that the current user owns the Page linked to this ContentArea
    }
    public function canView(ContentArea $contentArea): bool { return true; }
}
```

All controllers (`BlockController`, `SectionController`, `UploadController`) and Live Components (`BlockComponent`, `ColumnComponent`, `ContentAreaBuilderComponent`) call `canEdit()` before any mutation.

### CSRF Protection

AJAX controllers (`/_content-blocks/*`) require a `X-CSRF-Token` header validated against the token id `content_blocks`.

The token is rendered in `ContentAreaBuilder.html.twig` via `{{ csrf_token('content_blocks') }}` on the `data-cb-csrf-token` attribute. Stimulus controllers read it with `closest('[data-cb-csrf-token]')`.

**Host app requirements:**
- `session: true` in `framework.yaml` (needed for session-based CSRF tokens)
- `csrf_protection: enabled: true` (Symfony 7.x default is stateless — the token id `content_blocks` falls through to the session-based fallback automatically)

### Firewalls

If the host's admin area is behind a firewall **separate** from the front-office, extend that firewall's pattern to cover `/_content-blocks/*` — otherwise the builder's AJAX calls run unauthenticated and the user loses their session during a builder action:

```yaml
security:
    firewalls:
        admin:
            pattern: ^/(admin|_content-blocks)
```

### Block Data Sanitization

**The block's Symfony form _is_ the whitelist + validator.** A block's `data` is
never written raw: `BlockComponent::persistDraft()` submits the form built by
`buildForm()`, and only on success writes `$form->getData()` to the draft (see
[packages/content-blocks/src/Twig/Component/BlockComponent.php](packages/content-blocks/src/Twig/Component/BlockComponent.php)). Two guarantees fall out of this:

- **Key whitelist**: the compound form only maps its declared children, so an
  unexpected key in the POST is dropped — it never reaches `data`.
- **Value validation**: each field's `constraints` (e.g. `Assert\Choice`,
  `Assert\Length`) run on submit; a failure re-renders the form with errors and
  writes nothing. Nested collections validate via their `entry_type`'s own
  constraints.

There is **no** `getAllowedDataKeys()` / `sanitizeData()` / `processData()` hook —
a custom block secures its data purely by what it declares in `buildForm()`
(fields + constraints). The kit's `AbstractKitBlock::choiceConstraint()` derives
an `Assert\Choice` from the field's full coded choice set for exactly this reason.

**Raw-HTML caveat**: the kit's `html_raw` block renders `{{ html|raw }}`, so it
trusts its editors — it is **disabled by default** (`content_blocks_kit.blocks.html_raw.enabled: false`)
and must be opted in.

### File Upload

Upload endpoint `/_content-blocks/upload` (core, `ContentBlocks\Controller\UploadController`) validates: CSRF token, file size, MIME whitelist — both configurables via `content_blocks.upload.max_size` / `.allowed_mime_types`.

`ContentBlocks\Storage\FileStorageInterface` is the abstraction for file storage (core — plus dans le kit) :
- **Default**: `NullFileStorage` — throws (forces app to configure)
- **Provided**: `LocalFileStorage` — local filesystem, activé par la config sémantique :

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    upload:
        directory: '%kernel.project_dir%/public/uploads/content-blocks'
        public_prefix: '/uploads/content-blocks'
```

Pour S3/Flysystem : aliaser `FileStorageInterface` vers sa propre implémentation. Côté formulaire, `ImageUploadType` (core) rend le picker + preview via le widget `cb_image_upload` de `cb_form_theme.html.twig`. L'export/import d'assets passe par `FileStorageAssetResolver` (core), branché par défaut sur `AssetResolverInterface`.

## Choix Techniques

- **Doctrine ORM** pour la persistance
- **Attributs PHP 8** pour le mapping Doctrine et l'auto-enregistrement des blocs
- **Live Components + Stimulus (mix)** : Live Components pour le CRUD serveur (ajout/suppression sections, blocs), Stimulus pour le contrôle DOM fin (réordonnancement, drag & drop, intégration d'éditeurs JS tiers comme TinyMCE)
- **Règle d'architecture UI** : Live Component pour le CRUD, Stimulus pour le DOM. Ne pas utiliser de LiveAction pour des opérations qui réordonnent des composants Live enfants (morphdom/Idiomorph ne gère pas le reorder de child Live Components avec `data-live-preserve`).
- **Bootstrap 5** via CDN pour le styling rapide (MVP)
- **MySQL** comme base de données (pdo_mysql requis)

## Installation & Lancement

### 1. Sandbox Symfony

```bash
cd apps/content-blocks-sandbox

# Installer les dépendances (les packages sont en symlink)
composer install

# Configurer la base de données dans .env
# DATABASE_URL="mysql://user:password@127.0.0.1:3306/content_blocks_sandbox?serverVersion=8.0&charset=utf8mb4"

# Créer la base et le schéma
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:schema:create

# Lancer le serveur
php -S 127.0.0.1:8000 -t public

# Accéder à http://127.0.0.1:8000
```

### 2. Sandbox Sylius

```bash
cd apps/content-blocks-sylius-sandbox

# Installer les dépendances
composer install

# Configurer la base de données dans .env
# DATABASE_URL="mysql://user:password@127.0.0.1:3306/content_blocks_sylius?serverVersion=8.0&charset=utf8mb4"

# Créer la base et le schéma
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:schema:create

# Lancer le serveur
php -S 127.0.0.1:8001 -t public

# Accéder à http://127.0.0.1:8001
```

## Intégration dans un projet

Chaque application crée sa propre entité (ex: `Page`, `Product`) avec une relation `OneToOne` vers `ContentArea` :

```php
use ContentBlocks\Entity\ContentArea;

#[ORM\Entity]
class Page
{
    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    private ?ContentArea $contentArea = null;
    // ...
}
```

Les deux sandbox (Symfony et Sylius) suivent ce pattern avec une entité `App\Entity\Page` propre.

## Spécificités Sylius Sandbox

- Entité `Page` propre à l'app avec champ `enabled`
- Référence vers le `ContentArea` via relation `OneToOne`
- Grid Sylius configurée dans `PageGrid.php`
- Le ContentAreaBuilder est intégré dans la vue builder de la Page

## Tests

### Tests JS (dans `packages/content-blocks/`)

```bash
cd packages/content-blocks
npm install

# Tests unitaires (Vitest + jsdom)
npm run test:unit

# Tests E2E (Playwright — démarre la sandbox automatiquement)
npm run test:e2e

# Les deux
npm test
```

- **Vitest** : teste la logique des controllers Stimulus en isolation (DOM mock, fetch mock)
- **Playwright** : teste les flux complets dans un vrai navigateur contre la sandbox
- La sandbox (`apps/content-blocks-sandbox/`) sert de **fixture** pour Playwright (`webServer` dans `playwright.config.js`)

### Tests PHP

```bash
cd packages/content-blocks
./vendor/bin/phpunit
```

## Workflow Claude — recompiler les assets après chaque tâche

Les deux sandboxes utilisent **AssetMapper** : tout changement dans `packages/content-blocks/assets/` (Stimulus controllers, CSS, JS) ou `packages/content-blocks-kit/assets/` n'est servi qu'après une recompilation du `public/assets/` de chaque sandbox.

**À chaque tâche** qui touche un fichier sous `packages/*/assets/`, Claude doit relancer la compilation dans **les deux** sandboxes avant de conclure :

```bash
# Symfony sandbox
cd apps/content-blocks-sandbox \
  && rm -rf public/assets \
  && php bin/console cache:clear -q \
  && php bin/console asset-map:compile

# Sylius sandbox
cd apps/content-blocks-sylius-sandbox \
  && rm -rf public/assets \
  && php bin/console cache:clear -q \
  && php bin/console asset-map:compile
```

Si on ajoute un nouveau Stimulus controller, il faut aussi :
- Le déclarer dans **`packages/content-blocks/assets/package.json`** (et **non** dans le `package.json` racine du package — celui-ci ne sert qu'à vitest/playwright). C'est `assets/package.json` qui est lu par `Symfony\UX\StimulusBundle\Ux\UxPackageReader`.
- L'activer dans `apps/content-blocks-sandbox/assets/controllers.json` **et** `apps/content-blocks-sylius-sandbox/assets/controllers.json`.

Sans ces étapes, `asset-map:compile` échoue avec `Controller "@klehm/content-blocks/<name>" does not exist in the "klehm/content-blocks" package.`

## Troubleshooting

### `could not find driver`
Installer l'extension PHP requise : `sudo apt install php-mysql` (ou `php8.x-mysql`)

### Live Components 404
Vérifier que les routes Live Components ont le prefix `/_components` dans `config/routes/ux_live_component.yaml`

### Twig Component "no matching namespace"
Vérifier `config/packages/twig_component.yaml` — le namespace `ContentBlocks\Twig\Component\` doit être mappé vers `@ContentBlocks/components/`

### Blocs non détectés
Vérifier que le bloc est dans un namespace chargé par le service container et que le fichier `config/services.php` du kit charge bien `ContentBlocks\Kit\Block\`

---

*Document maintenu à jour au fur et à mesure du développement.*
