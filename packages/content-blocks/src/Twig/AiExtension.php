<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Ai\AiFeatures;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_ai_actions()` and `cb_ai_tones()`: what the builder shell draws for the
 * AI actions. Both are empty without a provider.
 *
 * @see docs/guide/ai.md
 */
final class AiExtension extends AbstractExtension
{
    public function __construct(
        private readonly AiFeatures $features,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_ai_actions', $this->features->enabledActions(...)),
            new TwigFunction('cb_ai_tones', fn (): array => $this->features->enabledActions() === []
                ? []
                : $this->features->tones()),
        ];
    }
}
