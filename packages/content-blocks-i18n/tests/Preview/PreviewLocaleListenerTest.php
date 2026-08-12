<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Preview;

use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Preview\PreviewLocaleListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The half of the preview pane that lives in the request: the workbench appends
 * `?cb_locale=` to the host's own URL, and this turns it into the request locale.
 *
 * Its guards are the interesting part. The parameter is public and forgeable,
 * so what it may *not* do matters more than what it does.
 */
final class PreviewLocaleListenerTest extends TestCase
{
    public function testAPreviewRequestIsSwitchedToTheRequestedLocale(): void
    {
        $request = $this->request(['cb_preview' => '1', 'cb_locale' => 'de']);

        $this->listen($request);

        $this->assertSame('de', $request->getLocale());
    }

    /**
     * The gate that keeps this out of the public site. Without `cb_preview=1`
     * the parameter is inert, so it can never flip the language of a page a
     * visitor is reading — preview mode is itself only granted after the core
     * has run `canEdit()`.
     */
    public function testAPublicRequestIsLeftAlone(): void
    {
        $request = $this->request(['cb_locale' => 'de']);

        $this->listen($request);

        $this->assertSame('fr', $request->getLocale());
    }

    /**
     * An unconfigured locale has no translation rows, so honouring it would
     * render a page of empty fallbacks and look like data loss. Same reasoning
     * for the source locale: it is the content itself, never a target.
     */
    public function testAnUnknownOrSourceLocaleIsIgnored(): void
    {
        foreach (['zz', 'fr', ''] as $locale) {
            $request = $this->request(['cb_preview' => '1', 'cb_locale' => $locale]);

            $this->listen($request);

            $this->assertSame('fr', $request->getLocale(), sprintf('"%s" must not be honoured', $locale));
        }
    }

    /**
     * Sub-requests (a rendered fragment, an ESI) inherit the main request's
     * query string, so acting on them would re-apply the switch at a point
     * where the locale is already settled.
     */
    public function testSubRequestsAreIgnored(): void
    {
        $request = $this->request(['cb_preview' => '1', 'cb_locale' => 'de']);

        $this->listen($request, HttpKernelInterface::SUB_REQUEST);

        $this->assertSame('fr', $request->getLocale());
    }

    /**
     * `cb_preview` is a flag, not a truthy value — the core reads it the same
     * strict way, and the two must not disagree about what counts as preview.
     */
    public function testOnlyTheExactPreviewFlagCounts(): void
    {
        $request = $this->request(['cb_preview' => 'true', 'cb_locale' => 'de']);

        $this->listen($request);

        $this->assertSame('fr', $request->getLocale());
    }

    /** @param array<string, string> $query */
    private function request(array $query): Request
    {
        $request = Request::create('/page/1?' . http_build_query($query));
        // What the host app's own default_locale would have resolved to.
        $request->setLocale('fr');

        return $request;
    }

    private function listen(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $listener = new PreviewLocaleListener(new TranslationLocales('fr', ['en', 'de', 'es']));

        $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type));
    }
}
