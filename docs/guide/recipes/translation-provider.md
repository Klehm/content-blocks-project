---
title: Machine translation with LibreTranslate
---

# Wire a machine-translation engine (LibreTranslate)

`klehm/content-blocks-i18n` ships the translation *seam* and no engine behind it. That is deliberate: which service a page's text may be sent to — and whether it may leave the building at all — is a decision about cost, quality and confidentiality that belongs to the application, and it should not arrive as a transitive dependency of a page builder. Same call as the [LiipImagine recipe](./liip-imagine.md).

This recipe wires [LibreTranslate](https://github.com/LibreTranslate/LibreTranslate) behind [`TranslationProviderInterface`](../translation.md#machine-translation). It is a good default for the "just make it work" case: **AGPL, self-hosted, no account, no quota, and the content never leaves your infrastructure** — which for a page builder used on client sites is often the deciding argument rather than a nice-to-have.

**One class is the entire opt-in.** No service definition, no configuration node: Symfony autoconfigures anything implementing the interface, and the workbench picks it up on its next render.

## 1. Run the engine

```bash
docker run -d --name libretranslate -p 5000:5000 \
  -e LT_LOAD_ONLY=en,fr,de,es \
  libretranslate/libretranslate
```

`LT_LOAD_ONLY` is worth setting: without it the container downloads every language model at first boot (a few GB and several minutes). Restrict it to the locales in your `content_blocks_i18n.locales` plus the source.

Check it answers:

```bash
curl -s localhost:5000/languages | head -c 200
```

## 2. The provider

One file, `src/Translation/LibreTranslateProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Translation;

use ContentBlocks\I18n\Machine\TranslationJob;
use ContentBlocks\I18n\Machine\TranslationOutcome;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use ContentBlocks\I18n\Machine\TranslationRequest;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

final class LibreTranslateProvider implements TranslationProviderInterface
{
    /**
     * Strings per request. LibreTranslate takes an array for `q`, so the batch
     * contract maps onto it directly; chunking only keeps one payload — and one
     * retry — a reasonable size.
     */
    private const CHUNK = 50;

    /**
     * @param array<string, string> $localeMap host locale => LibreTranslate code,
     *                                         for the cases stripping the region gets wrong
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl = 'http://127.0.0.1:5000',
        private readonly ?string $apiKey = null,
        private readonly array $localeMap = [],
    ) {
    }

    public static function getName(): string
    {
        return 'libretranslate';
    }

    public function getLabel(): string|TranslatableInterface
    {
        return 'LibreTranslate';
    }

    /**
     * Optimistic on purpose: answering precisely would mean shipping (and
     * maintaining) the instance's language list, and this is called on every
     * workbench render. See the caveats below for the `/languages` variant.
     */
    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return $this->languageCode($sourceLocale) !== $this->languageCode($targetLocale);
    }

    public function translate(array $requests, TranslationJob $job): array
    {
        if ($requests === []) {
            return [];
        }

        $outcomes = [];

        // Grouped by format because `format` is per-call, then chunked because
        // payload size is per-call too.
        foreach ($this->groupByFormat($requests) as $format => $group) {
            foreach (array_chunk($group, self::CHUNK) as $chunk) {
                foreach ($this->translateChunk($chunk, $format, $job) as $outcome) {
                    $outcomes[] = $outcome;
                }
            }
        }

        return $outcomes;
    }

    /**
     * @param  list<TranslationRequest>               $requests
     * @return array<string, list<TranslationRequest>>
     */
    private function groupByFormat(array $requests): array
    {
        $groups = [];

        foreach ($requests as $request) {
            $groups[$request->isHtml() ? TranslationRequest::FORMAT_HTML : TranslationRequest::FORMAT_TEXT][] = $request;
        }

        return $groups;
    }

    /**
     * @param  list<TranslationRequest> $chunk
     * @return list<TranslationOutcome>
     */
    private function translateChunk(array $chunk, string $format, TranslationJob $job): array
    {
        $body = [
            'q' => array_map(static fn (TranslationRequest $r): string => $r->text, $chunk),
            'source' => $this->languageCode($job->sourceLocale),
            'target' => $this->languageCode($job->targetLocale),
            // The package's own constants are already LibreTranslate's spelling.
            'format' => $format,
        ];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $body['api_key'] = $this->apiKey;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/translate', [
                'json' => $body,
            ]);

            $status = $response->getStatusCode();
            // `false` = do not throw on 4xx/5xx: the body carries LibreTranslate's
            // own error message, which is worth showing the editor.
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            return $this->failAll($chunk, 'transport_error: ' . $e->getMessage());
        }

        if ($status >= 400) {
            $message = \is_string($payload['error'] ?? null) ? $payload['error'] : 'http_' . $status;

            return $this->failAll($chunk, $message);
        }

        $translations = $payload['translatedText'] ?? null;

        // An array `q` comes back as an array. Older builds answer a bare
        // string when exactly one string went out.
        if (\is_string($translations) && \count($chunk) === 1) {
            $translations = [$translations];
        }

        if (!\is_array($translations)) {
            return $this->failAll($chunk, 'malformed_response');
        }

        $outcomes = [];

        foreach ($chunk as $index => $request) {
            // Positional match — the one place order matters, and the reason
            // the outcome carries the path back explicitly.
            $text = $translations[$index] ?? null;

            $outcomes[] = \is_string($text)
                ? TranslationOutcome::success($request->path, $text)
                : TranslationOutcome::failure($request->path, 'missing_translation');
        }

        return $outcomes;
    }

    /**
     * @param  list<TranslationRequest> $chunk
     * @return list<TranslationOutcome>
     */
    private function failAll(array $chunk, string $error): array
    {
        return array_map(
            static fn (TranslationRequest $r): TranslationOutcome => TranslationOutcome::failure($r->path, $error),
            $chunk,
        );
    }

    /** `fr_FR` / `fr-FR` → `fr`. LibreTranslate speaks bare language codes. */
    private function languageCode(string $locale): string
    {
        if (isset($this->localeMap[$locale])) {
            return $this->localeMap[$locale];
        }

        return strtolower(explode('-', str_replace('_', '-', $locale))[0]);
    }
}
```

## 3. Point it at your instance

With autowiring on, the defaults already work for a container on localhost. To name the URL — and a key, if your instance requires one:

```yaml
# config/services.yaml
services:
    App\Translation\LibreTranslateProvider:
        arguments:
            $baseUrl: '%env(LIBRETRANSLATE_URL)%'
            $apiKey: '%env(default::LIBRETRANSLATE_API_KEY)%'
```

Needs `symfony/http-client` (`composer require symfony/http-client`) — the package itself has no HTTP client in its dependencies, precisely because it talks to nothing.

If you registered more than one engine, pick which one runs when a caller names none:

```yaml
# config/packages/content_blocks_i18n.yaml
content_blocks_i18n:
    machine:
        default: libretranslate
```

## 4. Try it

```bash
# One page, one language, to the draft
php bin/console content-blocks:i18n:translate 42 --locale=de
```

Then open the workbench: the ⚡ button and "translate this page" are now rendered. Before this class existed they were not — with no usable provider the workbench shows no machine-translation affordance at all, so the appearance of the buttons *is* the confirmation that registration worked.

Results land in the **draft**, like everything else here: a machine pass is a first draft, and it goes live only when someone publishes the area.

## What the provider is and is not responsible for

The interface is small because the surrounding work is already done. A provider **translates strings and reports outcomes** — nothing else:

- **Never write anything.** Results go back through the ordinary write path, so the translatable-field allow-list and the source digests apply to machine output exactly as they do to an editor's typing. A provider that persisted its own results would bypass every check.
- **Return one outcome per request, matched by `path`.** Order is not trusted by the caller; the positional match above is internal to one HTTP call.
- **Per-string trouble is a failure *outcome*, not an exception.** One rate-limited string must not discard the forty-nine that succeeded — the editor would have no way to tell which half to redo. The three failure paths above (bad key, unreachable host, malformed body) all return outcomes carrying a message the workbench shows in its snackbar.
- **Respect `format`.** A request marked HTML holds markup; returning escaped or tag-stripped text corrupts the block. That is the whole reason for the grouping pass — `format` is per-call in LibreTranslate, as `tag_handling` is in DeepL.

## Two operational caveats

**`supports()` runs on every workbench render.** The workbench asks each registered provider whether it covers the page's language pair, and hides the buttons for those that say no. Keep the answer cheap. If one instance genuinely covers only a few pairs and you want the picker to reflect that, query `/languages` — but cache it, or you have added an HTTP round-trip to every page load:

```php
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly CacheInterface $cache,
    // …
) {
}

public function supports(string $sourceLocale, string $targetLocale): bool
{
    $source = $this->languageCode($sourceLocale);
    $target = $this->languageCode($targetLocale);

    if ($source === $target) {
        return false;
    }

    $codes = $this->cache->get('libretranslate.languages', function (ItemInterface $item): array {
        $item->expiresAfter(3600);

        try {
            $payload = $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/languages')->toArray(false);
        } catch (HttpExceptionInterface) {
            return [];   // unknown ≠ unsupported: stay optimistic, fail per request
        }

        return array_column($payload, 'code');
    });

    // An empty list means "could not ask", not "supports nothing".
    return $codes === [] || (\in_array($source, $codes, true) && \in_array($target, $codes, true));
}
```

**Quality is below the commercial engines.** LibreTranslate's models (Argos Translate / OpenNMT) are solid on factual copy in common European pairs and weaker on idiomatic marketing prose. That is a fair trade for a first pass an editor reviews — which is exactly the workflow the package assumes, with its *outdated* state and its per-field "still current" button. It also ignores `TranslationJob::$glossary` and `$tone`, which the contract explicitly allows: both are advisory, and a provider that cannot honour them ignores them rather than failing.

## Other backends

The same class shape fits every engine worth wiring; only `translateChunk()` changes.

| Backend | What differs |
|---|---|
| **DeepL** | `tag_handling: 'html'` instead of `format`, `Authorization: DeepL-Auth-Key`, and language codes it spells its own way (`EN-GB`, `PT-PT`) — so `languageCode()` grows a source/target distinction. |
| **Google / Azure** | Same batch-array shape; Azure wants `textType=html`. Both have recurring free tiers at the time of writing — verify the current quota before relying on it. |
| **An LLM** | Send `label`, `blockType`, the glossary and the tone from `TranslationJob` in the prompt, and constrain the reply to a JSON schema keyed by an index you echo back. It is the only kind of backend that can use the context the request already carries — that a string is a *button label* rather than a heading translates differently in German. |
| **A human vendor** | `translate()` may enqueue rather than translate: return failure outcomes with a "queued" reason, and fill the rows later through the same write path. |

## See also

- [Translation guide](../translation.md) — the editorial model, staleness, the workbench
- [`TranslationProviderInterface`](https://github.com/klehm/content-blocks-project/blob/master/packages/content-blocks-i18n/src/Machine/TranslationProviderInterface.php) — the contract, with its reasoning
- [`PseudoTranslationProvider`](https://github.com/klehm/content-blocks-project/blob/master/apps/content-blocks-sandbox/src/Translation/PseudoTranslationProvider.php) — the sandbox's offline provider, the smallest possible implementation
