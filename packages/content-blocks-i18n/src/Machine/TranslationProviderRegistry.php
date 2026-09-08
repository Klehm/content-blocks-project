<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * The registered providers, keyed by name, so a host can wire two engines side
 * by side and let the editor pick.
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
            throw new \InvalidArgumentException(sprintf('Unknown translation provider "%s". Registered: %s.', $name, $providers === [] ? '(none)' : implode(', ', array_keys($providers)), ));
        }

        return $providers[$name];
    }

    /**
     * The configured default, else the only one, else the null provider — so
     * the API and CLI state a reason rather than 500.
     *
     * @see docs/internals/i18n.md#machine-translation-is-a-seam
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
