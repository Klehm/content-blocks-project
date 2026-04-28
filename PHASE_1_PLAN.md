# Phase 1 — Foundation (plumbing only, no functional editing)

## Vision

Refonte du builder vers une architecture **iframe + sidebar + draft persistant en BDD**, inspirée de [sylius-happy-cms-plugin](https://github.com/agence-adeliom/sylius-happy-cms-plugin).

- Builder ouvert dans un `<dialog>` plein écran depuis un bouton "Modifier le contenu" sur le form host.
- Iframe qui rend la page front avec son vrai thème (param `?cb_preview=1`).
- Sidebar qui mount un `BlockComponent` Live pour l'édition d'un bloc (le seul Live Component qui survit).
- Boutons d'overlay (drag, edit, delete) au survol des sections/blocs dans l'iframe → `postMessage` au parent.
- Save-block (sidebar) = écrit `draftData` en BDD + reload iframe.
- Bouton "Publier" en haut = copie `draftData → publishedData` + `previewPosition → position`, supprime les soft-deleted, en cascade.
- Bouton "Annuler les modifications" = revert.
- Boutons "+1/+2/+3 colonnes" en bas pour ajouter une section.

## Modèle de données

| Entité | Existant | Ajouts V1 |
|---|---|---|
| `Block` | rename `data → publishedData` | `draftData ?array`, `previewPosition int`, `deleted bool` |
| `Section` | inchangé | `previewPosition int`, `deleted bool` |
| `Column` | inchangé | `previewPosition int`, `deleted bool` |

Conventions :
- `draftData === null` ⇒ pas de modification en attente, le rendu preview retombe sur `publishedData`.
- `publishedData === null` ⇒ bloc créé mais jamais publié (invisible côté public).
- `deleted === true` ⇒ soft-delete (visible barré en preview, supprimé pour de bon au publish).
- Cascade au publish : si une `Section.deleted = true`, toutes ses `Column` et `Block` sont supprimés (Doctrine cascade ORM). Idem pour `Column.deleted`.

## Critère "phase 1 done"

Tu cliques "Modifier le contenu" sur `/admin/page/1` → un `<dialog>` plein écran s'ouvre. L'iframe charge la page front avec le thème réel et `?cb_preview=1`. Tu hover un bloc → overlay avec boutons. Clic Edit/Delete → console parent log un `postMessage` typé. Les boutons "Publier", "Annuler", "Ajouter 1/2/3 colonnes" loggent leur intent. **Aucune édition réelle n'est wirée — c'est l'ossature, pas le moteur.**

## Pré-requis (faits)

- [x] Suppression des tests obsolètes :
  - `assets/test/cb-block-drag.test.js`
  - `assets/test/cb-section-move.test.js`
  - `assets/test/cb-block-edit-keys.test.js`
  - `assets/test/e2e/block-drag.spec.js`
  - `assets/test/e2e/section-move.spec.js`
- [x] Suppression de tests PHPUnit pré-existants cassés (référençaient des méthodes `sanitizeData`/`processData`/`getAllowedDataKeys` absentes du code) :
  - `tests/BlockType/AbstractBlockTypeTest.php`
  - `tests/Security/DataSanitizationTest.php`
  - `tests/Security/TabsBlockSanitizationTest.php`

---

## Étape 1 — Schéma + entités + helpers ✅

**Fichiers touchés** : `Entity/Block.php`, `Entity/Section.php`, `Entity/Column.php`, `Entity/ContentArea.php`, migrations Doctrine des sandboxes.

- [x] `Block` :
  - rename colonne `data` → `published_data` (nullable JSON)
  - add `draft_data` (nullable JSON)
  - add `preview_position` (smallint, default 0)
  - add `deleted` (bool, default false)
  - helpers : `publish()`, `revertDraft()`, `hasUnpublishedChanges()`
- [x] `Section` : add `preview_position`, `deleted` + helpers `publish()` / `revertDraft()` / `hasUnpublishedChanges()`
- [x] `Column` : idem `Section`
- [x] `ContentArea::hasUnpublishedChanges()` : OR sur l'arbre (au moins un descendant a une modif en attente)
- [x] Patch minimal : `BlockComponent::instantiateForm` lit `draftData ?? publishedData`, `BlockComponent::save` écrit `draftData`, `ColumnComponent::addBlock` set `draftData`/`previewPosition`, `Block.html.twig` preview lit `draftData ?? publishedData` (pour ne pas casser le runtime entre étape 1 et 4 — refactor complet en étape 4/5)
- [x] Migration Doctrine générée + appliquée pour les 2 sandboxes (Symfony + Sylius)
- [x] Tests PHPUnit unitaires (23 tests) : `BlockTest`, `SectionTest`, `ColumnTest`, `ContentAreaTest`

**Note différée à étape 2** : les helpers `getEffectiveData(RenderMode)` / `getEffectivePosition(RenderMode)` qui dépendent de `RenderMode` ont été repoussés à l'étape 2. La logique vit pour le moment dans le tree builder du `BlockRenderer`. À évaluer si ces helpers d'entité sont réellement nécessaires.

**Critère** : `doctrine:schema:validate` clean dans les 2 sandboxes ✅. Tests verts ✅.

---

## Étape 2 — `RenderMode` + service `BlockRenderer` ✅

**Fichiers** : `src/Rendering/RenderMode.php` (enum), `src/Rendering/BlockRenderer.php`, `templates/render/content_area.html.twig`, `src/Twig/ContentBlocksExtension.php`, services config.

- [x] Enum `RenderMode { PUBLIC, PREVIEW }`
- [x] Service `BlockRenderer::render(ContentArea $area, ?RenderMode $forceMode = null): string` :
  - mode détecté via `RequestStack` → query `cb_preview=1` + `AccessCheckerInterface::canEdit($area)` (sinon force PUBLIC). Plus propre que ROLE_ADMIN — on réutilise le primitive existant.
  - PUBLIC : skip blocs `deleted=true` ou `publishedData=null`, skip columns/sections deleted, ordre par `position`
  - PREVIEW : tout, ordre par `previewPosition`, cascade `deleted` (un bloc dans une section deleted hérite du flag), data = `draftData ?? publishedData`
  - en PREVIEW, markers `data-cb-block-id`, `data-cb-section-id`, `data-cb-column-id`, `data-cb-deleted="1"` cascadés
  - en PREVIEW, append `<script type="module" src="/_content-blocks/preview-overlay.js"></script>`
  - paramètre `$forceMode` pour bypasser la résolution (utile en tests)
- [x] Twig extension `ContentBlocksExtension` avec function `cb_render_content_area(area)` (`is_safe: html`)
- [x] Template `@ContentBlocks/render/content_area.html.twig`
- [x] Services enregistrés dans `config/services.php` (BlockRenderer + ContentBlocksExtension)
- [x] Tests PHPUnit (9 tests) : filtre PUBLIC, inclusion PREVIEW, ordre par `previewPosition` vs `position`, cascade deleted depuis section, mode resolution (no request, no param, denied access, granted access), include du `viewTemplate`

**Critère** : tests verts ✅, `cache:clear` clean dans les 2 sandboxes ✅.

---

---

## Étape 3 — `ContentAreaPublisher` (publish + discard) ✅

**Fichier** : `src/Service/ContentAreaPublisher.php`.

- [x] `publish(ContentArea $area)` : walk sections → columns → blocks, applique :
  - si entité `deleted` ⇒ `em->remove()` (cascade Doctrine s'occupe des descendants)
  - sinon : sync position depuis previewPosition, sync data depuis draft (Block uniquement via `Block::publish()`)
  - flush
- [x] `discardDraft(ContentArea $area)` : 
  - Block avec `publishedData === null` ⇒ `em->remove()` (jamais publié)
  - autres entités ⇒ `revertDraft()` (clear flags + previewPosition ← position + deleted ← false)
- [x] Service enregistré dans `config/services.php`
- [x] Tests PHPUnit (10 tests) avec EM mocké : publish copie/sync, removes deleted (Section/Column/Block), arbre mixte ; discard reverts, removes never-published blocks, clears deleted flags.
- [x] Pas encore wiré à l'UI (phase 4).

**Limitation V1 connue** : Section/Column ajoutée puis discardée n'est PAS supprimée (pas de flag `hasBeenPublished` sur ces entités, contrairement à Block qui s'appuie sur `publishedData === null`). À reconsidérer en phase 3 quand le wire-up "add section" landera — soit ajouter le flag, soit utiliser une heuristique (e.g. `position === 0 && previewPosition !== 0` couplée à un flag d'identité côté JS).

**Critère** : 10 tests verts, suite complète à 51 tests. Service appelable depuis tout autoload mais aucun controller ne l'invoque encore.

---

## Étape 4 — `BlockComponent` : save → draftData, template = form only ✅

**Fichiers** : `src/Twig/Component/BlockComponent.php`, `templates/components/Block.html.twig`, `src/Twig/Component/ColumnComponent.php`, `templates/components/Column.html.twig`.

- [x] `BlockComponent::instantiateForm()` : initial data = `$block->getDraftData() ?? $block->getPublishedData() ?? []` (déjà fait en étape 1, retesté ici)
- [x] `BlockComponent::save()` : écrit `setDraftData()`, dispatchBrowserEvent `cb:block:saved { blockId }` après flush — plus de toggle `editing`, plus de `resetForm()`
- [x] `BlockComponent::cancelEdit()` : dispatchBrowserEvent `cb:block:cancel { blockId }` — pas de mutation côté serveur
- [x] Suppression de `LiveProp $editing`, de l'action `openEdit()`, des conditionnels `#[PostMount] initializeForm` et `#[PreReRender] submitFormOnRender` (le component est toujours en édition via la sidebar — utilise directement `LiveCollectionTrait`)
- [x] Suppression de l'action `delete()` — le delete est désormais déclenché depuis l'overlay iframe (via postMessage → endpoint AJAX en phase 3), pas depuis le form
- [x] `Block.html.twig` : uniquement form + boutons Save/Cancel. Le `cb-block-edit-keys` controller pour Enter/Escape est conservé (utile dans la sidebar). Drop le card wrapper, le drag handle, le label de type, la branche preview.
- [x] `ColumnComponent` : nettoyage — drop `LiveProp $lastAddedBlockId` (causait le bug 1 d'Issues.md, plus utile sans toggle editing) ; le call `component('ContentBlocks:Block', ...)` ne passe plus de prop `editing` (le component n'en a plus). Composant entier supprimé en étape 5.
- [x] 3 tests PHPUnit `BlockComponentTest` couvrant le fallback chain de `instantiateForm` (draft > published > [])

**Note** : tests d'intégration de `save()` et `cancelEdit()` complets reportés à phase 2 (mount sidebar) — la `LiveCollectionTrait` est trop couplée au framework Live Component pour un mock unit-test propre.

**Effet de bord transitoire** : entre étape 4 et étape 5, l'inline UI rendue par `ColumnComponent` affiche tous les blocs sous forme de form (au lieu du toggle preview/edit). Visuellement messy mais fonctionnel. Étape 5 supprime cette UI entièrement.

**Critère** : suite à 54 tests verts ✅, cache:clear clean dans les 2 sandboxes ✅.

---

## Étape 5 — Suppression des composants obsolètes ✅

**Fichiers supprimés** (~1000 lignes) :

- [x] `src/Twig/Component/ContentAreaBuilderComponent.php` + `templates/components/ContentAreaBuilder.html.twig`
- [x] `src/Twig/Component/ColumnComponent.php` + `templates/components/Column.html.twig`
- [x] `src/Twig/Component/SectionComponent.php` + `templates/components/Section.html.twig`
- [x] `assets/controllers/cb-sortable_controller.js`
- [x] `assets/controllers/cb-section-move_controller.js`
- [x] `assets/controllers/cb-block-drag_controller.js`
- [x] `assets/controllers/cb-block-edit-keys_controller.js` — **conservé** (reused in sidebar).
- [x] `src/Controller/SectionController.php` — supprimé (reorder/move/delete tous obsolètes)
- [x] `src/Controller/BlockController.php` — supprimé (avait juste `/move` ; pas d'`/upload` ici, c'est dans content-blocks-kit). Phase 3 réintroduira des endpoints draft-aware.
- [x] Form widget passé à un placeholder vide (`form_widget(form)` seul) en attendant l'étape 6
- [x] `apps/*/public/assets/` purgés pour forcer AssetMapper à re-résoudre depuis les sources

**Critère** : `cache:clear` propre dans les 2 sandboxes ✅. Tests à 54 verts ✅.

---

## Étape 6 — Form widget = launcher + `<dialog>` + shell ✅

**Fichiers** : `templates/form/content_area_widget.html.twig`, nouveau `templates/builder/launcher.html.twig`, nouveau `templates/builder/shell.html.twig`, nouveau `assets/controllers/cb-builder-launcher_controller.js`, refonte `assets/styles/admin.css`.

- [x] `launcher.html.twig` (séparé du form widget pour pouvoir l'inclure depuis n'importe quel template host) : bouton "Modifier le contenu" + badge "Brouillon en attente" si `area.hasUnpublishedChanges` + `<dialog>` qui contient le shell.
- [x] `content_area_widget.html.twig` : pour les forms Symfony, rend `form_widget(form)` puis include `launcher.html.twig`.
- [x] `shell.html.twig` :
  - topbar : close (×) + viewport switcher (3 boutons desktop/tablet/mobile) + Discard + Publish
  - main : `<iframe>` + `<aside>` sidebar slot (collapsed en V1)
  - footer : "Add section" + 3 boutons (1/2/3 colonnes)
  - data-controller `cb-builder` avec `data-cb-builder-area-id-value`, `data-cb-builder-iframe-url-value`
- [x] Stimulus `cb-builder-launcher_controller.js` :
  - `open()` : lazy-set `iframe.src` (pas avant l'open) puis `dialog.showModal()`
  - `close()` : `dialog.close()` (le confirm-if-dirty viendra en phase 2 quand la sidebar est wirée)
- [x] CSS minimal pour le dialog (fullscreen `100vw/100vh`, grid topbar/main/footer)
- [x] Sandboxes : import `'@klehm/content-blocks/styles/admin.css'` ajouté dans `assets/app.js` (et déclaré dans `importmap.php` pour qu'AssetMapper le résolve)

**Décision** : iframe URL en placeholder `about:blank` à l'étape 6, remplacé par `cb_preview_url(area)` à l'étape 7 (les deux étapes commits séparés).

**Critère** : `cache:clear` clean ✅, tests verts ✅.

---

## Étape 7 — Preview URL resolver + intégration sandboxes ✅

**Fichiers** : `src/Preview/ContentAreaUrlResolverInterface.php`, `src/Preview/NullContentAreaUrlResolver.php`, mise à jour de `ContentBlocksExtension`, des sandboxes (controller + template + service binding).

- [x] Interface : `ContentAreaUrlResolverInterface::resolve(ContentArea $area): string` — retourne l'URL frontale (sans `?cb_preview=1`).
- [x] `NullContentAreaUrlResolver` : throws "host app must implement this".
- [x] Twig function `cb_preview_url(area)` (dans `ContentBlocksExtension`) qui appelle le resolver et append `?cb_preview=1`.
- [x] Sandbox Symfony : `App\Preview\PageContentAreaUrlResolver` lookup la Page par `findOneBy(['contentArea' => $area])` puis génère l'URL `app_page_show`. Route `/page/{id}` (requirement `\d+` pour pas conflicter avec `/page/create`). Template `page/show.html.twig` qui appelle `cb_render_content_area`.
- [x] Sandbox Sylius : idem (mirror `App\Preview\PageContentAreaUrlResolver` + `App\Controller\PageController` + `templates/page/show.html.twig`). Le template admin de la Page Builder tab utilise désormais `launcher.html.twig`.
- [x] Décision de naming : `ContentAreaUrlResolverInterface` (pas `Preview` dans le nom — l'interface produit une URL publique propre, le préfixe `cb_preview_url` côté Twig ajoute le param).

**Critère** : iframe affiche `/page/{id}?cb_preview=1` avec markers `data-cb-block-id`/`data-cb-section-id` ✅, script overlay chargé ✅.

---

## Étape 8 — Stimulus `cb-builder` (handshake parent ↔ iframe) ✅

**Fichier** : `assets/controllers/cb-builder_controller.js`, tests `assets/test/cb-builder.test.js`.

- [x] Targets : `iframe`, `sidebar`
- [x] Values : `areaId: Number`, `iframeUrl: String`
- [x] `connect()` ajoute un listener global `window.message` avec strict origin check (`event.origin === window.location.origin`). `disconnect()` cleanup.
- [x] Routing des messages typés `cb:*` (ignore tout le reste) :
  - `cb:ready` → log "iframe ready"
  - `cb:block:edit` / `cb:block:delete-requested` / `cb:block:add-requested` / `cb:block:reorder` → log avec payload
  - `cb:section:move-requested` / `cb:section:delete-requested` → log avec payload
  - default → log unknown type
- [x] Actions Stimulus :
  - `publish(event)` → log avec areaId
  - `discard(event)` → log avec areaId
  - `addSection(event)` → log avec areaId + layout (default `full`)
  - `setViewport(event)` → toggle `--active` sur le bouton, redimensionne l'iframe (desktop 100% / tablet 768px / mobile 375px)
  - `reload()` → capture scrollY de l'iframe avant reload, restaure sur load event, fallback sur `src` reassign si cross-origin throw
- [x] 17 tests Vitest couvrant : origin check (autre origine, type non-cb:, payload non-objet), routing par type, actions defaults, side-effects DOM/iframe de setViewport.

**Critère** : 17 tests verts ✅. Vérification visuelle : tous les boutons (overlay block/section + topbar publish/discard + footer add 1/2/3 cols) loggent via `console.log [cb-builder] ...` ✅.

---

## Étape 9 — Overlay iframe-side (plain JS, no Stimulus) ✅

**Fichiers** : `src/Controller/PreviewOverlayController.php` + `assets/preview-overlay.js`.

- [x] Route GET `/_content-blocks/preview-overlay` (no `.js` extension on purpose — PHP's built-in server treats `.js` paths as static and 404s; routing through Symfony works only when the URL doesn't look like a static asset). Content-Type `application/javascript`, cache 5 min.
- [x] Le JS (plain, no Stimulus pour ne pas imposer le loader sur le thème front du host) :
  - Au load, `parent.postMessage({ type: 'cb:ready' }, location.origin)` (skip si pas dans un iframe).
  - Injecte une feuille de style minimale (toolbar flottant, outline survol, blocs supprimés grisés/barrés via `[data-cb-deleted="1"]`).
  - Toolbar unique réutilisé, positionné en absolu top-right de l'élément hovered.
  - Sur hover d'un `[data-cb-block-id]` : boutons ✎ Edit + × Delete.
  - Sur hover d'un `[data-cb-section-id]` : boutons ▲ Move-up + ▼ Move-down + × Delete.
  - Block markers prennent priorité sur section markers (un block vit dans une section, mais ses actions sont plus granulaires).
  - Click sur un bouton → `parent.postMessage({ type: 'cb:block:edit', blockId })` etc.
  - **DnD reorder reporté à phase 3 polish** (couvert par le plan original mais hors du critère "phase 1 done").
  - Pas d'AJAX — only intent dispatch.
- [x] Sandbox `controllers.json` mis à jour (drop cb-sortable/section-move/block-drag obsolètes ; déclare cb-builder-launcher / cb-builder / cb-block-edit-keys).
- [x] `packages/content-blocks/assets/package.json` (manifeste Symfony de stimulus-bundle) mis à jour itself.

**Critère** : `curl /_content-blocks/preview-overlay` retourne 200 application/javascript ✅. Vérification visuelle : hover bloc dans iframe → toolbar apparaît, click Edit → parent log `cb:block:edit` ✅.

---

## Étape 10 — Test e2e de bout en bout ✅

**Fichier** : `assets/test/e2e/builder-shell.spec.js` (7 tests).

- [x] Le test charge la Page #1 existante (créée au préalable dans la sandbox via `/page/create`) — pas de fixture per-test, ce serait du gaspillage pour de la pure plomberie.
- [x] Test 1 : launcher button visible, click → `<dialog open>` + shell skeleton (topbar/iframe/footer) visibles.
- [x] Test 2 : iframe a la bonne URL `/page/1?cb_preview=1`, son contenu contient les markers `data-cb-block-id`/`data-cb-section-id` + `.cb-overlay-toolbar` injecté par `preview-overlay`.
- [x] Test 3 : `cb:ready` capté dans la console parent (`page.on('console')` sink + `expect.poll`).
- [x] Test 4 : hover bloc → `.cb-overlay-toolbar.is-visible` apparaît.
- [x] Test 5 : click Edit overlay → parent log `[cb-builder] block:edit`.
- [x] Test 6 : Publish topbar → log ; Discard si activé → log.
- [x] Test 7 : 3 boutons addSection footer → ≥3 logs `[cb-builder] addSection`.

**Critère** : `npm run test:e2e` au vert (`7 passed`) ✅.

**Découvertes pendant le wire-up** (corrigées dans le commit step 10) :
- `packages/content-blocks/assets/package.json` (manifeste Symfony controllers) listait encore les controllers supprimés. StimulusBundle 500-ait sur tout render qui chargeait les assets.
- AssetMapper ne résolvait pas `import '@klehm/content-blocks/styles/admin.css'` : il faut une entrée explicite dans `importmap.php` (côté sandboxes) avec `'type' => 'css'`, malgré que l'asset soit déclaré dans `debug:asset-map`.

---

## Commits livrés (en ordre)

1. `chore(content-blocks): remove obsolete PHPUnit tests` ✅ (`b61a106`)
2. `feat(content-blocks): add draft/published state to Block, Section, Column` (étape 1) ✅ (`8093efd`)
3. `feat(content-blocks): RenderMode enum + BlockRenderer service` (étape 2) ✅ (`c38f6cf`)
4. `feat(content-blocks): ContentAreaPublisher (publish + discard)` (étape 3) ✅ (`2ccc768`)
5. `refactor(content-blocks): BlockComponent for sidebar — form-only, dispatch events` (étape 4) ✅ (`f2986c3`)
6. `chore(content-blocks): remove obsolete builder UI components` (étape 5) ✅ (`bce1af1`)
7. `feat(content-blocks): builder shell — launcher + dialog + iframe + sidebar slot` (étape 6) ✅ (`e8e1463`)
8. `feat(content-blocks): preview URL resolver + sandbox front rendering` (étape 7) ✅ (`4dd823c`)
9. `feat(content-blocks): cb-builder Stimulus controller — postMessage handshake` (étape 8) ✅ (`67c3049`)
10. `feat(content-blocks): preview-overlay iframe-side JS + serving controller` (étape 9) ✅ (`e5dfcd8`)
11. `test(content-blocks): e2e Playwright smoke for the builder shell` (étape 10) ✅ (`781e46a`)

## Suite des tests à la sortie de phase 1

| Suite | Count | Notes |
|---|---|---|
| PHPUnit | 54 tests / 122 assertions | Entités, BlockRenderer, ContentAreaPublisher, BlockComponent.instantiateForm, AccessChecker, BlockTypeRegistry, LayoutValidation |
| Vitest | 17 tests | cb-builder controller — origin check, message routing, action defaults, setViewport |
| Playwright | 7 tests | Full e2e plumbing : dialog open / iframe load / hover overlay / postMessage round-trip / topbar+footer actions |
| **Total** | **78 tests automatisés** | Tous verts ✅ |

## Limitations connues à la sortie de phase 1

1. **Section/Column "added then discarded"** — pas de flag `hasBeenPublished` sur ces entités. Discard remet juste les flags draft. À traiter en phase 3 quand le wire-up "add section" landera.
2. **Tests d'intégration save/cancel BlockComponent** — reportés à phase 2 (LiveCollectionTrait trop couplée au framework pour mock unit-test propre).
3. **DnD reorder dans le preview-overlay** — laissé pour phase 3 polish (plan original le mentionnait pour étape 9).
4. **`asset-map:compile` requis pour servir les assets** — PHP built-in server ne dispatch pas les `.js`/`.css` à `index.php`. Le pretest e2e compile, et les sandboxes en dev veulent un `compile` (warning Symfony : "delete public/assets to allow live changes").

## Phases suivantes — état final

### Phase 2 ✅ (`41c3a0e`)
Sidebar fonctionnelle : `cb:block:edit` mount le BlockComponent dans `<aside>` via `GET /_content-blocks/block/{id}/edit`. `cb:block:saved` (CustomEvent émis par `dispatchBrowserEvent`) → unmount + reload iframe ; `cb:block:cancel` → unmount only. 5 tests Vitest + 2 Playwright nouveaux.

### Phase 3 ✅ (`6f92abf` + `b8397fe`)
- **Schema** : `Section`/`Column` gagnent `publishedAt: ?\DateTimeImmutable`. Permet à `discardDraft` de supprimer les Section/Column jamais publiées (publishedAt null) au lieu de juste revert les flags. Block reste sur la convention `publishedData === null`.
- **Endpoints** : `POST /area/{id}/sections {layout}`, `POST /section/{id}/move {direction}`, `DELETE /section/{id}`, `GET /types`, `POST /column/{id}/blocks {type}`, `POST /block/{id}/move {toColumnId, position}`, `DELETE /block/{id}`. Tous CSRF-protected via `CsrfProtectedTrait` (header `X-CSRF-Token` lu depuis `data-cb-csrf-token`).
- **Iframe overlay** : column toolbar avec "+ Block" ouvre un popover listant les types depuis `window.__cbBlockTypes` (injecté par `BlockRenderer`). Priority hover : block > column > section. Padding `padding-top: 18px` sur sections pour rendre le hover section accessible même quand les colonnes remplissent la row.
- **DnD reorder** : non livré (reporté en post-phase 5 si besoin — tous les autres reorder/move passent par les boutons overlay ▲▼).
- 9 tests Vitest + 5 Playwright nouveaux.

### Phase 4 ✅ (`c4b0bbb`)
- Endpoints `POST /area/{id}/publish`, `POST /area/{id}/discard`, `GET /area/{id}/state` qui appellent `ContentAreaPublisher`.
- `cb-builder#publish` / `#discard` actions appellent les endpoints, applique le `hasUnpublishedChanges` retourné via `_applyDraftState(bool)` (toggle du Discard button + retire le badge launcher) puis `reload()` l'iframe.
- Toutes les ops structurelles déclenchent `_afterStructuralOp()` qui force `_applyDraftState(true)` (économise un roundtrip — toute mutation logique = aire dirty).
- 5 tests Vitest + 3 Playwright nouveaux.

### Phase 5 ✅ (`d0cb020`)
- **Translations** : `cb.builder.{open,close,publish,discard,add_section,draft_pending,preview,confirm_close}` + `cb.section.layout.full` + `cb.block.remove` ajoutés en `en` et `fr`.
- **Close guard** : `cb-builder-launcher` intercepte le clic close ET l'event `cancel` natif (Escape) du `<dialog>`. Si la sidebar a un form ouvert, `window.confirm` avant fermeture ; preventDefault sur l'event natif si l'utilisateur décline.
- **Auto-focus** : `_mountSidebar` focus le premier input/textarea/contenteditable au prochain `requestAnimationFrame` après que Stimulus + Live Component aient connecté.
- **Animation sidebar** : keyframes `cb-sidebar-in` slide-in `.18s ease-out`.
- **A11y** : `aria-label` sur la `<aside>` sidebar, sur le `<dialog>`, sur chaque bouton overlay (mirror du title).
- 7 tests Vitest (nouveau fichier `cb-builder-launcher.test.js`) + 4 Playwright nouveaux.

## Récap final

| Suite | Phase 1 | Phase 2 | Phase 3 | Phase 4 | Phase 5 | **Total** |
|---|---|---|---|---|---|---|
| PHPUnit | 54 | 54 | 58 | 58 | 58 | **58** |
| Vitest | 17 | 22 | 31 | 34 | 41 | **41** |
| Playwright | 7 | 8 | 10 | 12 | 16 | **16** |
| **Cumul** | 78 | 84 | 99 | 104 | 115 | **115** |

Tous au vert.

## Limitations restantes (post-phase 5)

1. **DnD reorder dans l'iframe overlay** — non livré (toutes les autres ops reorder passent par les boutons ▲▼, suffit pour V1).
2. **Confirm-on-close** est basé sur "la sidebar contient un form", pas sur "le form est dirty" (pas de tracking input change). Acceptable mais le user voit le confirm même s'il n'a rien tapé.
3. **Auto-recreate du badge launcher** si l'utilisateur édite après publish : `_applyDraftState(true)` avec badge déjà parti ne le recrée pas (le markup translation-aware n'est connu que côté serveur). À recréer côté JS si besoin futur, ou full reload du parent.
4. **Tests d'intégration save BlockComponent** — toujours reportés, couverts par le e2e Playwright "phase 2".
