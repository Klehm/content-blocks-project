# ContentBlocks i18n

Content translation for [ContentBlocks](https://github.com/klehm/content-blocks):
**one shared layout, per-locale field values.**

Sections, columns, block order and styling stay shared across languages — only
fields a block type tagged as translatable are swapped per locale. A translated
page therefore cannot drift structurally from its source: move a section and
every language moves with it.

Installing the bundle changes nothing on its own. With no translation rows and
no target locale resolved, every block renders its own data exactly as before.

```bash
composer require klehm/content-blocks-i18n
```

---

## Configuration

```yaml
# config/packages/content_blocks_i18n.yaml
content_blocks_i18n:
    # The language your block data itself is written in. It is never a
    # translation target and has no rows of its own — `Block.data` *is* the
    # source text.
    source_locale: en

    # What editors can translate into. Bare codes or { code, label } maps.
    locales:
        - fr
        - { code: de, label: 'Deutsch' }
        - es

    machine:
        # Optional: which of your registered providers runs when a caller names
        # none. There is nothing to configure here otherwise — the package ships
        # no translation engine, see "Machine translation" below.
        default: my_engine
```

Then create the table — see
[the sandbox migration](../../apps/content-blocks-sandbox/migrations/Version20260812120000.php)
for copy-ready SQL. The bundle maps the entity for you; the table is yours to
create.

---

## Marking fields translatable

A field opts in with the core's `cb_translatable` form option — the convention
frozen in ContentBlocks 1.0, so kit blocks and your own blocks tag identically:

```php
$builder->add('title', TextType::class, [
    'label' => 'Title',
    'cb_translatable' => true,
]);
```

Tag fields whose value legitimately **differs between languages**: prose
(headings, body copy, labels, alt text, captions) and link targets, since a
localized site routinely points at `/fr/contact` rather than `/contact`. Leave
out enums, colors, sizes and IDs — they read the same in every language, and a
stored value for an untagged field is ignored at render.

The kit already tags 29 fields across its blocks, so an installation using it
has something to translate on day one.

---

## Rendering a translated page

Nothing in your templates changes. The package reads the locale Symfony has
already negotiated:

```php
#[Route('/{_locale}/page/{id}', requirements: ['_locale' => 'fr|de|es'])]
public function show(int $id): Response
{
    // No mention of translation anywhere — the render pipeline resolves the
    // request locale and merges the stored values.
}
```

To pin a locale explicitly — a language switcher, a sitemap job, a
transactional email with no request at all — pass one through the core's
render context:

```php
$html = $renderer->render($area, RenderContext::forPublic('de'));
```

**Fallback is per field, not per block.** An untranslated field keeps its source
text while its neighbours render translated, so a half-translated page looks
incomplete rather than broken — and incremental translation shows something
before it is finished.

---

## The editorial model

### Draft and published

Translations ride the area's existing **Publish** and **Discard** buttons. They
are written to the draft and go live with the content they translate.

This is the rule that prevents the failure the feature exists to avoid: a French
heading live on the public site describing an English heading that is still an
unpublished draft.

### Three states, not two

| State | Meaning |
|---|---|
| **Missing** | No value stored; the field renders its source text. |
| **Translated** | Stored, and the source still hashes to what it did when it was written. |
| **Outdated** | Stored, but the source changed afterwards. |

Outdated is tracked separately because it is the expensive one. "Translated vs
not" is easy to compute and useless: the field that costs money is the one that
*was* translated and whose source has since been rewritten, because nothing
about the page looks wrong — the German is there, it is simply describing last
month's offer. It still renders (a stale translation beats an English paragraph
on a German page) and it is flagged so an editor can decide.

Staleness is a digest of the source text stored beside the translation. Nothing
else — no timestamps, no revision numbers — so saving an unrelated field cannot
perturb it. An editor who judges a translation still correct clicks "still
current" and the digest is re-stamped, without retyping anything.

### Progress

```bash
# Per area and locale, across the whole site
php bin/console content-blocks:i18n:status

# Gate a release on it
php bin/console content-blocks:i18n:status --locale=de --incomplete
```

---

## The workbench

The editorial UI: every translatable field of a page in one list, source beside
target, with the page itself previewed next to it.

```
/_content-blocks/i18n/workbench/{areaId}/{locale}
```

It is a **standalone page this package renders in full**, not a panel inside the
builder — the unit of work is the page, not the block, and the point is never to
leave the list. Link to it from wherever the host keeps its admin UI:

