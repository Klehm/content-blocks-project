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

## Étape 5 — Suppression des composants obsolètes

**Fichiers supprimés** :

- [ ] `src/Twig/Component/ContentAreaBuilderComponent.php` + `templates/components/ContentAreaBuilder.html.twig`
- [ ] `src/Twig/Component/ColumnComponent.php` + `templates/components/Column.html.twig`
- [ ] `src/Twig/Component/SectionComponent.php` + `templates/components/Section.html.twig`
- [ ] `assets/controllers/cb-sortable_controller.js`
- [ ] `assets/controllers/cb-section-move_controller.js`
- [ ] `assets/controllers/cb-block-drag_controller.js`
- [ ] `assets/controllers/cb-block-edit-keys_controller.js` — **conservé** : utile dans la sidebar (raccourcis Enter/Escape sur le form). Réutilisé tel quel par le template form-only de l'étape 4.
- [ ] `src/Controller/SectionController.php` — endpoint reorder/move retirés (les ops layout passent en phase 3 via nouveaux endpoints)
- [ ] Nettoyage des routes correspondantes dans `config/routes.php`
- [ ] Vérifier qu'on ne casse pas `BlockController.php` (qui sert à `move` block et `upload`) — garder `upload`, supprimer `move` (réintroduit en phase 3).

**Critère** : `composer dump-autoload && php bin/console cache:clear` propre. Pas d'erreur de référence morte. Le builder actuel est cassé visuellement (c'est attendu, on le remplace dans les étapes suivantes).

---

## Étape 6 — Form widget = launcher + `<dialog>` + shell

**Fichiers touchés/nouveaux** : `templates/form/content_area_widget.html.twig`, nouveau `templates/builder/shell.html.twig`, nouveau `assets/controllers/cb-builder-launcher_controller.js`.

