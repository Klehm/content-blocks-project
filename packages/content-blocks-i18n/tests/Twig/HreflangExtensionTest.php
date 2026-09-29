<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\AlternatePageLinks;
use ContentBlocks\I18n\Locale\LocalizedPageUrlResolverInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\I18n\Twig\HreflangExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * `cb_i18n_hreflang()` and `cb_i18n_alternates()`: the host's
 * LocalizedPageUrlResolverInterface, turned into `<head>` alternates.
 */
final class HreflangExtensionTest extends TestCase
{
    public function testEveryLanguageWithAUrlGetsATagAndTheSourceIsXDefault(): void
    {
        $html = $this->extension(['fr' => '/page/7', 'en' => '/en/page/7', 'pt_BR' => '/pt_BR/page/7'])
            ->hreflang(Entities::area(7));

        $this->assertSame(implode("\n", [
            '<link rel="alternate" hreflang="fr" href="https://shop.test/page/7">',
            '<link rel="alternate" hreflang="en" href="https://shop.test/en/page/7">',
            '<link rel="alternate" hreflang="pt-BR" href="https://shop.test/pt_BR/page/7">',
            '<link rel="alternate" hreflang="x-default" href="https://shop.test/page/7">',
        ]), $html);
    }

    public function testXDefaultCanBeLeftOut(): void
    {
        $html = $this->extension(['fr' => '/page/7', 'en' => '/en/page/7'])
            ->hreflang(Entities::area(7), xDefault: false);

        $this->assertStringNotContainsString('x-default', $html);
    }

    public function testNoXDefaultWhenTheSourceHasNoUrl(): void
    {
        $html = $this->extension(['en' => '/en/page/7', 'pt_BR' => '/pt_BR/page/7'])
            ->hreflang(Entities::area(7));

        $this->assertSame(2, substr_count($html, '<link'));
        $this->assertStringNotContainsString('x-default', $html);
    }

    public function testALanguageTheResolverSkipsIsLeftOut(): void
    {
        $links = $this->extension(['fr' => '/page/7', 'en' => null, 'pt_BR' => '/pt_BR/page/7'])
            ->alternates(Entities::area(7));

        $this->assertSame(['fr', 'pt_BR'], array_column($links, 'locale'));
    }

    public function testOneLanguageOnlyRendersNothing(): void
    {
        // An alternate needs a second language; the default resolver
        // links none at all.
        $this->assertSame('', $this->extension(['fr' => '/page/7'])->hreflang(Entities::area(7)));
        $this->assertSame('', $this->extension([])->hreflang(Entities::area(7)));
    }

    public function testAMissingAreaRendersNothing(): void
    {
        $this->assertSame('', $this->extension(['fr' => '/page/7', 'en' => '/en'])->hreflang(null));
        $this->assertSame([], $this->extension(['fr' => '/page/7'])->alternates(null));
    }

    public function testAbsoluteUrlsAreKeptAndProtocolRelativeOnesGetTheScheme(): void
    {
        $links = $this->extension(['fr' => 'https://example.fr/page', 'en' => '//example.com/page'])
            ->alternates(Entities::area(7));

        $this->assertSame(
            ['https://example.fr/page', 'https://example.com/page'],
            array_column($links, 'url'),
        );
    }

    public function testWithoutARequestAPathStaysAsTheResolverGaveIt(): void
    {
        // A sitemap job or a command has no host to prefix with.
        $links = $this->extension(['fr' => '/page/7', 'en' => '/en/page/7'], request: false)
            ->alternates(Entities::area(7));

        $this->assertSame('/page/7', $links[0]['url']);
    }

    public function testTheUrlIsEscaped(): void
    {
        $html = $this->extension(['fr' => '/page?a=1&b="2"', 'en' => '/en'])
            ->hreflang(Entities::area(7));

        $this->assertStringContainsString('href="https://shop.test/page?a=1&amp;b=&quot;2&quot;"', $html);
    }

    public function testTheFunctionsAreRegisteredAndTheTagsAreNotEscaped(): void
    {
        $twig = new Environment(new ArrayLoader([
            'head' => '{{ cb_i18n_hreflang(area, x_default: false) }}|{{ cb_i18n_alternates(area)|length }}',
        ]));
        $twig->addExtension($this->extension(['fr' => '/page/7', 'en' => '/en/page/7']));

        $this->assertSame(
            '<link rel="alternate" hreflang="fr" href="https://shop.test/page/7">' . "\n"
            . '<link rel="alternate" hreflang="en" href="https://shop.test/en/page/7">|2',
            $twig->render('head', ['area' => Entities::area(7)]),
        );
    }

    /**
     * @param array<string, string|null> $urls locale => what the host resolves
     */
    private function extension(array $urls, bool $request = true): HreflangExtension
    {
        $locales = new TranslationLocales('fr', ['en', 'pt_BR']);
        $resolver = new class ($urls) implements LocalizedPageUrlResolverInterface {
            /** @param array<string, string|null> $urls */
            public function __construct(private readonly array $urls)
            {
            }

            public function resolve(ContentArea $area, string $locale): ?string
            {
                return $this->urls[$locale] ?? null;
            }
        };

        $stack = new RequestStack();
        if ($request) {
            $stack->push(Request::create('https://shop.test/en/page/7'));
        }

        return new HreflangExtension(new AlternatePageLinks($resolver, $locales, $stack), $locales);
    }
}
