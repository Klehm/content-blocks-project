<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Controller\WorkbenchPageController;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\NullTranslationProvider;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatableInterface;
use Twig\Environment;

/**
 * The workbench page. The template is rendered by a double that captures its
 * context, so what is asserted here is the *data* the controller decided on —
 * which is where its logic lives; the markup is covered by the e2e suite.
 */
final class WorkbenchPageControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $context = [];

    /**
     * The preview pane's whole trick: the host's own URL, untouched, with the
     * preview flag, the chrome switched off and the language appended. Reusing
     * the host resolver rather than asking for a locale-aware one is what makes
     * this work on a host whose routes spell locales any way they like.
     *
     * `cb_chrome=0` is the difference between a readable pane and the builder's
     * toolbars floating over a page with no builder behind them.
     */
    public function testThePreviewUrlAsksForDraftContentWithoutTheEditingChrome(): void
    {
        $this->controller()->workbench(7, 'de');

        $this->assertSame('/page/7?cb_preview=1&cb_chrome=0&cb_locale=de', $this->context['previewUrl']);
    }

    /**
     * A host URL that already carries a query string must keep it — appending
     * with `?` would silently drop whatever the host put there (a preview
     * token, a channel, a version).
     */
    public function testAHostUrlWithAQueryStringIsExtendedNotOverwritten(): void
    {
        $this->controller(hostUrl: '/page/7?channel=web')->workbench(7, 'de');

        $this->assertSame(
            '/page/7?channel=web&cb_preview=1&cb_chrome=0&cb_locale=de',
            $this->context['previewUrl'],
        );
    }

    public function testTheRowsAndTheProgressBarAreComputedFromTheSameInspection(): void
    {
        // A progress bar computed separately from the list it summarizes is a
        // bug generator: the bar says 100%, the list still shows empty rows.
        $this->controller()->workbench(7, 'de');

        $blocks = $this->context['blocks'];
        $this->assertCount(1, $blocks);

        $rows = 0;
        foreach ($blocks as $block) {
            $rows += \count($block['fields']);
        }

        $this->assertSame($rows, $this->context['progress']['total']);
        $this->assertSame(0, $this->context['progress']['percent']);
    }

    /**
     * The picker must not offer the "nothing configured" placeholder: choosing
     * it would be a click that always fails, and the template keys the whole
     * translate-button block off this list being empty.
     */
    public function testTheNullProviderIsKeptOutOfThePicker(): void
    {
        $this->controller()->workbench(7, 'de');

        $this->assertSame([['name' => 'fake', 'label' => 'Fake engine']], $this->context['providers']);
    }

    /**
     * The default state of the package: it ships no adapter, so a host that
     * wired none has nothing to offer. The list comes back empty and the
     * template renders no ⚡ button and no "translate the page" button.
     */
    public function testWithNoEngineWiredThereIsNothingToOffer(): void
    {
        $this->controller(providers: [new NullTranslationProvider()])->workbench(7, 'de');

        $this->assertSame([], $this->context['providers']);
    }

    /**
     * An engine that cannot handle this source/target pair is not offered for
     * it. Registering one is a global act; whether it covers a given language
     * is per-page, and only `supports()` knows.
     */
    public function testAnEngineThatDoesNotCoverThePairIsNotOffered(): void
    {
        $german = $this->controller(providers: [new GermanOnlyTranslationProvider()]);
        $german->workbench(7, 'de');
        $this->assertSame([['name' => 'german_only', 'label' => 'German only']], $this->context['providers']);

        $this->context = [];
        $this->controller(providers: [new GermanOnlyTranslationProvider()])->workbench(7, 'es');
        $this->assertSame([], $this->context['providers'], 'es is outside what this engine covers');
    }

    public function testAnUnknownAreaIs404(): void
    {
        $response = $this->controller()->workbench(404, 'de');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->context, 'nothing must be rendered for a missing area');
    }

    /**
     * The locale comes from the URL, so it is user input. An unconfigured one
     * has no rows and must not render an all-empty page that reads as data
     * loss — and the source locale is the content itself, never a target.
     */
    public function testAnUnknownOrSourceLocaleIs404(): void
    {
        foreach (['zz', 'fr'] as $locale) {
            $this->context = [];

            $this->assertSame(404, $this->controller()->workbench(7, $locale)->getStatusCode(), $locale);
            $this->assertSame([], $this->context);
        }
    }

    /**
     * Same gate as every mutating endpoint: the workbench shows a page's whole
     * text, so it is a read of content the user may not be allowed to see.
     */
    public function testAccessIsCheckedBeforeAnythingIsRendered(): void
    {
        $this->expectException(ContentBlocksAccessDeniedException::class);

        try {
            $this->controller(accessChecker: new DenyAllAccessChecker())->workbench(7, 'de');
        } finally {
            $this->assertSame([], $this->context);
        }
    }

    /** @param list<TranslationProviderInterface>|null $providers */
    private function controller(
        string $hostUrl = '/page/7',
        ?AccessCheckerInterface $accessChecker = null,
        ?array $providers = null,
    ): WorkbenchPageController {
        $this->context = [];

        $area = Entities::area(7, Entities::block(1, draft: [
            'heading' => 'Welcome',
            'body' => 'We ship worldwide.',
            'align' => 'center',
            'items' => [],
        ]));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?object => $class === ContentArea::class && $id === 7 ? $area : null,
        );

        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturn([]);
        $repository->method('findOneFor')->willReturn(null);

        $locales = new TranslationLocales('fr', ['de', 'es']);

        $inspector = new TranslationInspector(
            new TranslationStore($repository, $this->createMock(EntityManagerInterface::class)),
            CatalogFactory::create(),
            $locales,
            CatalogFactory::registry(),
            new Translator('en'),
        );

        $urlResolver = $this->createMock(ContentAreaUrlResolverInterface::class);
        $urlResolver->method('resolve')->willReturn($hostUrl);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('content_blocks', 'tok'));

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $context = []): string {
            $this->context = $context;

            return '<html></html>';
        });

        return new WorkbenchPageController(
            $em,
            $accessChecker ?? new AllowAllAccessChecker(),
            $inspector,
            $locales,
            new TranslationProviderRegistry($providers ?? [new FakeTranslationProvider(), new NullTranslationProvider()]),
            $urlResolver,
            new Translator('en'),
            $csrf,
            $twig,
        );
    }
}

/** Registered, and covering only one target — the `supports()` gate in the flesh. */
final class GermanOnlyTranslationProvider implements TranslationProviderInterface
{
    public static function getName(): string
    {
        return 'german_only';
    }

    public function getLabel(): string|TranslatableInterface
    {
        return 'German only';
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return $targetLocale === 'de';
    }

    public function translate(array $requests, \ContentBlocks\I18n\Machine\TranslationJob $job): array
    {
        return $requests;
    }
}

/** A provider that is registered and usable — unlike the null one beside it. */
final class FakeTranslationProvider implements TranslationProviderInterface
{
    public static function getName(): string
    {
        return 'fake';
    }

    public function getLabel(): string|TranslatableInterface
    {
        return 'Fake engine';
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return true;
    }

    public function translate(array $requests, \ContentBlocks\I18n\Machine\TranslationJob $job): array
    {
        return $requests;
    }
}
