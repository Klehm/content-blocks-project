<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Preview;

use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Rendering\BlockRendererInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns `?cb_locale=de` into the request locale, on a `cb_preview=1` request
 * from a session that opened the workbench — never for a visitor.
 *
 * @see docs/internals/i18n.md#the-preview-pane
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 15)]
final class PreviewLocaleListener
{
    public const PARAM = 'cb_locale';

    /** Set by the workbench page, which checked `canEdit()` to render. */
    public const SESSION_KEY = 'cb_i18n.preview_locale';

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

        // hasPreviousSession(): reading must not start a session for a visitor.
        if (!$request->hasPreviousSession() || $request->getSession()->get(self::SESSION_KEY) !== true) {
            return;
        }

        $locale = $request->query->get(self::PARAM);

        if (!\is_string($locale) || !$this->locales->isTarget($locale)) {
            return;
        }

        $request->setLocale($locale);
    }
}
