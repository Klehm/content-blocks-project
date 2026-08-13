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

### A.2 Les cinq ruptures qui comptent sur beta.5 → RC

88 commits séparent beta.5 de master. Ce qui casse réellement chez ces hôtes :

- **`BlockRendererInterface` prend un `RenderContext`, plus un `RenderMode`.**
  `render(ContentArea, ?RenderContext)`, idem `renderBlock()` / `renderSection()`.
  Ne concerne que qui appelle ou **décore** le renderer — vérifier les extensions
  EFS en priorité.
- **Le défaut de fond est passé à transparent.** `CoreStylingDefaults` /
  `CoreBlockStylingDefaults` valent `''`. Conséquence sournoise : **un `#ffffff`
  déjà persisté rend maintenant un vrai fond blanc**, là où le hack d'avant le
  rendait invisible. À contrôler en base avant/après :
  ```sql
  SELECT id, type FROM cb_block WHERE JSON_EXTRACT(data, '$.styling.backgroundColor') = '#ffffff';
  ```
  EFS forçait déjà `''` via des defaults providers basse priorité — ce contournement
  devient inutile et doit sauter.
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

### A.4 Recompiler et vérifier

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
| | | | |

---

## 7. Attendus côté RC

Ces trois hôtes **sont** les testeurs de la RC — il n'y en aura pas d'autres. Il est
donc normal et sain qu'ils produisent une RC2, voire une RC3 : une RC jamais
re-taguée signifie que personne ne l'a fait tourner. Ce sont des pre-releases, elles
ne figent rien.

Tout ce qui est corrigé dans le package pendant ces trois migrations part dans la RC
suivante, avec une ligne dans le `CHANGELOG.md` du package concerné.
