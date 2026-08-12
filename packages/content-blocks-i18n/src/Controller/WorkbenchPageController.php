<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Preview\PreviewLocaleListener;
use ContentBlocks\I18n\Progress\BlockTranslationView;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Progress\TranslationProgress;
use ContentBlocks\I18n\Machine\NullTranslationProvider;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
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
 * Serves the translation workbench: every translatable field of a page in one
 * list, with the page preview beside it.
 *
 * The page is rendered **server-side with its field list already in it** rather
 * than as an empty shell the controller fills over XHR. A translator's first
 * action is to read and start typing, and a spinner between the click and the
 * first field is exactly the latency the whole design is trying to remove. The
 * JSON endpoints next door remain the API — for saves, for machine translation,
 * and for anything else that wants the same data.
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
     * The host's own public URL for this area, showing draft content in the
     * language being translated, with the builder's editing chrome switched off.
     *
     * Reusing the host resolver rather than asking for a second, locale-aware
     * one is what keeps this working on any host regardless of how it spells a
     * locale in its routes — see {@see PreviewLocaleListener} for the other half.
     *
     * `cb_chrome=0` is what makes the pane readable: preview mode otherwise
     * injects the builder's toolbars and click-to-edit, which are dead ends
     * here because this page has no builder sidebar to open.
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
     * The machine-translation engines actually usable for *this* page.
     *
     * Empty is the normal state, not an error: this package ships no adapter,
     * so an installation where the host wired none has nothing to offer — and
     * the template renders no ⚡ button and no "translate the page" button at
     * all rather than showing affordances that could only fail. A button that
     * always errors teaches editors to distrust the ones that work.
     *
     * Two things are filtered out, for the same reason:
     *
     *  - {@see NullTranslationProvider}, the "nothing configured" placeholder;
     *  - any provider whose `supports()` says no to this source/target pair —
     *    an engine that covers European languages has no business offering a
     *    button on the Japanese column.
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
