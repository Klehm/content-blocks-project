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

**DeepL ships with the package** (declare `machine.deepl.api_key`). An **LLM**
fits the same seam and can use the context the request already carries — that
this string is a button label rather than a heading, a glossary, a tone;
[`ClaudeTranslationProvider`](https://github.com/klehm/content-blocks-project/blob/master/apps/content-blocks-sandbox/src/Translation/ClaudeTranslationProvider.php)
in the sandbox is a working example. Vendor adapters live in the sandbox rather
than the package for the same reason the [LiipImagine
integration](./recipes/liip-imagine) is a recipe: the seam belongs in the
package, a vendor SDK in every host's `require` does not.

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
duplicates a block has to duplicate its rows (which
`BlockCloneObserverInterface` makes possible), plus a prefetch so a translated
page is one query rather than one per block.

**Collection entries are keyed by `_id`, never by position.** Reordering,
duplicating or deleting a card shifts every position after it; keying per-entry
translations by index would attach the German title of card 1 to card 3. An entry
predating the `_id` backfill is skipped rather than guessed at — run
`content-blocks:backfill-collection-ids` to normalize it.

## See also

- [Package README](https://github.com/klehm/content-blocks-i18n#readme) — the
  full HTTP API and provider contract
- [Custom blocks](./custom-blocks) — where `cb_translatable` goes
- [Content versioning](./content-versioning) — the other thing stamped on stored
  block data
