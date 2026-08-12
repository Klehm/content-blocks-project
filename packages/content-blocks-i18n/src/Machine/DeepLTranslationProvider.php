<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * DeepL, over its v2 `/translate` endpoint.
 *
 * Registered only when `content_blocks_i18n.machine.deepl.api_key` is set, so an
 * installation that never configures it never sees it in the provider picker.
 *
 * Three details that are easy to get wrong and are handled here:
 *
 *  - **HTML and plain text go in separate calls.** `tag_handling` is a
 *    per-request setting, not per-string, so mixing a rich-text field into a
 *    batch of plain ones would either escape the markup or translate the tag
 *    names. Requests are grouped by format first.
 *  - **DeepL answers positionally.** The response is a bare list matched to the
 *    input by index — so the grouping above has to keep its own index map to put
 *    the results back on the right paths. This is exactly the class of bug
 *    {@see TranslationProviderInterface} guards against by having the *caller*
 *    match on `path` rather than trusting order.
 *  - **Locale codes are not BCP 47.** DeepL wants `EN-GB`, `PT-BR`, `ZH`. The
 *    mapping is mechanical for the common cases and configurable for the rest.
 */
final class DeepLTranslationProvider implements TranslationProviderInterface
{
    public const NAME = 'deepl';

    private const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    private const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    /**
     * DeepL rejects oversized payloads; 50 strings per call is well inside the
     * documented limit and keeps a failed batch cheap to retry.
     */
    private const CHUNK = 50;

    /**
     * @param array<string, string> $localeMap host locale => DeepL language code, for the
     *                                          cases the mechanical mapping gets wrong
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly ?string $endpoint = null,
        private readonly array $localeMap = [],
    ) {
    }

    public static function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string|TranslatableInterface
    {
        return 'DeepL';
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        // Answering cheaply here would mean shipping (and maintaining) DeepL's
        // language list. The contract explicitly prefers optimism plus a
        // per-request failure, which is also what surfaces a useful message.
        return $sourceLocale !== $targetLocale;
    }

    public function translate(array $requests, TranslationJob $job): array
    {
        if ($requests === []) {
            return [];
        }

        $outcomes = [];

        // Grouped by format because `tag_handling` is per-call, then chunked
        // because payload size is per-call too.
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
     * @param list<TranslationRequest> $requests
     *
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
     * @param list<TranslationRequest> $chunk
     *
     * @return list<TranslationOutcome>
     */
    private function translateChunk(array $chunk, string $format, TranslationJob $job): array
    {
        $body = [
            'text' => array_map(static fn (TranslationRequest $r): string => $r->text, $chunk),
            'target_lang' => $this->languageCode($job->targetLocale),
            'source_lang' => $this->languageCode($job->sourceLocale, source: true),
        ];

        if ($format === TranslationRequest::FORMAT_HTML) {
            $body['tag_handling'] = 'html';
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint ?? $this->defaultEndpoint(), [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);

            $payload = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (TransportException $e) {
            return $this->failAll($chunk, 'transport_error: ' . $e->getMessage());
        }

        if ($status >= 400) {
            $message = \is_string($payload['message'] ?? null) ? $payload['message'] : 'http_' . $status;

            return $this->failAll($chunk, $message);
        }

        $translations = $payload['translations'] ?? null;

        if (!\is_array($translations)) {
            return $this->failAll($chunk, 'malformed_response');
        }

        $outcomes = [];

        foreach ($chunk as $index => $request) {
            // Positional match — the one place order matters, and the reason
            // the outcome carries the path back explicitly.
            $text = $translations[$index]['text'] ?? null;

            $outcomes[] = \is_string($text)
                ? TranslationOutcome::success($request->path, $text)
                : TranslationOutcome::failure($request->path, 'missing_translation');
        }

        return $outcomes;
    }

    /**
     * @param list<TranslationRequest> $chunk
     *
     * @return list<TranslationOutcome>
     */
    private function failAll(array $chunk, string $error): array
    {
        return array_map(
            static fn (TranslationRequest $r): TranslationOutcome => TranslationOutcome::failure($r->path, $error),
            $chunk,
        );
    }

    /**
     * `fr` → `FR`, `pt_BR` → `PT-BR`.
     *
     * DeepL distinguishes source from target for English and Portuguese: as a
     * *source* it wants the bare `EN`/`PT` and rejects the regional variants,
     * while as a *target* it deprecates the bare code and wants `EN-GB`/`PT-PT`.
     */
    private function languageCode(string $locale, bool $source = false): string
    {
        if (isset($this->localeMap[$locale])) {
            return $this->localeMap[$locale];
        }

        $code = strtoupper(str_replace('_', '-', $locale));

        if ($source) {
            return explode('-', $code)[0];
        }

        return match ($code) {
            'EN' => 'EN-GB',
            'PT' => 'PT-PT',
            default => $code,
        };
    }

    private function defaultEndpoint(): string
    {
        // DeepL's own convention: a free-tier key ends in `:fx` and must go to
        // a different host. Getting this wrong returns a 403 that reads like an
        // authentication problem, so it is worth deriving rather than asking.
        return str_ends_with($this->apiKey, ':fx') ? self::FREE_ENDPOINT : self::PRO_ENDPOINT;
    }
}