```twig
<a href="{{ cb_i18n_workbench_url(page.contentArea, 'de') }}">Translate</a>

{# Decorate a page list with per-locale progress — "DE 40%" #}
{% for code, progress in cb_i18n_progress(page.contentArea) %}
    {{ code }} {{ progress.percent }}%
{% endfor %}

{# Configured locales, source flagged, for a picker #}
{% for locale in cb_i18n_locales() %}{{ locale.label }}{% endfor %}
```

Four things about it are deliberate:

- **Rows are rendered server-side.** A translator's first action is to read and
  start typing; a spinner between the click and the first field is the latency
  the whole design removes. The JSON endpoints remain the API for everything
  else.
- **Saves are debounced and batched per block.** Tabbing through the four fields
  of a card produces one request, not four — which is why the save endpoint
  takes a batch. A pending edit is flushed on unload with `keepalive`, so a
  navigation cannot lose what is already on screen.
- **The preview updates one block at a time.** After a save exactly one block is
  re-fetched and swapped, so scroll position and any JS state in the preview
  survive the edit. Only a block type that answers `hotReload: false` forces a
  full frame reload.
- **The preview is the raw page**, in the language being translated: the host's
  own public URL loaded with `?cb_preview=1&cb_chrome=0&cb_locale=<target>`.
  `cb_chrome=0` is a core flag that keeps draft content but drops the builder's
  toolbars, tray and overlay script — they would be dead ends here, since this
  page has no builder sidebar to open. The locale rides the same way: the
  package appends a parameter to whatever `ContentAreaUrlResolverInterface`
  returned and a request listener turns it into the request locale, so this
  works whether the host spells locales as a path prefix, a subdomain or a
  stored preference, with no second resolver to implement. That parameter is
  honoured **only** on a request already in preview mode (which the core grants
  only after `canEdit()`), so it can never switch the language of a public page.

Its CSS and JS are served from the package. There is no Stimulus controller to
declare and nothing to recompile in a host's asset pipeline: the page never
loads the host's bundle, so a self-contained ES module works identically under
AssetMapper and Webpack Encore.

### Where the routes live

By default everything sits under `/_content-blocks/i18n`, matching the core. A
host that would rather mount it elsewhere — under its admin path, so one
firewall pattern covers it — imports the unprefixed route file and names its own
prefix:

```yaml
# config/routes/content_blocks_i18n.yaml
content_blocks_i18n:
    resource: '@ContentBlocksI18nBundle/config/routes/bare.php'
    prefix: /admin/translations        # → /admin/translations/workbench/{id}/{locale}
```

Route **names** are identical either way, so nothing else changes: the workbench
generates every URL its JavaScript calls with `path()` and hands them over on the
DOM. Authorization is unaffected by the choice — the workbench and its endpoints
call `AccessCheckerInterface::canEdit()` on the area exactly like the builder's
own endpoints, and the writing ones require the `content_blocks` CSRF token. A
prefix is about letting an authentication layer cover the routes, not about
access control on the content.

---

## Machine translation

Registering a provider is the whole integration. The per-field button and the
"translate this page" button both go through it.

```php
use ContentBlocks\I18n\Machine\TranslationProviderInterface;

final class MyProvider implements TranslationProviderInterface
{
    public static function getName(): string { return 'mine'; }
    public function getLabel(): string { return 'My engine'; }
    public function supports(string $source, string $target): bool { return true; }

    /** @param list<TranslationRequest> $requests @return list<TranslationOutcome> */
    public function translate(array $requests, TranslationJob $job): array
    {
        // one call, many strings
    }
}
```

It is autoconfigured — implementing the interface is enough.

**The contract is a batch, and that is the important part.** Translating a page
means 50–200 short strings; done one HTTP call at a time it is slow enough that
editors stop using it, and on a metered API it multiplies per-request overhead
by 200. A per-field click simply passes a list of one, so there is no second
code path and no way for the two to drift.

Three more rules worth knowing before writing one:

- **Return one outcome per request, matched by `path`.** Order is not trusted.
- **Throw only for whole-batch failures** (bad credentials, unreachable host).
  Per-string trouble is a failure *outcome*, so one rate-limited string does not
  discard the fifty that succeeded.
- **Never write anything.** Results go back through the ordinary write path, so
  the translatable-field allow-list and the digests apply to machine output
  exactly as they do to an editor's typing.

### What ships

**No engine ships with this package**, and that is deliberate. Where a page's
text is allowed to travel is a decision about cost, quality and confidentiality
that belongs to the host — it should not arrive as a transitive dependency of a
page builder, and no default here could be right for everyone. The only provider
in the box is `null`: it answers every request with `no_provider_configured`
rather than throwing, so the API and the CLI on an unconfigured installation
look unconfigured instead of broken.

