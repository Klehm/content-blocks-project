<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Preview;

use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Rendering\BlockRendererInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns `?cb_locale=de` into the request locale, **only** on a request already
 * carrying `cb_preview=1` — so it can never relanguage a public page.
 *
 * @see docs/internals/i18n.md#the-preview-pane
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
