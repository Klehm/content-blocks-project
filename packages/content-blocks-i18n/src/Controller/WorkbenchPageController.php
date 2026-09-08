<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\NullTranslationProvider;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Preview\PreviewLocaleListener;
use ContentBlocks\I18n\Progress\BlockTranslationView;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Progress\TranslationProgress;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Serves the workbench, rendered server-side with its field list already in
 * it. The JSON endpoints next door remain the API.
 *
 * @see docs/internals/i18n.md#the-workbench-is-a-page-not-a-panel
 */
final class WorkbenchPageController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly TranslationInspector $inspector,
        private readonly TranslationLocales $locales,
        private readonly TranslationProviderRegistry $providers,
        private readonly ContentAreaUrlResolverInterface $urlResolver,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(
        '/workbench/{id}/{locale}',
        name: 'content_blocks_i18n_workbench',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'],
    )]
    public function workbench(int $id, string $locale): Response
    {
        $area = $this->em->find(ContentArea::class, $id);

        if ($area === null) {
            return new Response('Content area not found', Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        if (!$this->locales->isTarget($locale)) {
            return new Response('Unknown target locale', Response::HTTP_NOT_FOUND);
        }

        $views = $this->inspector->inspectArea($area, $locale);
        $progress = new TranslationProgress($locale);

        foreach ($views as $view) {
            $progress = $progress->plus($view->progress);
        }

        return new Response($this->twig->render('@ContentBlocksI18n/workbench/workbench.html.twig', [
            'area' => $area,
            'locale' => $locale,
            'sourceLocale' => $this->locales->getSourceLocale(),
            'localeLabel' => $this->locales->getLabel($locale),
            'sourceLabel' => $this->locales->getLabel($this->locales->getSourceLocale()),
            'locales' => $this->locales->toArray(),
            'blocks' => array_map(static fn (BlockTranslationView $v): array => $v->toArray(), $views),
            'progress' => $progress->toArray(),
            'previewUrl' => $this->previewUrl($area, $locale),
            'providers' => $this->providerChoices($locale),
            'csrfToken' => (string) $this->csrfTokenManager->getToken('content_blocks'),
        ]));
    }

    /**
     * The host's own URL, draft content, target language, chrome off.
     * {@see PreviewLocaleListener} is the other half.
     *
     * @see docs/internals/i18n.md#the-preview-pane
     */
    private function previewUrl(ContentArea $area, string $locale): string
    {
        $url = $this->urlResolver->resolve($area);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query([
            BlockRendererInterface::QUERY_PARAM => '1',
            BlockRendererInterface::CHROME_QUERY_PARAM => '0',
            PreviewLocaleListener::PARAM => $locale,
        ]);
    }

    /**
     * Engines usable for *this* page. Empty is the normal state, not an error.
     *
     * @see docs/internals/i18n.md#machine-translation-is-a-seam
     *
     * @return list<array{name: string, label: string}>
     */
    private function providerChoices(string $locale): array
    {
        $source = $this->locales->getSourceLocale();
        $out = [];

        foreach ($this->providers->all() as $name => $provider) {
            if ($name === NullTranslationProvider::NAME || !$provider->supports($source, $locale)) {
                continue;
            }

            $label = $provider->getLabel();

            $out[] = [
                'name' => $name,
                'label' => $label instanceof TranslatableInterface
                    ? $label->trans($this->translator)
                    : (string) $label,
            ];
        }

        return $out;
    }
}
