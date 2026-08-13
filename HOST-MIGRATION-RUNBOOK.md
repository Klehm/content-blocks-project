# Runbook — migrer les trois hôtes vers la RC 1.0 et vers le kit

Source unique de vérité pour la mise à jour de `ybc`, `efs` et `em-interpretation`.
Les `CLAUDE.md` des trois projets pointent ici ; ne duplique pas ce contenu chez eux,
mets-le à jour ici.

**Objectif.** Monter les trois hôtes sur la RC 1.0, puis remplacer les blocs maison
par ceux de `klehm/content-blocks-kit` **sans perdre une seule capacité d'édition**
et sans casser le contenu déjà en base.

---

## 0. État de départ (relevé le 2026-08-13)

| Projet | Chemin | Version | Blocs custom | Ids en collision avec le kit | Criticité |
|---|---|---|---|---|---|
| em-interpretation | `/var/www/em-interpretation` | `v0.1.0-beta.6` | 9 | 1 | faible |
| ybc | `/var/www/ybc` | `v0.1.0-beta.5` | 21 | **14** | prod, peu critique |
| efs | `/var/www/efs` | `v0.1.0-beta.5` | 21 | 4 | **prod, ~20 pages** |

Aucun autre hôte n'installe le package. Aucun tiers ne l'installe. C'est la raison
pour laquelle il n'y a **pas** de guide d'upgrade publié beta → stable : le guide,
c'est ce fichier, et il se remplit en faisant le premier projet.

Types livrés par le kit (17) : `accordion` `alert` `breadcrumb` `button` `card`
`divider` `embed` `gallery` `html_raw` `icon` `image` `list` `rich_text` `table`
`tabs` `text` `title`.

---

## 1. Le piège central : la collision d'identifiants

