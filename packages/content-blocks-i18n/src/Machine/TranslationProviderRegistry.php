<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * The registered {@see TranslationProviderInterface} services, keyed by name.
 *
 * Same shape as the core's block-type and palette registries: an autoconfigured
 * tagged collection, indexed lazily. A host can therefore wire DeepL and an LLM
 * side by side and let the editor pick — which is the realistic setup, since
 * the two are good at different things (a glossary-bound engine for product
 * copy, a model for marketing prose).
 */
final class TranslationProviderRegistry
{
    /** @var array<string, TranslationProviderInterface>|null */
    private ?array $providers = null;

    /**
     * @param iterable<TranslationProviderInterface> $services
     */
    public function __construct(
        private readonly iterable $services,
        private readonly ?string $defaultName = null,
    ) {
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): TranslationProviderInterface
    {
        $providers = $this->all();

        if (!isset($providers[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown translation provider "%s". Registered: %s.',
                $name,
                $providers === [] ? '(none)' : implode(', ', array_keys($providers)),
            ));
        }

        return $providers[$name];
    }

    /**
     * The provider to use when the caller named none: the configured default,
     * else the only registered one, else {@see NullTranslationProvider}.
     *
     * Falling through to the null provider rather than throwing keeps the
     * workbench's "translate" button rendering on an installation with nothing
     * wired: the click comes back with a stated reason instead of a 500, which
     * is the difference between a feature that looks broken and one that looks
     * unconfigured.
     */
    public function getDefault(): TranslationProviderInterface
    {
        $providers = $this->all();

        if ($this->defaultName !== null && $this->defaultName !== '') {
            return $this->get($this->defaultName);
        }

        if (\count($providers) === 1) {
            return reset($providers);
        }

        return $providers[NullTranslationProvider::NAME] ?? new NullTranslationProvider();
    }

    /** @return array<string, TranslationProviderInterface> */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $out = [];

        foreach ($this->services as $provider) {
            $out[$provider::getName()] = $provider;
        }

        return $this->providers = $out;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
