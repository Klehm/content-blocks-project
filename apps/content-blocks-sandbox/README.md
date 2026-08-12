# Content Blocks Sandbox

Application Symfony 7 minimale pour tester localement les bundles ContentBlocks via `path repositories`.

## Installation

```bash
composer install
```

## Utilisation

```bash
bin/console about
```

## Traduction de contenu (workbench)

La sandbox est configurée avec `fr` en source et `en` / `de` / `es` en cibles
(`config/packages/content_blocks_i18n.yaml`).

**Depuis le builder** : menu *Actions* de la topbar → **Translate this page**.
Le workbench s'ouvre dans un nouvel onglet — le builder garde son brouillon en
cours, et le workbench est une page à part entière.

**Par URL**, si on a l'id de la `ContentArea` :

```
/admin/translations/workbench/{areaId}/{locale}
```

La sandbox monte les routes du package sous `/admin/translations` plutôt qu'au
défaut `/_content-blocks/i18n` (cf. `config/routes/content_blocks_i18n.yaml`) :
tout ce qu'un éditeur atteint tient ainsi sous un seul chemin, ce dont un hôte a
besoin pour qu'un `firewall.pattern` le couvre. Les droits eux-mêmes ne changent
pas — le workbench appelle `canEdit()` sur la zone comme le builder.

Pour trouver une page qui a effectivement du texte à traduire — une zone sans
champ tagué `cb_translatable` non vide affiche un état vide, ce qui est le
comportement attendu, pas un bug :

```bash
# Avancement par zone et par locale (Total = champs traduisibles trouvés)
php bin/console content-blocks:i18n:status

# Page de démo publiée puis traduite dans chaque locale, en une commande
php bin/console app:i18n:demo --verify
```

**Traduction automatique** : la sandbox n'a qu'un provider, `pseudo`
(`src/Translation/`) — hors-ligne et déterministe, il préfixe le texte par
`[EN]`/`[DE]`/`[ES]`. C'est volontaire : ni le package ni la sandbox n'embarquent
de moteur tiers, brancher un vrai service est l'affaire de l'hôte
(`TranslationProviderInterface`, autoconfiguré). Retirer ce provider fait
disparaître les boutons ⚡ et « traduire la page » du workbench — c'est l'état
non configuré, celui que voit un hôte avant d'avoir branché le sien.