`BlockTypeRegistry::register()` fait `$this->blockTypes[$type] = $blockType`
([BlockTypeRegistry.php:16](packages/content-blocks/src/BlockType/BlockTypeRegistry.php#L16)).

**Une collision d'id est silencieuse, et c'est le dernier service enregistré qui
gagne** — l'ordre dépend du container, pas d'une règle stable. Sur YBC, un
`composer require klehm/content-blocks-kit` échangerait 14 implémentations sous du
contenu existant, sans une ligne d'avertissement, avec des formes de `data` qui ne
correspondent pas.

Le levier qui neutralise ça : `content_blocks_kit.blocks.<type>.enabled: false`
**dé-enregistre le service du kit**. Rien dans le picker, rien dans le registry,
aucune collision.

### Deux formes de migration, une seule est confortable

|  | Même id (ex. YBC `button`) | Id différent (ex. EFS `wysiwyg` → kit `rich_text`) |
|---|---|---|
| Collision | oui | non |
| Cohabitation | impossible | **les deux vivent en parallèle** |
| Bascule | atomique, tout ou rien | ligne par ligne, réversible |
| Rollback | redéployer | `UPDATE` inverse |

**Technique à appliquer systématiquement : convertir le cas 1 en cas 2.** Avant
d'installer le kit, renomme l'id du bloc maison en `legacy_<type>` et fais suivre
les lignes existantes :

```php
// dans le bloc de l'hôte
public static function getType(): string { return 'legacy_button'; }
```
```sql
UPDATE cb_block SET type = 'legacy_button' WHERE type = 'button';
```

À partir de là, plus aucune collision : le kit peut être installé entier, les deux
blocs coexistent dans le picker, et tu migres `legacy_button` → `button` au rythme
que tu veux, avec un `UPDATE` inverse comme filet. Le bloc `legacy_*` se supprime
quand il ne reste plus une ligne de ce type.

---

## 2. Ordre de passage

Deux migrations distinctes se cachent ici — **monter de version** et **passer au
kit**. Ne les mène jamais ensemble dans le même commit, et pas dans le même projet
la première fois.

1. **em-interpretation** — plus petit delta (beta.6), 9 blocs quasi tous métier,
   une seule collision. Il isole proprement « le core monte à la RC ». C'est le
   smoke test, et c'est là que se prennent les notes qui remplissent la §6.
2. **ybc** — 14 collisions, prod mais peu critique. Il isole « hôte → kit » avec le
   maximum de surface et le minimum d'enjeu. C'est lui qui révèle les vrais écarts
   de champs.
3. **efs** — les deux, avec tout ce qui précède déjà appris et déjà écrit.

Dans chaque projet, même découpage en deux étapes séparées par une vérification en
recette : **(A) monter le core, remettre au vert avec les blocs de l'hôte, publier,
vérifier. (B) seulement ensuite, installer le kit et basculer les types un par un.**

---

## 3. Étape A — monter le core sur la RC

### A.0 Filet de sécurité (non négociable)

```bash
git checkout -b chore/content-blocks-rc
mysqldump <base> > /tmp/<projet>-avant-rc.sql
```

### A.1 Installer

em-interpretation prend le **vrai tag depuis Packagist** — c'est le seul test du
chemin de distribution (split splitsh, propagation du tag aux 3 repos read-only,
résolution Packagist, recette Flex qui écrit `controllers.json`), et un repository
`path` ne peut structurellement pas le tester.

```bash
composer require klehm/content-blocks:^1.0@RC
```

YBC et EFS passent en repository `path` sur le monorepo pendant l'étape B, parce
qu'on y édite activement le kit et que la boucle « je corrige / je recharge » doit
rester en secondes. Retour sur le tag à la fin.

```json
{
  "repositories": [
    { "type": "path", "url": "/var/www/page-builder-project/packages/*" }
  ]
}
```

Note : `minimum-stability` vaut `alpha` (ybc), `beta` (efs), `stable` (em). Une
contrainte explicite portant un suffixe de stabilité (`^1.0@RC`) suffit à Composer 2,
même sous `stable` — pas besoin de toucher au `minimum-stability` global.

### A.2 Les six ruptures qui comptent sur beta.5 → RC

88 commits séparent beta.5 de master. Ce qui casse réellement chez ces hôtes :

- **`BlockRendererInterface` prend un `RenderContext`, plus un `RenderMode`.**
  `render(ContentArea, ?RenderContext)`, idem `renderBlock()` / `renderSection()`.
  Ne concerne que qui appelle ou **décore** le renderer — vérifier les extensions
  EFS en priorité.
- **Le défaut de fond est passé à transparent.** `CoreStylingDefaults` /
  `CoreBlockStylingDefaults` valent `''`. Conséquence sournoise : **un `#ffffff`
  déjà persisté rend maintenant un vrai fond blanc**, là où le hack d'avant le
  rendait invisible. À contrôler en base avant/après — attention, **il n'y a pas de
  colonne `data`** : un bloc porte `published_data` / `draft_data`, une section
  `published_settings` / `draft_settings`, et les quatre se contrôlent :
  ```sql
  SELECT id, type FROM cb_block
  WHERE JSON_EXTRACT(published_data, '$.styling.backgroundColor') = '#ffffff'
     OR JSON_EXTRACT(draft_data,     '$.styling.backgroundColor') = '#ffffff';
  SELECT id FROM cb_section
  WHERE JSON_EXTRACT(published_settings, '$.styling.backgroundColor') = '#ffffff'
     OR JSON_EXTRACT(draft_settings,     '$.styling.backgroundColor') = '#ffffff';
  ```
  EFS forçait déjà `''` via des defaults providers basse priorité — ce contournement
  devient inutile et doit sauter.
  **Que faire des lignes trouvées : les blanchir.** Sous beta.5 le défaut valait
  `#ffffff`, donc une valeur égale était strippée avant rendu — aucun éditeur n'a
  jamais pu obtenir un blanc en le choisissant. Un `#ffffff` en base est donc
  toujours un artefact du hack : le remettre à `''` **préserve** le rendu au lieu de
  perdre une intention (migration ybc `Version20260813130500`, à passer avant toute
  écriture sous la RC).
- **Le code de l'hôte qui *produit* des clés de viewport `d`/`t`/`m` meurt en
  silence.** La bascule `d`/`t`/`m` → `desktop`/`tablet`/`mobile` (voir A.5) ne
  touche pas que les données stockées : un `SectionSettingsDefaultsProviderInterface`
  ou un `BlockDataDefaultsProviderInterface` de l'hôte qui retourne
  `styling.padding.{d,t,m}` cesse simplement de s'appliquer — sans erreur, sans
  warning. Les champs s'ouvrent vides et le rendu perd le padding, ce qui ressemble
  à un choix éditorial. À chercher **avant** de migrer les données :
  ```bash
  grep -rn "'d' =>" src/   # tout provider de defaults, section comme bloc
  ```
- **La brique upload a migré du kit vers le core** (`ContentBlocks\Storage`,
  `UploadController`, `ImageUploadType`, widget `cb_image_upload`). Les deux hôtes
  l'avaient réimplémentée : conflit de services quasi certain, et c'est du code hôte
  à **supprimer**, pas à réconcilier.
- **`PaletteColorType` (`cb_palette_color`) remplace `ColorType` dans `StylingType`.**
  Le `ThemeColorType` d'EFS et son `StylingThemeColorExtension` sont désormais du
  doublon : basculer sur `content_blocks.palette` en config.
- **Le contrôleur `cb-condition` est générique.** Les `cb-gallery-size`,
  `cb_image_size`, `cb_gallery_layout`, `cb_card_layout` ad-hoc des hôtes se
  remplacent par `data-cb-condition` sur les `row_attr`.

### A.3 Poser le versionnage de contenu

Avant toute migration de données, chaque hôte déclare sa génération de schéma —
c'est le premier usage réel de la fonctionnalité, et il faut un point de départ
nommé pour pouvoir dire « ces lignes sont en v1 » :

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    content_version: 1
```

Le stamp existant vaut `NULL` sur les trois hôtes (antérieur au versionnage), ce que
le `DenyOnMismatchUpgrader` accepte. Chaque migration de données de l'étape B
incrémente ce numéro.

### A.4 Migrer le schéma — la RC en ajoute

La RC apporte deux objets absents de beta.5 / beta.6, tous deux postérieurs à ces
tags. Ce n'est pas optionnel : sans la colonne, **toute écriture sur une
`ContentArea` échoue** (`Unknown column 'content_version' in 'field list'`).

```sql
CREATE TABLE cb_section_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL,
  payload JSON NOT NULL, block_types JSON NOT NULL, content_version INT DEFAULT NULL,
  created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
ALTER TABLE cb_content_area ADD content_version INT DEFAULT NULL;
```

Génère-la (`doctrine:migrations:diff`) plutôt que de la copier, puis applique-la.
**La base de test ne se migre pas toute seule** : `--env=test` aussi, sinon la
suite tombe sur la colonne manquante et non sur une vraie régression.

### A.5 La migration de contenu de l'étape A : les clés de viewport

La RC renomme les clés du sous-arbre responsive (`styling.padding` / `margin` /
`gap`) : `d`/`t`/`m` → `desktop`/`tablet`/`mobile`, dans les quatre colonnes JSON.
Les noms des custom properties CSS émises ne bougent pas — le CSS de l'hôte n'est
pas concerné. Migration de référence livrée par le package :
`Version20260715130000` (les deux sandboxes, réversible).

Compte les lignes concernées avant de décider :

```sql
SELECT COUNT(*) FROM cb_block
WHERE JSON_CONTAINS_PATH(draft_data,     'one', '$.styling.padding.d', '$.styling.margin.d', '$.styling.gap.d')
   OR JSON_CONTAINS_PATH(published_data, 'one', '$.styling.padding.d', '$.styling.margin.d', '$.styling.gap.d');
```

Zéro ligne en dev ne dit rien de la prod : **relance le compte sur la base de
production** avant de publier. (Sur em : zéro des deux côtés, aucun `styling`
n'ayant jamais été persisté.)

### A.6 Recompiler et vérifier

Assets recompilés selon le bundler de l'hôte, puis vérification manuelle sur des
pages réelles : le builder ouvre, une section s'édite, un bloc s'enregistre, la
preview rend, Publish/Discard fonctionnent. **Publier et vérifier en prod avant
d'entamer l'étape B.**

---

## 4. Étape B — remplacer un bloc maison par un bloc du kit

### B.0 Installer le kit, tout ce qui collisionne éteint

```bash
composer require klehm/content-blocks-kit
```

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        button:   { enabled: false }
        image:    { enabled: false }
        # ... un par id en collision, tant qu'il n'est pas migré
```

(`html_raw` est déjà off par défaut via `ContentBlocksKitBundle::DEFAULT_DISABLED`.)

Ou, mieux : applique le renommage `legacy_*` de la §1 et n'éteins rien.

Le kit sert sa propre feuille sur la route `content_blocks_kit_asset_css` ; le
markup est neutre (`cb-kit-*`), sans dépendance à Tailwind, Bootstrap, LiipImagine
ou un pack d'icônes.

### B.1 Le diff de champs — avant d'écrire une ligne de code

**C'est ici que se joue « on ne perd aucune capacité d'édition »**, pas dans la
migration de données. Mets les champs du `buildForm()` de l'hôte en face de ceux du
bloc kit, et classe **chaque** champ dans exactement une des quatre cases :

1. **Équivalent dans le kit** → mappé par la migration de données.
2. **Absent du kit, mais générique** → tu le remontes **dans le kit**. C'est tout
   l'objet du voyage retour. Le kit a déjà `options` / `choices` / `defaults` par
   bloc pour l'accueillir sans forker quoi que ce soit.
3. **Absent du kit et spécifique à l'hôte** → **sous-classe** le bloc kit dans
   l'hôte : `enabled: false` sur le type kit, une classe qui étend
   `ContentBlocks\Kit\Block\ButtonBlock`, garde le même `getType()`, enrichit
   `buildForm()` / `getDefaultData()`. Tu hérites du template et de la donnée, tu
   ajoutes ton champ. C'est ça, l'override propre — et il n'est possible que grâce
   au levier `enabled`.
4. **Métier** → **tu ne migres pas ce bloc.** Il reste tel quel, à vie.

> **Règle bloquante : aucun type ne bascule tant qu'un seul de ses champs n'est pas
> classé.** C'est la seule garantie réelle contre la perte de capacité d'édition.
> Un champ qu'on n'arrive pas à classer est un champ qu'on n'a pas compris.

Écris le diff dans le commit (ou dans un fichier `docs/` de l'hôte). Il documente
*pourquoi* la bascule était sûre, ce qu'aucun diff de code ne dira.

### B.2 Les trois gestes de la bascule

1. **Migration de données** — une commande console dédiée, qui lit les lignes
   `cb_block` du type concerné et réécrit `data` (et `type`, en cas de renommage)
   vers la forme du bloc kit. Exigences : **idempotente**, `--dry-run` par défaut,
   un compte des lignes touchées, et un log des clés abandonnées. Elle incrémente
   `content_version` quand elle a fini.
2. **Rendu** — le kit rend du `cb-kit-*` neutre. Si le design de l'hôte diffère,
   surcharge le template :
   `templates/bundles/ContentBlocksKitBundle/block/<type>/view.html.twig`
   (le kit rend depuis `@ContentBlocksKit/block/<type>/view.html.twig`). Préfère
   styler `cb-kit-*` dans le thème de l'hôte quand le markup convient — une
   surcharge de template est une dette qui ne suit pas les mises à jour du kit.
3. **L'échange** — kit `enabled: true`, classe hôte supprimée (ou `legacy_*` gardé
   tant qu'il reste des lignes).

### B.3 Par quoi commencer

Sur YBC : **`button` ou `divider`.** Peu de champs, peu de contenu en base, et ils
déroulent la chaîne complète une fois avant `image` et `gallery`, qui sont les deux
vrais morceaux.

### B.4 Correspondances relevées (à confirmer par le diff de champs)

**ybc** — collision d'id : `accordion` `alert` `breadcrumb` `button` `divider`
`embed` `gallery` `html_raw` `icon` `image` `list` `table` `text` `title`.
Ne migrent pas : `agenda` `event_calendar` `gyms` `hero_cta_card` `info_card`
`news` `schedule_table`.

**efs** — collision d'id : `breadcrumb` `button` `card` `image`.
Équivalents à id différent (cas confortable) : `wysiwyg` → `rich_text`,
`heading` → `title`, `faq` → `accordion`, `image_gallery` → `gallery`,
`detailed_list` / `feature_list` → `list`, `video_embed` / `map_embed` → `embed`,
`highlight_box` → `alert` ou `card`.
Ne migrent pas : `contact_form` `last_articles` `reviews_carousel` `search_visit`
`typical_day` `why_choose_us` `hero_header` `video_gallery`.

**em-interpretation** — collision d'id : `rich_text`.
Équivalents à id différent : `faq` → `accordion`, `seo_text` → `text` ou `rich_text`,
`cta_banner` → `card` ou `button`.
Ne migrent pas : `booking_steps` `booking_tunnel` `ethics` `hero` `service_doors`.

---

## 5. Comment travailler (sessions Claude)

**Une session par projet, jamais un workspace unique avec les quatre.** Les trois
hôtes contiennent `ButtonBlock`, `ImageBlock`, `BreadcrumbBlock` en exemplaires
quasi identiques : dans un workspace commun, chaque recherche devient ambiguë et une
édition finit dans le mauvais projet.

Mais chaque session hôte a **le monorepo en working directory additionnel**, parce
qu'un bug trouvé dans EFS appartient souvent au package, pas à EFS — et sans accès
au package, on le contourne côté hôte, ce qui est exactement la direction qui a fait
diverger les trois projets la première fois.

`<projet>/.claude/settings.local.json` :

```json
{
  "permissions": {
    "additionalDirectories": ["/var/www/page-builder-project"]
  }
}
```

Ce qui remonte dans le package se corrige **dans le monorepo**, avec ses tests, et
part dans la RC suivante. Rien ne se patche dans `vendor/`.

---

## 6. Journal d'upgrade — à remplir en faisant

Le guide beta → 1.0 qu'on ne publie pas se récolte ici, au fil de l'eau. Une entrée
par surprise rencontrée, dans le projet où elle est apparue.

| Projet | Surprise rencontrée | Résolution | Doit remonter dans le package ? |
|---|---|---|---|
| em | **L'étape A demande une migration de schéma, que ce runbook ne mentionnait pas.** La RC ajoute la table `cb_section_template` et la colonne `cb_content_area.content_version` — deux fonctionnalités postérieures à beta.6. Sans elles, tout écrit sur une `ContentArea` casse (`Unknown column 'content_version'`). | `doctrine:migrations:diff` puis `migrate`, en dev **et** dans la base de test (elle ne se migre pas toute seule). §3 corrigé : c'est maintenant l'étape A.4. | Non — c'est le runbook qui était incomplet. |
| em | **Le SQL de contrôle des fonds `#ffffff` (§3 A.2) visait une colonne `data` qui n'existe pas.** Le schéma réel porte `cb_block.published_data` / `draft_data` et `cb_section.published_settings` / `draft_settings` — le contrôle passait donc « au vert » par erreur de colonne, pas par absence de fond blanc. | Requête corrigée dans le §3, sur les quatre colonnes. Sur em : zéro `#ffffff`, zéro `styling` persisté du tout (contenu semé par fixtures). | Non — erreur du runbook. |
| em | **La bascule des clés de viewport `d`/`t`/`m` → `desktop`/`tablet`/`mobile` est une migration de contenu de l'étape A**, pas de l'étape B. Le §3 ne la citait pas ; elle est dans le CHANGELOG de la RC avec une migration de référence (`Version20260715130000`, les deux sandboxes). | Sur em : zéro ligne concernée (aucun `styling` persisté), donc rien à migrer — mais **à vérifier sur la base de prod avant de publier**, et ybc/efs y passeront presque sûrement. Ajouté au §3 comme A.3. | Non — le package fournit déjà la migration de référence. |
| em | Chemin de distribution réel **validé de bout en bout** : `composer require klehm/content-blocks:^1.0@RC` résout depuis Packagist sous `minimum-stability: stable` sans toucher au global, et la recette Flex met bien `assets/controllers.json` à jour (ajout de `cb-condition` et `cb-file-upload`). | Rien à faire. | Non — c'était le test, il passe. |
| em | **Un `public/assets/` compilé qui traîne fait tourner l'ANCIEN JS du builder, sans un mot.** En debug, AssetMapper sert les fichiers compilés s'ils existent — après le `composer require`, le builder chargeait encore le contrôleur de beta.6. Symptôme : `Action "click->cb-builder#toggleActions" references undefined method`, alors que la méthode est bien dans `vendor/`. Symfony ne le dit qu'en warning, à la compilation suivante. | `rm -rf public/assets` en dev (c'est un artefact de build, gitignoré), puis `cache:clear`. **À faire systématiquement après le `composer require`**, avant de conclure quoi que ce soit d'un test manuel. | Non — comportement AssetMapper. Mais c'est le piège n°1 de la vérification de l'étape A : il fabrique de faux bugs de package. |
| em | **Escape ferme le builder entier** (le shell vit dans un `<dialog>` ouvert en `showModal()`, sans handler `cancel`). Vu en test automatisé : `.cb-shell` passe à 0×0 alors que `display` reste `grid` — donc « bouton Publier invisible » sans la moindre erreur JS. | Comportement natif et intentionnel (les éditions sont autosauvées, aucune perte). La superposition est correcte, vérifiée : 1er Escape ferme le menu Actions, 2e ferme le builder. **Ne pas mettre d'Escape dans un script de fumée** — c'était le test qui était faux, pas le package. | Non. |
| em | **Prod contrôlée avant publication : 0 ligne de `styling` persistée** (27 blocs, 8 areas), donc ni clés de viewport courtes ni `#ffffff`. Les deux ruptures de contenu de la RC sont sans objet sur cet hôte. | Aucune migration de contenu à écrire. `content_version: 1` posé et vérifié : les areas écrites sous la RC portent bien le stamp 1. | Non. |
| em | **Étape B — `^/_content-blocks` attrape aussi `/_content-blocks-kit/`.** L'hôte verrouillait `- { path: ^/_content-blocks, roles: ROLE_ADMIN }` ; le préfixe couvre le chemin du kit, donc sa feuille de style front partait en **302 vers la page de connexion pour tout visiteur anonyme**. Aucune erreur : la page rend, les blocs du kit sortent simplement sans un seul style, et en session admin tout paraît normal. | Ajouter `- { path: ^/_content-blocks-kit/public, roles: PUBLIC_ACCESS }` **avant** la règle ROLE_ADMIN. À vérifier sur les trois hôtes : la règle large est le réglage naturel, donc les trois l'ont probablement. | Non — mais à mettre dans le §4 comme geste d'installation du kit. |
| em | **Se fier au rendu pour valider le thème mène à l'erreur inverse.** Les blocs paraissaient correctement stylés alors que la feuille du kit ne se chargeait pas : ce qu'on voyait venait uniquement des surcharges de l'hôte. Le signe qui ne trompe pas est une propriété que **seul** le kit pose — `.cb-kit-btn` en `display: inline` au lieu de `inline-flex`. | Contrôler une propriété propre au kit, pas une couleur, et vérifier `document.styleSheets` : la feuille inaccessible lève `SecurityError` sur `cssRules`. | Non. |
| em | **La régénération de la doc du kit écrase les notes écrites à la main.** `npm run docs:blocks:refresh` a voulu supprimer les renvois à `ImageUrlResolverInterface` de `card.md` et `image.md`. Le pied de page affirme pourtant « generated … they never go stale ». | Notes restaurées à la main. Le générateur devrait préserver une section libre, ou la doc ne devrait pas se compléter à la main. | **Oui** — soit le générateur préserve les notes, soit le pied de page arrête de promettre ce qu'il ne tient pas. |
| em | **Un champ manquant se règle par une sous-classe dans l'hôte, pas par une remontée dans le kit.** Les 3 lignes `faq` portaient un surtitre, et l'éclatement en blocs du kit n'avait nulle part où le mettre. Premier réflexe : ajouter `eyebrow` au `title` du kit (cas ② du §4.1). **Mauvais réflexe** — un surtitre est la typographie d'une charte, pas un besoin général, et le paquet est partagé par trois hôtes. | Cas ③ : `title: { enabled: false }` et `App\ContentBlocks\Block\TitleBlock extends ContentBlocks\Kit\Block\TitleBlock`, qui garde l'identifiant `title`, ajoute son champ et rend son template. Tout le reste est hérité. Le rendu y gagne : le template réutilise le composant `<twig:Eyebrow>` du site, donc le surtitre suit la charte sans la redécrire — ce qu'un champ générique dans le kit n'aurait pas pu faire. **Aucune RC2 nécessaire.** | Non — et c'est l'enseignement : le cas ② se mérite. Avant de toucher au paquet, vérifier que le champ n'est pas simplement la charte d'un hôte. |
| em | Aucune des **cinq ruptures du §3 A.2** ne touche cet hôte : pas de décorateur de renderer, pas de brique upload réimplémentée, pas de `ColorType`, pas de contrôleur de condition ad-hoc, et **aucun `config/packages/content_blocks.yaml`** (donc ni `upload.dir` ni `styles` à renommer). Surface d'intégration : 4 fichiers (`AccessCheckerInterface`, `ContentAreaUrlResolverInterface`, `ContentAreaType`, entité `Page`). | Rien à faire. Le signal est propre : ce qui casse ici casse pour tout le monde. | Non. |
| ybc | **Sixième rupture, absente du §3 A.2 : le code PHP de l'hôte qui *produit* des clés `d`/`t`/`m`.** La bascule des viewports ne concerne pas que les données stockées : un `SectionSettingsDefaultsProviderInterface` (ici `SectionPaddingDefaults`, qui pré-remplit 32px de padding horizontal) qui retourne `styling.padding.{d,t,m}` **cesse simplement de s'appliquer**, sans erreur ni warning. Symptôme : les champs « Marge intérieure » s'ouvrent vides et le rendu perd le padding — indiscernable d'un choix éditorial. Le CHANGELOG de la RC ne cite que les données stockées et le CSS. | Clés renommées dans le provider, plus son test unitaire. Vérifié dans le builder : les champs se ré-ouvrent bien à 32/32. **À chercher avant de migrer quoi que ce soit** : `grep -rn "'d' =>" src/` sur tout provider de defaults (settings de section comme data de bloc). Ajouté au §3 A.2. | **Oui** — le point BREAKING du CHANGELOG doit citer les providers de defaults de l'hôte comme troisième porteur des clés, à côté des données stockées et du CSS. C'est le seul des trois qui échoue en silence. |
| ybc | **La collision de la brique upload est totale, pas partielle.** L'hôte avait réimplémenté la brique entière : même nom de route *et* même chemin (`content_blocks_upload`, `/_content-blocks/upload`), même nom de contrôleur Stimulus (`cb-file-upload`, que la recette Flex vient justement d'ajouter à `controllers.json`), même `FileStorageInterface`/`LocalFileStorage` à un namespace près. Rien ne lève : Symfony garde la dernière route enregistrée, Stimulus le dernier contrôleur chargé. | Suppression franche côté hôte (3 classes PHP, 1 contrôleur Stimulus, 2 form themes Twig, 2 entrées `services.yaml`), et bascule des champs `src` sur `ImageUploadType` du core. Nouveau `config/packages/content_blocks.yaml` avec `upload.directory` + `public_prefix`. Chaîne complète re-testée dans le builder : upload réel, fichier écrit, preview, publication, image servie en 200. **Capacité d'édition gagnée** au passage (glisser-déposer, bouton Retirer, coller un chemin) — c'est bien du code hôte à supprimer, pas à réconcilier. | Non — le §3 A.2 le disait, il sous-estimait juste l'ampleur. |
| ybc | **Les deux ruptures de contenu de la RC sont bien réelles ici** (là où em n'avait rien) : 1 bloc porte à la fois les clés de viewport courtes et un `backgroundColor: '#ffffff'`, en `published_data` seul. Sur 10 blocs / 9 sections / 8 areas. | Deux migrations : le portage de la migration de référence `Version20260715130000`, et une seconde qui neutralise les `#ffffff`. Vérifié après coup : 0 clé courte restante, 0 `#ffffff` restant. | Non. |
| ybc | **Le §3 A.2 dit de *contrôler* les fonds `#ffffff` mais pas quoi en faire.** La bonne décision se lit dans beta.5 : le défaut valait `#ffffff`, donc toute valeur égale était *strippée* avant rendu (`withoutDefaults`) — **aucun éditeur n'a jamais pu obtenir un blanc en le choisissant**. Un `#ffffff` en base est donc toujours un artefact du hack, jamais une intention. | Les blanchir à `''` est ce qui **préserve** le rendu, ce n'est pas une perte de donnée. Migration dédiée, `down()` volontairement irréversible (une valeur blanchie est indiscernable d'un « Aucune » choisi après coup — le dump d'avant-étape est le chemin de rollback). Ne vaut que tant qu'aucune ligne n'a été écrite sous la RC : c'est une migration à passer tôt, pas plus tard. | Non — mais le §3 A.2 gagne à porter la conclusion plutôt que le seul contrôle. |
| ybc | **L'exemple de config du bundle est périmé et ne passe plus.** Le docblock de `ContentBlocksBundle::configure()` annonce encore `section_styles[].settings: { styling: { padding: { d: { top: 40, bottom: 40 } } } }`, alors que `responsiveBoxNode()` n'accepte plus que `desktop`/`tablet`/`mobile`. Un hôte qui recopie l'exemple se prend un `Unrecognized option "d"` au build du container. Sans effet ici (ybc déclare ses presets en PHP via `SectionStyleProviderInterface`, pas en YAML) — mais efs est le prochain et déclare, lui, de la config. | Rien à faire côté hôte. | **Oui** — corriger l'exemple du docblock dans le package. |
| ybc | `content_version: 1` posé avant toute migration de contenu, et **vérifié sur une écriture réelle du builder** : l'area passe de `NULL` à `1` à l'enregistrement (pas seulement en test). Le reste du §3 A.2 est sans objet ici : pas de décorateur de renderer, pas de `ColorType` du package (les `ColorType` trouvés sont ceux de Symfony, dans des formulaires d'admin sans rapport), et les contrôleurs de condition ad-hoc (`cb-gallery-size`) n'entrent en collision avec rien — leur remplacement par `data-cb-condition` est du nettoyage, pas une rupture, et attend l'étape B. | Vérification manuelle complète au passage : ouverture du builder, édition de section, autosave, preview, Publier, Annuler les modifications, upload — zéro erreur console. | Non. |

---

## 7. Attendus côté RC

Ces trois hôtes **sont** les testeurs de la RC — il n'y en aura pas d'autres. Il est
donc normal et sain qu'ils produisent une RC2, voire une RC3 : une RC jamais
re-taguée signifie que personne ne l'a fait tourner. Ce sont des pre-releases, elles
ne figent rien.

Tout ce qui est corrigé dans le package pendant ces trois migrations part dans la RC
suivante, avec une ligne dans le `CHANGELOG.md` du package concerné.
