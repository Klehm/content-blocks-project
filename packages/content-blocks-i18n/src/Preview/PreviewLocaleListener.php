<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Preview;

use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Rendering\BlockRendererInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets a builder-preview request name the language it should render in, via
 * `?cb_locale=de`.
 *
 * ---- Why this exists ----
 *
 * The workbench previews a page in the language being translated, by iframing
 * the host's own public URL — the same URL the builder previews. But the host
 * owns that route, and how a host expresses a locale in it is entirely its own
 * business: a `/{_locale}/` prefix, a subdomain, a stored user preference. The
 * package cannot generate a localized URL for an arbitrary host and must not
 * make hosts implement another resolver just to get a preview pane.
 *
 * So it goes the other way: the package appends a query parameter to whatever
 * URL the host resolver gave it, and this listener turns that parameter into
 * the request locale. Symfony's own locale machinery does the rest, and
 * {@see \ContentBlocks\I18n\Locale\RequestRenderLocaleResolver} picks it up
 * unchanged.
 *
 * ---- Why it is gated on preview mode ----
 *
 * Only requests already carrying `cb_preview=1` are considered. That parameter
 * means "the builder is looking at this", and the core answers it with PREVIEW
 * content **only after** `AccessCheckerInterface::canEdit()` passes — so this
 * listener cannot become a way to flip the language of a public page from a
 * query string. An unconfigured locale is ignored for the same reason a typo'd
 * `_locale` is: there are no rows for it, so honouring it would only produce a
 * confusing empty translation.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 15)]
final class PreviewLocaleListener
{
    public const PARAM = 'cb_locale';

    public function __construct(
        private readonly TranslationLocales $locales,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->query->get(BlockRendererInterface::QUERY_PARAM) !== '1') {
            return;
        }

        $locale = $request->query->get(self::PARAM);

        if (!\is_string($locale) || !$this->locales->isTarget($locale)) {
            return;
        }

        $request->setLocale($locale);
    }
}