- [ ] `content_area_widget.html.twig` :
  - bouton "Modifier le contenu" + badge "Brouillon en attente" si `area.hasUnpublishedChanges`
  - `<dialog>` avec lazy iframe (src vide initial, set par le launcher à l'open)
  - data-controller `cb-builder-launcher`
- [ ] `shell.html.twig` :
  - topbar : "Publier" + "Annuler les modifications" + viewport switch (mockup, pas wiré)
  - main : `<iframe data-cb-builder-target="iframe">` + `<aside data-cb-builder-target="sidebar" hidden></aside>`
  - footer : "Ajouter une section" + 3 boutons (1/2/3 colonnes)
  - data-controller `cb-builder` avec `data-cb-builder-area-id-value`, `data-cb-builder-iframe-url-value`
- [ ] Stimulus `cb-builder-launcher_controller.js` :
  - `open()` : set iframe src + `dialog.showModal()`
  - `close()` : prompt si dirty (sidebar en cours d'édition), sinon `dialog.close()`
- [ ] CSS minimal pour le dialog (plein écran, layout topbar/main/footer)

**Critère** : ouvrir `/admin/page/1`, cliquer "Modifier le contenu" → `<dialog>` ouvre, iframe charge l'URL preview. Aucun comportement runtime au-delà.

---

## Étape 7 — Preview URL resolver + intégration sandboxes

**Nouveaux** : `src/Preview/ContentAreaPreviewUrlResolverInterface.php`, `src/Preview/NullContentAreaPreviewUrlResolver.php`, Twig function `cb_preview_url`.

- [ ] Interface : `resolve(ContentArea $area): string` — retourne l'URL frontale qui rend le contenu (sans le `?cb_preview=1`, c'est `BlockRenderer` qui l'ajoutera côté request).
- [ ] `NullContentAreaPreviewUrlResolver` : throws "implement this in your app".
- [ ] Twig function `cb_preview_url(area)` qui appelle le resolver et append `?cb_preview=1` automatiquement.
- [ ] Sandbox Symfony :
  - implémente le resolver dans `src/Preview/PagePreviewUrlResolver.php` qui retourne l'URL `app_page_show` (route à créer si absente)
  - route `/page/{id}` qui rend la page avec `{{ cb_render_content_area(page.contentArea) }}` dans un layout front
  - bind l'interface dans `services.yaml`
- [ ] Sandbox Sylius : idem, en s'appuyant sur le pattern Sylius `PageController`.
- [ ] Test e2e Playwright : ouvrir le builder via le launcher dans la sandbox, vérifier que l'iframe charge bien l'URL `/page/1?cb_preview=1`.

**Critère** : iframe affiche le contenu de la Page avec son thème, pas le shell admin. Markers `data-cb-block-id` présents. Script overlay chargé.

---

## Étape 8 — Stimulus `cb-builder` (handshake parent ↔ iframe)

**Nouveau** : `assets/controllers/cb-builder_controller.js`.

- [ ] Targets : `iframe`, `sidebar`
- [ ] Values : `areaId: Number`, `iframeUrl: String`
- [ ] À la connexion, listen `window.addEventListener('message', this._onMessage)` avec origin check (`event.origin === window.location.origin`).
- [ ] Handlers (juste log + dispatch d'event JS) :
  - `cb:ready` (iframe prête)
  - `cb:block:edit` { blockId } → log
  - `cb:block:add-requested` { columnId, blockType } → log
  - `cb:block:delete-requested` { blockId } → log
  - `cb:block:reorder` { blockId, fromColumnId, toColumnId, position } → log
  - `cb:section:move-requested` { sectionId, direction } → log
  - `cb:section:delete-requested` { sectionId } → log
- [ ] Actions Stimulus :
  - `publish()` → log
  - `discard()` → log
  - `addSection({ params: { layout } })` → log
  - `reload()` : capture `iframe.contentWindow.scrollY`, `iframe.contentWindow.location.reload()`, sur `load` event suivant restaure scroll.
- [ ] Tests Vitest unitaires : message handler dispatch correct, origin check rejette mauvaises origines, reload restaure scroll.

**Critère** : ouvrir le builder, hover un bloc dans l'iframe, cliquer sur l'overlay Edit → log dans la console parent : `cb:block:edit { blockId: 42 }`. Idem pour les autres actions.

---

## Étape 9 — Overlay iframe-side (plain JS, no Stimulus)

**Nouveaux** : `src/Asset/PreviewOverlayController.php` (route servant le JS) + `assets/preview-overlay.js` (source plain JS).

- [ ] Route GET `/_content-blocks/preview-overlay.js` qui sert le JS avec `Content-Type: application/javascript` et cache court (5 min en dev). Le JS est inline dans la PHP / lu depuis un asset avec hash.
- [ ] Le script :
  - Au load, `parent.postMessage({ type: 'cb:ready' }, location.origin)`.
  - Crée un container overlay absolu en `position: fixed` (ou injecté dans le body).
  - Sur `mouseenter` d'un `[data-cb-block-id]` : positionne un overlay flottant avec boutons Edit / Delete / handle DnD.
  - Sur `mouseenter` d'un `[data-cb-section-id]` : overlay sur la section avec boutons Move-up / Move-down / Delete.
  - Sur clic d'un bouton overlay : `parent.postMessage({ type: 'cb:block:edit', blockId: ... }, location.origin)` etc.
  - Sur DnD natif HTML5 sur les blocs (handle), à la fin du drop : `parent.postMessage({ type: 'cb:block:reorder', ... })`.
  - Pas d'AJAX dans ce script — il ne fait que dispatcher des intents au parent.
- [ ] CSS minimal pour l'overlay (z-index élevé, transition opacity, no-interference avec le contenu rendu).

**Critère** : ouvrir le builder dans la sandbox, hover un bloc dans l'iframe → overlay s'affiche au-dessus avec boutons. Clic sur un bouton → console parent log l'event.

---

## Étape 10 — Test e2e de bout en bout

**Nouveau** : `assets/test/e2e/builder-shell.spec.js`.

- [ ] Avant chaque test : créer une Page avec quelques sections+blocs en BDD via fixture.
- [ ] Test : ouvrir `/admin/page/{id}`, cliquer "Modifier le contenu", attendre `<dialog>` open, attendre iframe `cb:ready`, vérifier markers `data-cb-block-id` dans iframe.
- [ ] Test : hover un bloc dans iframe → overlay visible.
- [ ] Test : clic Edit dans iframe → parent reçoit `cb:block:edit` (instrumenté via un `console.log` capturé).
- [ ] Test : clic "Publier" / "Annuler" → log respectif.
- [ ] Test : clic "Ajouter 1 colonne" → log `cb:section:add-requested layout=full`.

**Critère** : `npm run test:e2e` passe au vert. Aucun comportement runtime réel n'est testé (rien ne se sauve), juste la plomberie.

---

## Ordre de commits suggéré

1. `chore(content-blocks): remove obsolete tests` ✅
2. `feat(content-blocks): add draft/published columns to Block, Section, Column` (étape 1) ✅
3. `feat(content-blocks): RenderMode enum + BlockRenderer service` (étape 2) ✅
4. `feat(content-blocks): ContentAreaPublisher (publish + discard)` (étape 3) ✅
5. `refactor(content-blocks): BlockComponent writes to draftData, form-only template` (étape 4) ✅
6. `refactor(content-blocks): BlockComponent writes to draftData, form-only template` (étape 4)
7. `chore(content-blocks): remove builder Live Components + Stimulus controllers` (étape 5)
8. `feat(content-blocks): builder shell — launcher button + dialog + iframe` (étape 6)
9. `feat(content-blocks): preview URL resolver + sandbox integration` (étape 7)
10. `feat(content-blocks): cb-builder controller — postMessage handshake` (étape 8)
11. `feat(content-blocks): preview-overlay (iframe-side hover/actions)` (étape 9)
12. `test(content-blocks): e2e builder shell` (étape 10)

## Pour les phases suivantes

- **Phase 2** : sidebar fonctionnelle. Mount `BlockComponent` dans `<aside>` au reçu de `cb:block:edit`, save bloc → reload iframe.
- **Phase 3** : ops structurelles AJAX (add section, delete section, move section, add block, delete block, reorder block). Endpoints écrivent en draft/preview, iframe reload après chaque op.
- **Phase 4** : "Publier" + "Annuler les modifications" wirés au `ContentAreaPublisher`. Badge "modifications en attente" dynamique. Soft-deleted blocks rendus barrés en preview.
- **Phase 5** : polish — viewport switcher, raccourcis clavier dans la sidebar, transitions, accessibilité.