**[Machine translation with LibreTranslate](https://klehm.github.io/content-blocks-project/guide/recipes/translation-provider)**
is the worked recipe: a self-hosted, no-account engine behind a complete
adapter, with the failure paths spelled out. The sandbox's
[`PseudoTranslationProvider`](../../apps/content-blocks-sandbox/src/Translation/PseudoTranslationProvider.php)
is the smallest possible one — offline, deterministic, no credentials, and what
the demo and the e2e suite run on. Both are a single class, because implementing
the interface *is* the whole registration.

A real engine is the same shape. A translation API (DeepL, Google, Azure) or a
self-hosted one (LibreTranslate) maps almost directly onto it —
`TranslationRequest::isHtml()` already tells you which calls need the engine's
markup mode, and the batch signature is what those APIs want anyway. An **LLM**
fits too, and can use context the request already carries that a dedicated
engine ignores: that a string is a button label rather than a heading, a
glossary, a tone.

### When nothing is registered

The workbench renders **no machine-translation affordance at all**: no ⚡ button
on a field, no "translate this page", no engine picker. Typing, "still current"
and reset are untouched. A provider is also skipped for a page whose language
pair its `supports()` rejects, so an engine that covers European languages does
not offer a button on the Japanese column.

### Bulk

```bash
# Every area, every configured locale, written to the draft
php bin/console content-blocks:i18n:translate

# One page, one language, re-translating even what is already correct
php bin/console content-blocks:i18n:translate 42 --locale=de --overwrite
```

A bulk machine pass is a first draft, not a release: it writes to the draft, so
nothing is public until each area is published.

---

## HTTP API

All under `/_content-blocks/i18n`, so a host firewall pattern covering the
builder covers these too. Writes require the `content_blocks` CSRF token and
pass `canEdit()` on the target area.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/area/{id}/locales` | Configured locales + per-locale progress |
| `GET` | `/area/{id}/fields/{locale}` | Every translatable field of the page, in reading order |
| `POST` | `/block/{id}/{locale}` | Save a batch of values |
| `POST` | `/block/{id}/{locale}/approve` | Re-stamp digests ("still current") |
| `POST` | `/block/{id}/{locale}/translate` | Machine-translate one block or named fields |
| `POST` | `/area/{id}/{locale}/translate` | Machine-translate the page |
| `GET` | `/providers` | Registered providers, for a picker |
| `GET` | `/workbench/{id}/{locale}` | The workbench page itself |

On save, **`null` clears** a translation (the field falls back to the source)
and **`""` stores a deliberate blank**. The distinction is load-bearing: a card
carrying an optional subtitle in English and none in German needs the blank,
because clearing would fall back and print the English subtitle on the German
page.

---

## How it is stored

A side table, `cb_block_translation`, one row per block per locale, holding a
flat map of field path to value:

```json
{"title": "Bienvenue", "items[9f2c1a].label": "Livraison rapide"}
```

Two decisions are worth knowing about:

**A side table rather than an envelope inside `Block.data`.** An envelope rides
along every clone and export for free; it is also opaque, so "which pages are
missing German?" would mean deserializing every block's JSON. A multilingual
site is run from exactly that view. The cost is the mirror image — every flow
that duplicates a block has to duplicate its rows, which the core's
`BlockCloneObserverInterface` seam makes possible — and a prefetch on the render
path so a translated page stays one query rather than one per block.

**Collection entries are keyed by their `_id`, never by position.** Reordering,
duplicating or deleting a card shifts every position after it; keying per-entry
translations by index would silently attach the German title of card 1 to card
3. An entry that predates the `_id` backfill is skipped rather than guessed at —
run `content-blocks:backfill-collection-ids` to normalize it.

---

## Requirements

- PHP >= 8.2
- `klehm/content-blocks` ^0.1
- Symfony 6.4 LTS, 7.x or 8.x

No HTTP client, no vendor SDK: the package talks to no third-party service. A
machine-translation adapter is the host's, and brings its own dependencies.

## Documentation & contributing

Full documentation and development setup live in the monorepo:
[github.com/klehm/content-blocks-project](https://github.com/klehm/content-blocks-project)

**Backward compatibility.** From `1.0.0`, what is covered by semver — and what is deliberately not — is listed in the
[backward compatibility page](https://klehm.github.io/content-blocks-project/guide/backward-compatibility).
Anything tagged `@internal` sits outside the promise.

## License

MIT
