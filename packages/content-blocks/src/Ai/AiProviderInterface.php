<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

/**
 * A language model backend. Autoconfigured; none ships, because where a page's
 * text is sent is the host's decision.
 *
 * @see docs/guide/ai.md#writing-a-provider
 */
interface AiProviderInterface
{
    /**
     * Whether this provider answers an {@see AiAction} at all. An action it
     * does not support is never offered, whatever the configuration says.
     */
    public function supports(string $action): bool;

    /**
     * The new text of one field: {@see AiAction::REWRITE}, `SHORTEN` or
     * `TONE`. HTML in, HTML out when {@see TextRequest::$html} is true.
     */
    public function rewriteText(TextRequest $request): string;

    /** A short description of the image, for its `alt` attribute. */
    public function describeImage(AltTextRequest $request): string;

    /**
     * `['sections' => [...]]`, in the shape of the request's schema. Untrusted:
     * every block is replayed through its form before it reaches the draft.
     *
     * @return array<string, mixed>
     */
    public function generateContent(ContentRequest $request): array;
}
