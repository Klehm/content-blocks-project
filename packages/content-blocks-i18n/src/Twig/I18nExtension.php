<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Progress\TranslationInspector;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers for linking into the workbench from wherever a host keeps its
 * admin UI — a page list, a builder view, a custom dashboard.
 *
 * Deliberately separate from any core extension so it stays instantiable on its
 * own in a test, the same split {@see \ContentBlocks\Twig\ImageExtension} makes.
 */
final class I18nExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslationLocales $locales,
        private readonly TranslationInspector $inspector,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_i18n_workbench_url', $this->workbenchUrl(...)),
            new TwigFunction('cb_i18n_locales', $this->localeList(...)),
            new TwigFunction('cb_i18n_progress', $this->progress(...)),
        ];
    }

    /**
     * URL of the workbench for an area in a locale. With no locale, the first
     * configured target — the common case, "open the translation view".
     */
    public function workbenchUrl(ContentArea $area, ?string $locale = null): string
    {
        $locale ??= $this->locales->getTargetLocales()[0] ?? $this->locales->getSourceLocale();

        return $this->urls->generate('content_blocks_i18n_workbench', [
            'id' => $area->getId(),
            'locale' => $locale,
        ]);
    }

    /** @return list<array{code: string, label: string, source: bool}> */
    public function localeList(): array
    {
        return $this->locales->toArray();
    }

    /**
     * Per-locale progress for an area, keyed by locale — so a host's page list
     * can show "DE 40%" without knowing anything about how it is computed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function progress(ContentArea $area): array
    {
        $out = [];

        foreach ($this->inspector->progressMatrix($area) as $locale => $progress) {
            $out[$locale] = $progress->toArray();
        }

        return $out;
    }
}
