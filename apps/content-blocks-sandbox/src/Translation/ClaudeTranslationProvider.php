<?php

declare(strict_types=1);

namespace App\Translation;

use Anthropic\Client;
use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;
use ContentBlocks\I18n\Machine\TranslationJob;
use ContentBlocks\I18n\Machine\TranslationOutcome;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use ContentBlocks\I18n\Machine\TranslationRequest;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * An LLM as a translation provider — Claude, through the official Anthropic PHP
 * SDK.
 *
 * ---- Why this lives in the sandbox ----
 *
 * Same call the LiipImagine integration made: the *seam* belongs in the package,
 * a *vendor adapter* does not. Shipping this in `klehm/content-blocks-i18n`
 * would put an SDK dependency (and its release cadence) in the require of every
 * host that only wanted DeepL, or nothing. Here it is a working, runnable
 * example a host can copy in one file — and proof that
 * {@see TranslationProviderInterface} fits an LLM as comfortably as it fits a
 * dedicated translation API, which is the design claim worth testing.
 *
 * ---- Why an LLM at all ----
 *
 * A translation API translates a string. A model can be told that the string is
 * a *button label* rather than a heading, that a glossary term must not be
 * translated, and what tone the site uses — all of which
 * {@see TranslationRequest} and {@see TranslationJob} already carry, and all of
 * which a dedicated engine mostly ignores.
 *
 * ---- Two things worth copying ----
 *
 *  - **Structured output, not prose parsing.** The response is constrained to a
 *    schema, so there is no "strip the markdown fence and hope" step. Items are
 *    keyed by an index the model echoes back, because a schema cannot express
 *    the dynamic field paths this package uses as keys.
 *  - **One call per batch.** The whole page goes in one request, which is what
 *    the batch-shaped provider contract exists to allow.
 */
final class ClaudeTranslationProvider implements TranslationProviderInterface
{
    public const NAME = 'claude';

    /**
     * A page's worth of short strings fits comfortably in one request; chunking
     * keeps a single failure cheap to retry and the output well inside
     * `maxTokens`.
     */
    private const CHUNK = 60;

    public function __construct(
        private readonly Client $client,
        private readonly string $model = 'claude-opus-5',
    ) {
    }

    public static function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string|TranslatableInterface
    {
        return 'Claude (Anthropic)';
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return $sourceLocale !== $targetLocale;
    }

    public function translate(array $requests, TranslationJob $job): array
    {
        $outcomes = [];

        foreach (array_chunk($requests, self::CHUNK) as $chunk) {
            foreach ($this->translateChunk($chunk, $job) as $outcome) {
                $outcomes[] = $outcome;
            }
        }

        return $outcomes;
    }

    /**
     * @param list<TranslationRequest> $chunk
     *
     * @return list<TranslationOutcome>
     */
    private function translateChunk(array $chunk, TranslationJob $job): array
    {
        // Indexed rather than keyed by path: a JSON schema cannot express
        // arbitrary dynamic property names, and structured outputs reject
        // `additionalProperties` other than false.
        $items = [];

        foreach ($chunk as $i => $request) {
            $items[] = array_filter([
                'id' => $i,
                'text' => $request->text,
                'format' => $request->format,
                'field' => $request->label,
                'block' => $request->blockType,
            ], static fn ($v) => $v !== null);
        }

        try {
            $message = $this->client->messages->create(
                model: $this->model,
                maxTokens: 16000,
                system: $this->systemPrompt($job),
                messages: [[
                    'role' => 'user',
                    'content' => json_encode(['items' => $items], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                ]],
                outputConfig: [
                    'format' => TranslatedBatch::class,
                    // Translation is not a reasoning-heavy task; low effort
                    // keeps a whole-page run fast and cheap without hurting
                    // output quality here.
                    'effort' => 'low',
                ],
            );
        } catch (\Throwable $e) {
            // Whole-batch failure (credentials, transport, rate limit) is
            // reported per request rather than thrown: the caller is mid-way
            // through a page and other chunks may still succeed.
            return array_map(
                static fn (TranslationRequest $r): TranslationOutcome => TranslationOutcome::failure(
                    $r->path,
                    'claude_error: ' . $e->getMessage(),
                ),
                $chunk,
            );
        }

        $batch = $message->parsedOutput();

        if (!$batch instanceof TranslatedBatch) {
            return array_map(
                static fn (TranslationRequest $r): TranslationOutcome => TranslationOutcome::failure($r->path, 'malformed_response'),
                $chunk,
            );
        }

        $byId = [];

        foreach ($batch->items as $item) {
            $byId[$item->id] = $item->text;
        }

        $outcomes = [];

        foreach ($chunk as $i => $request) {
            $outcomes[] = isset($byId[$i])
                ? TranslationOutcome::success($request->path, $byId[$i])
                : TranslationOutcome::failure($request->path, 'missing_translation');
        }

        return $outcomes;
    }

    private function systemPrompt(TranslationJob $job): string
    {
        $prompt = <<<PROMPT
            You translate short strings of website copy from {$job->sourceLocale} to {$job->targetLocale}.

            Each item carries the text plus context: `field` is the label of the form field it
            came from and `block` is the kind of content block it belongs to. Use that context —
            a button label, a heading and a table cell translate differently even when the
            source text is identical.

            Rules:
            - Return one item per input, echoing its `id`. Never merge, split, reorder or drop items.
            - Translate the text only. Do not add commentary, quotation marks or explanations.
            - When `format` is `html`, preserve every tag, attribute and entity exactly; translate
              only the text between tags.
            - Preserve leading and trailing whitespace, placeholders such as `%name%` or `{count}`,
              URLs, and code.
            - Match the register of the source rather than defaulting to formal address.
            - If a string is a proper noun or a brand name, leave it untranslated.
            PROMPT;

        if ($job->glossary !== []) {
            $terms = [];

            foreach ($job->glossary as $term => $translation) {
                $terms[] = sprintf('- "%s" must be translated as "%s"', $term, $translation);
            }

            $prompt .= "\n\nGlossary (these take precedence over your own judgement):\n" . implode("\n", $terms);
        }

        if ($job->tone !== null) {
            $prompt .= "\n\nTone: " . $job->tone;
        }

        return $prompt;
    }
}

/** One translated string, tied back to its input by `id`. */
final class TranslatedItem implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'The id of the input item this translates.')]
    public int $id;

    #[Constrained(description: 'The translated text.')]
    public string $text;
}

/** The batch wrapper — a JSON schema needs a named root object. */
final class TranslatedBatch implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    /**
     * `itemClass` is required for an array of models — PHP's `array` type hint
     * carries no element type for the schema generator to read.
     *
     * @var TranslatedItem[]
     */
    #[Constrained(description: 'One entry per input item.', itemClass: TranslatedItem::class)]
    public array $items;
}
