<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

/**
 * Which AI actions are on: switched on in `content_blocks.ai.actions` and
 * supported by the provider. Without a provider, none is but `apply`.
 *
 * @see docs/guide/ai.md#the-actions
 */
final class AiFeatures
{
    public const DEFAULT_TONES = ['professional', 'friendly', 'persuasive', 'playful'];

    /**
     * @param iterable<AiProviderInterface> $aiProviders the first one answers
     * @param array<string, bool>           $aiActions   action => on; an
     *                                                   action left out is on
     * @param list<string>                  $aiTones     offered by `tone`
     */
    public function __construct(
        private readonly iterable $aiProviders = [],
        private readonly array $aiActions = [],
        private readonly array $aiTones = self::DEFAULT_TONES,
        private readonly int $aiMaxSections = 8,
    ) {
    }

    public function provider(): ?AiProviderInterface
    {
        foreach ($this->aiProviders as $provider) {
            return $provider;
        }

        return null;
    }

    public function isEnabled(string $action): bool
    {
        if (!\in_array($action, AiAction::ALL, true) || !($this->aiActions[$action] ?? true)) {
            return false;
        }
        if ($action === AiAction::APPLY) {
            return true;
        }
        if ($action === AiAction::TONE && $this->aiTones === []) {
            return false;
        }

        return $this->provider()?->supports($action) ?? false;
    }

    /**
     * The provided actions that are on, for the builder to draw: `apply` has
     * no button.
     *
     * @return list<string>
     */
    public function enabledActions(): array
    {
        return array_values(array_filter(AiAction::PROVIDED, $this->isEnabled(...)));
    }

    /** @return list<string> */
    public function tones(): array
    {
        return array_values($this->aiTones);
    }

    /** How many sections a page may hold, at most. */
    public function maxSections(): int
    {
        return max(1, $this->aiMaxSections);
    }
}
