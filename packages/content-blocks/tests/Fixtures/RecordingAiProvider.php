<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures;

use ContentBlocks\Ai\AiProviderInterface;
use ContentBlocks\Ai\AltTextRequest;
use ContentBlocks\Ai\ContentRequest;
use ContentBlocks\Ai\TextRequest;

/**
 * An AI provider that answers what it is told to and remembers what it was
 * asked, for tests about everything around the provider.
 */
final class RecordingAiProvider implements AiProviderInterface
{
    /** @var list<TextRequest|AltTextRequest|ContentRequest> */
    public array $requests = [];

    /**
     * @param list<string>         $unsupported
     * @param array<string, mixed> $document
     */
    public function __construct(
        public array $unsupported = [],
        public string $text = 'Rewritten',
        public array $document = ['sections' => []],
        public ?\Throwable $failure = null,
    ) {
    }

    public function supports(string $action): bool
    {
        return !\in_array($action, $this->unsupported, true);
    }

    public function rewriteText(TextRequest $request): string
    {
        $this->requests[] = $request;

        return $this->failure !== null ? throw $this->failure : $this->text;
    }

    public function describeImage(AltTextRequest $request): string
    {
        $this->requests[] = $request;

        return $this->failure !== null ? throw $this->failure : $this->text;
    }

    public function generateContent(ContentRequest $request): array
    {
        $this->requests[] = $request;

        return $this->failure !== null ? throw $this->failure : $this->document;
    }
}
