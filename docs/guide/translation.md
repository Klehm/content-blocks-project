# Translation (multilingual content)

Multilingual content areas live in a satellite package,
[`klehm/content-blocks-i18n`](https://github.com/klehm/content-blocks-i18n), so
the core stays single-language by default. Installing it changes nothing on its
own: with no translation rows and no target locale resolved, every block renders
its own data exactly as before.

```bash
composer require klehm/content-blocks-i18n
```

## The model in one sentence

**One shared layout, per-locale field values.** Sections, columns, block order
and styling are language-agnostic and stay shared; only fields a block type
tagged as translatable are swapped per locale.

That is a deliberate constraint, not a limitation waiting to be lifted. It means
a translated page cannot drift structurally from its source — move a section and
every language moves with it — and it means adding a language costs text, not a
second page to maintain.

## Configuration

```yaml
# config/packages/content_blocks_i18n.yaml
content_blocks_i18n:
    source_locale: en
    locales:
        - fr
        - { code: de, label: 'Deutsch' }
        - es
    workbench:
        public_links: true   # default; see "Links to each language"
```

The **source locale is not a target**. A block's `data` *is* the source text:
there is no row for it, nothing to fall back to, and a progress percentage on it
would be meaningless.

You also need the `cb_block_translation` table — the bundle maps the entity, the
migration is yours. Copy
[the sandbox's](https://github.com/klehm/content-blocks-project/blob/master/apps/content-blocks-sandbox/migrations/Version20260812120000.php).

## Tagging a field

Use the core's `cb_translatable` form option — the convention frozen at 1.0, so
your blocks and the kit's tag identically:

```php
$builder->add('title', TextType::class, [
    'label' => 'Title',
    'cb_translatable' => true,
]);
```

Tag what legitimately differs between languages: prose, labels, alt text,
captions, and link targets (a localized site points at `/fr/contact`). Leave out
enums, colors, sizes and IDs. A stored value for an untagged field is ignored at
render, so the tags remain the authority even after a release changes them.

## Rendering

Nothing in your templates changes. Route with a locale and Symfony's locale
listener does the rest:

```php
#[Route('/{_locale}/page/{id}', requirements: ['_locale' => 'fr|de|es'])]
public function show(int $id): Response { /* no mention of translation */ }
```

To pin a locale with no request in play — a sitemap job, a transactional email —
pass one through the render context:

```php
$renderer->render($area, RenderContext::forPublic('de'));
```

**Fallback is per field, not per block.** An untranslated field keeps its source
text while its neighbours render translated. The alternative makes a
half-translated page look broken rather than incomplete, and makes incremental
translation pointless since nothing shows until everything is done.

## Draft, published, and why translations have no buttons of their own

Translations are written to the **draft** and ride the area's existing Publish
and Discard.

This is the rule that prevents the failure the feature exists to avoid: a French
heading live on the public site describing an English heading that is still an
unpublished draft. Source and translations go live together, or not at all.

## Three states

| State | Meaning | Renders as |
|---|---|---|
| **Missing** | No value stored | the source text |
| **Translated** | Stored, source unchanged since | the translation |
| **Outdated** | Stored, but the source changed afterwards | the translation, flagged |

Outdated is tracked separately because it is the state that quietly rots.
"Translated vs not" is easy to compute and useless: the field that costs money is
the one that *was* translated and whose source has since been rewritten, because
nothing about the page looks wrong — the German is there, it is simply describing
last month's offer.

It is detected by storing a digest of the source text beside the translation.
Nothing else — no timestamps, no revision numbers — so editing an unrelated field
cannot perturb it. An editor who judges a translation still correct clicks
*"still current"* and the digest is re-stamped without retyping anything. A
staleness flag that can only be cleared by redoing finished work is a flag people
learn to ignore.

```bash
php bin/console content-blocks:i18n:status                       # every area, every locale
php bin/console content-blocks:i18n:status --locale=de --incomplete   # exit non-zero if not ready
```

## Machine translation

Register a provider and both the per-field button and the whole-page button work
through it:

```php
final class MyProvider implements TranslationProviderInterface
{
    public static function getName(): string { return 'mine'; }
    public function getLabel(): string { return 'My engine'; }
    public function supports(string $source, string $target): bool { return true; }

    /** @param list<TranslationRequest> $requests @return list<TranslationOutcome> */
    public function translate(array $requests, TranslationJob $job): array { /* … */ }
}
```

It is autoconfigured; implementing the interface is enough.

The contract is a **batch** on purpose. A page is 50–200 short strings, and one
HTTP call per string is slow enough that editors stop using the feature. A
per-field click passes a list of one, so there is no second code path to keep in
step. Return one outcome per request matched by `path`, throw only for
whole-batch failures, and never persist anything yourself — results go back
through the ordinary write gate, so the allow-list and the digests apply to
machine output exactly as they do to typing.

**The package ships no adapter for any engine**, on purpose. Which service a
page's text may be sent to — and whether it may leave the building at all — is a
decision about cost, quality and confidentiality that belongs to the host; it
should not arrive as a transitive dependency of a page builder. Same call as the
[LiipImagine integration](./recipes/liip-imagine): the seam belongs in the
package, a vendor in every host's `require` does not.

Writing one is a single class — implementing the interface is the whole
registration. **[Machine translation with LibreTranslate](./recipes/translation-provider.md)**
is the full worked recipe: a self-hosted engine, a complete adapter, and the
failure paths spelled out. The sandbox's
[`PseudoTranslationProvider`](https://github.com/klehm/content-blocks-project/blob/master/apps/content-blocks-sandbox/src/Translation/PseudoTranslationProvider.php)
is the smallest possible one: offline, deterministic, no credentials, and what
the demo and the e2e suite run on.

A real engine is the same shape. A translation API (DeepL, Google, Azure) or a
self-hosted one (LibreTranslate) maps almost directly onto it, since
`TranslationRequest::isHtml()` already says which calls need the engine's markup
mode. An **LLM** fits too, and can use context a dedicated engine ignores: that
this string is a button label rather than a heading, a glossary, a tone.

**With no provider registered, the workbench renders no machine-translation
affordance at all** — no ⚡ on a field, no "translate this page", no engine
picker. Manual translation is unaffected. A button that can only fail is worse
than an absent one, and a provider is also skipped for a page whose language
pair its `supports()` rejects.

Bulk, for starting a translation project without clicking through 200 pages:

```bash
php bin/console content-blocks:i18n:translate            # everything, every locale
php bin/console content-blocks:i18n:translate 42 --locale=de --overwrite
```

It writes to the draft — a machine pass is a first draft, not a release.

## How it is stored

A side table, `cb_block_translation`, one row per block per locale, holding a
flat map of field path to value:

```json
{"title": "Bienvenue", "items[9f2c1a].label": "Livraison rapide"}
```

Two decisions worth knowing when you go looking:

**A side table, not an envelope inside `Block.data`.** An envelope rides along
every clone and export for free; it is also opaque, so "which pages are missing
German?" would mean deserializing every block's JSON — and a multilingual site is
run from exactly that view. The cost is the mirror image: every flow that
duplicates or serializes a block has to be taught to carry its rows — which
`BlockCloneObserverInterface` (duplicate, insert-content) and
`ContentAreaTransferExtensionInterface` (export, import) make possible — plus a
prefetch so a translated page is one query rather than one per block.

In practice that means **an exported page comes back translated**: the JSON
carries a `extensions."content-blocks/i18n"` fragment holding each block's values
and staleness digests per locale, and importing it into an installation without
this package simply skips that fragment. Copy/paste and saved section templates
are the two flows that do **not** carry translations yet.

**Tab titles have their own table.** A section shown as tabs or as an
accordion puts its column names on the page, so the workbench lists them as
*Tabs* or *Accordion* entries before that
section's blocks, and they count in the progress bar. They are stored in
`cb_column_translation` and follow the same rules: draft until Publish,
duplicated with the section, carried by export/import, translatable by your
machine provider. Titles of a section shown side by side are not listed, since
no visitor sees them.

**Collection entries are keyed by `_id`, never by position.** Reordering,
duplicating or deleting a card shifts every position after it; keying per-entry
translations by index would attach the German title of card 1 to card 3. An entry
predating the `_id` backfill is skipped rather than guessed at — run
`content-blocks:backfill-collection-ids` to normalize it.

## The back arrow

The workbench's ← leads to the page itself by default — the URL your
`ContentAreaUrlResolverInterface` returns. When translators start from your
admin, send them back there instead by aliasing
`WorkbenchBackUrlResolverInterface`:

```php
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Workbench\WorkbenchBackUrlResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(WorkbenchBackUrlResolverInterface::class)]
final class AdminBackUrlResolver implements WorkbenchBackUrlResolverInterface
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function resolve(ContentArea $area, string $locale): string
    {
        $page = $this->pages->findOneBy(['contentArea' => $area]);

        return $this->urls->generate('admin_page_edit', ['id' => $page?->getId()]);
    }
}
```

The resolver receives the locale the workbench is open on, so it can return to a
per-language tab. It is the same shape as the preview resolver, and nothing in
the template needs overriding.

## Links to each language

The workbench topbar can link the **published** page in every language — `FR EN
DE ES`, the one being translated highlighted — so a translator checks the live
result without leaving the list. The package cannot build those URLs: one host
spells a locale as a path prefix, another as a subdomain. So nothing is linked
until the host implements `LocalizedPageUrlResolverInterface`:

```php
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\LocalizedPageUrlResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(LocalizedPageUrlResolverInterface::class)]
final class LocalizedPageUrlResolver implements LocalizedPageUrlResolverInterface
{
    public function resolve(ContentArea $area, string $locale): ?string
    {
        $page = $this->pages->findOneBy(['contentArea' => $area]);
        if ($page === null) {
            return null;
        }

        return $locale === 'en'   // the source locale
            ? $this->urls->generate('page_show', ['id' => $page->getId()])
            : $this->urls->generate('page_show_localized', ['_locale' => $locale, 'id' => $page->getId()]);
    }
}
```

It is called for the source locale and for each target. Returning `null` skips
that language. To hide the links while keeping the resolver — for another use,
or on one environment — set `content_blocks_i18n.workbench.public_links: false`.

## Adding to the workbench

The workbench template carries empty Twig blocks for a host to fill, without
copying the page:

```twig
{# templates/bundles/ContentBlocksI18nBundle/workbench/workbench.html.twig #}
{% extends '@!ContentBlocksI18n/workbench/workbench.html.twig' %}

{% block cb_wb_head %}
    <link rel="stylesheet" href="{{ asset('admin/workbench-theme.css') }}">
{% endblock %}
```

| Block | Where |
|---|---|
| `cb_wb_head` | End of `<head>` — a stylesheet redeclaring the tokens below |
| `cb_wb_topbar_left_end` | Left of the topbar, after the language pair |
| `cb_wb_topbar_right_start` | Right of the topbar, first |
| `cb_wb_topbar_right_end` | Right of the topbar, last |
| `cb_wb_end` | Last thing inside `.cb-wb` |

A block sees the page's variables — `area` and `locale` included. The page loads
none of the host's JavaScript, so a script added here has to be self-contained.

## Theming the workbench

The workbench is a standalone page served by the package, so it carries its own
stylesheet rather than reusing the builder's chrome tokens. Fifteen custom
properties on `:root` cover it, and redeclaring them is the supported way to make
it sit inside the host's admin:

| Group | Tokens |
|---|---|
| Surfaces | `--cb-wb-bg` (`#f4f6f8`), `--cb-wb-surface` (`#ffffff`), `--cb-wb-border` (`#dfe4ea`) |
| Text | `--cb-wb-text` (`#1f2933`), `--cb-wb-muted` (`#6b7785`) |
| Accent | `--cb-wb-accent` (`#0e7490`), `--cb-wb-accent-soft` (`#e0f2f7`) |
| Field states | `--cb-wb-ok` (`#15803d`), `--cb-wb-outdated` (`#b45309`), `--cb-wb-outdated-soft` (`#fef3c7`), `--cb-wb-missing` (`#9ca3af`), `--cb-wb-danger` (`#b91c1c`) |
| Geometry | `--cb-wb-radius` (`8px`), `--cb-wb-topbar-h` (`52px`), `--cb-wb-meter-h` (`56px`) |

The four field-state colors are the ones worth overriding first: they are what
tells a translator at a glance which fields are done, stale or missing, so they
should read as *your* admin's status colors rather than ours.

These names are public surface, covered by the package's semver guarantee.

## See also

- [Package README](https://github.com/klehm/content-blocks-i18n#readme) — the
  full HTTP API and provider contract
- [Custom blocks](./custom-blocks) — where `cb_translatable` goes
- [Content versioning](./content-versioning) — the other thing stamped on stored
  block data
