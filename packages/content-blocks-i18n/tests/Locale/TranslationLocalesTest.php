<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Locale;

use ContentBlocks\I18n\Locale\RequestRenderLocaleResolver;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Rendering\RenderContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class TranslationLocalesTest extends TestCase
{
    public function testTheSourceLocaleIsNeverATarget(): void
    {
        // Even when a host lists it — which is a natural way to write the
        // config. `data` *is* the source, so a target row for it would be a
        // second, silently diverging copy of the same text.
        $locales = new TranslationLocales('en', ['en', 'fr', 'de']);

        $this->assertSame(['fr', 'de'], $locales->getTargetLocales());
        $this->assertFalse($locales->isTarget('en'));
        $this->assertTrue($locales->isSource('en'));
    }

    public function testAllLocalesPutsTheSourceFirst(): void
    {
        $locales = new TranslationLocales('en', ['fr', 'de']);

        $this->assertSame(['en', 'fr', 'de'], $locales->getAllLocales());
    }

    public function testDuplicatesAndBlanksAreDropped(): void
    {
        $locales = new TranslationLocales('en', ['fr', 'fr', '', 'de']);

        $this->assertSame(['fr', 'de'], $locales->getTargetLocales());
    }

    public function testAConfiguredLabelWins(): void
    {
        $locales = new TranslationLocales('en', ['fr'], ['fr' => 'Français (CA)']);

        $this->assertSame('Français (CA)', $locales->getLabel('fr'));
    }

    public function testAnUnknownLocaleFallsBackToItsRawTag(): void
    {
        // ext-intl is not in this package's require; a picker rendering `de` is
        // worse than "Deutsch" and far better than a fatal error.
        $locales = new TranslationLocales('en', ['zz']);

        $this->assertNotSame('', $locales->getLabel('zz'));
    }

    public function testAnExplicitContextLocaleBeatsTheRequest(): void
    {
        // A language switcher, a sitemap job and a transactional email all pin
        // a locale and must get it whatever the ambient request says.
        $stack = new RequestStack();
        $request = Request::create('/');
        $request->setLocale('de');
        $stack->push($request);

        $resolver = new RequestRenderLocaleResolver($stack, new TranslationLocales('en', ['fr', 'de']));

        $this->assertSame('fr', $resolver->resolve(RenderContext::forPublic('fr')));
    }

    public function testTheRequestLocaleIsUsedWhenTheContextPinsNone(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/');
        $request->setLocale('de');
        $stack->push($request);

        $resolver = new RequestRenderLocaleResolver($stack, new TranslationLocales('en', ['fr', 'de']));

        $this->assertSame('de', $resolver->resolve(new RenderContext()));
    }

    public function testAnUnconfiguredLocaleResolvesToNoTranslation(): void
    {
        // A typo'd `_locale` renders the source text rather than hunting for
        // rows that cannot exist.
        $stack = new RequestStack();
        $request = Request::create('/');
        $request->setLocale('it');
        $stack->push($request);

        $resolver = new RequestRenderLocaleResolver($stack, new TranslationLocales('en', ['fr']));

        $this->assertNull($resolver->resolve(new RenderContext()));
    }

    public function testTheSourceLocaleResolvesToNoTranslation(): void
    {
        $stack = new RequestStack();
        $resolver = new RequestRenderLocaleResolver($stack, new TranslationLocales('en', ['fr']));

        $this->assertNull($resolver->resolve(RenderContext::forPublic('en')));
    }

    public function testWithNoRequestAtAllNothingIsResolved(): void
    {
        $resolver = new RequestRenderLocaleResolver(new RequestStack(), new TranslationLocales('en', ['fr']));

        $this->assertNull($resolver->resolve(new RenderContext()));
    }
}
