<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\SectionSidebarController;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyle;
use ContentBlocks\Section\SectionStyleProviderInterface;
use ContentBlocks\Section\SectionStyleRegistry;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormTypeExtensionInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;

final class SectionSidebarControllerTest extends ControllerTestCase
{
    /** Context captured from the mocked twig render() on GET. */
    private ?array $renderContext = null;

    public function testPostWithStylingCustomOffDropsTheStylingSubtree(): void
    {
        $section = $this->makeSettingsSection(id: 5);
        $controller = $this->makeController([$section]);

        // Switch off: browsers omit unchecked checkboxes from the payload.
        $response = $controller->settings(5, $this->makeFormRequest([
            'widthMode' => 'full',
            'styling' => ['backgroundColor' => ['palette' => 'custom', 'custom' => '#123456']],
        ]));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(1, $this->flushCount);

        $saved = $section->getDraftSettings();
        $this->assertArrayNotHasKey('styling', $saved, 'styling must be wiped while the switch is off');
        // The false flag prunes away — absence reads as "off" on the next GET.
        $this->assertArrayNotHasKey('stylingCustom', $saved);
    }

    /** The range posts a string; the veil is stored as an int, 0 not at all. */
    public function testABackgroundImageAndItsVeilAreSaved(): void
    {
        $section = $this->makeSettingsSection(id: 5);
        $this->makeController([$section])->settings(5, $this->makeFormRequest([
            'widthMode' => 'full',
            'stylingCustom' => '1',
            'styling' => [
                'backgroundImage' => '/uploads/hero.jpg',
                'backgroundSize' => '',
                'backgroundPosition' => 'bottom',
                'overlayOpacity' => '45',
            ],
        ]));

        $styling = $section->getDraftSettings()['styling'] ?? [];
        $this->assertSame('/uploads/hero.jpg', $styling['backgroundImage']);
        $this->assertSame('bottom', $styling['backgroundPosition']);
        $this->assertSame(45, $styling['overlayOpacity']);
        $this->assertArrayNotHasKey('backgroundSize', $styling);

        $this->makeController([$section])->settings(5, $this->makeFormRequest([
            'widthMode' => 'full',
            'stylingCustom' => '1',
            'styling' => ['backgroundImage' => '/uploads/hero.jpg', 'overlayOpacity' => '0'],
        ]));
        $this->assertArrayNotHasKey('overlayOpacity', $section->getDraftSettings()['styling'] ?? []);
    }

    public function testPostWithStylingCustomOnKeepsTheStylingSubtree(): void
    {
        $section = $this->makeSettingsSection(id: 5);
        $controller = $this->makeController([$section]);

        $response = $controller->settings(5, $this->makeFormRequest([
            'widthMode' => 'full',
            'stylingCustom' => '1',
            'styling' => ['backgroundColor' => ['palette' => 'custom', 'custom' => '#123456']],
        ]));

        $this->assertSame(204, $response->getStatusCode());

        $saved = $section->getDraftSettings();
        $this->assertTrue($saved['stylingCustom']);
        $this->assertSame('#123456', $saved['styling']['backgroundColor']);
    }

    public function testUntouchedStylingFieldsAreNotPersisted(): void
    {
        // Flipping the switch on autosaves immediately, with every styling
        // field still empty. Those nulls must be pruned — persisting them
        // would mask the preset's values on the next sidebar prefill.
        $section = $this->makeSettingsSection(id: 5, settings: ['styleName' => 'airy']);
        $controller = $this->makeController([$section]);

        $response = $controller->settings(5, $this->makeFormRequest([
            'widthMode' => 'full',
            'styleName' => 'airy',
            'stylingCustom' => '1',
            'styling' => [
                'backgroundColor' => ['palette' => '', 'custom' => ''],
                'padding' => ['desktop' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '']],
            ],
        ]));

        $this->assertSame(204, $response->getStatusCode());

        $saved = $section->getDraftSettings();
        $this->assertTrue($saved['stylingCustom']);
        $this->assertArrayNotHasKey('styling', $saved);
        $this->assertSame('airy', $saved['styleName']);
    }

    public function testGetPrefillsStylingFieldsFromTheSelectedPreset(): void
    {
        $section = $this->makeSettingsSection(id: 5, settings: ['styleName' => 'airy']);
        $controller = $this->makeController([$section]);

        $response = $controller->settings(5, Request::create('/_content-blocks/section/5/settings'));

        $this->assertSame(200, $response->getStatusCode());
        $view = $this->renderedFormView();

        // Preset settings surface as the styling fields' starting values…
        $this->assertSame('80', $view['styling']['padding']['desktop']['top']->vars['value']);
        // …but a fresh section starts with the switch off.
        $this->assertFalse($view['stylingCustom']->vars['checked']);
    }

    public function testGetTreatsLegacyStylingValuesAsCustomized(): void
    {
        $section = $this->makeSettingsSection(id: 5, settings: [
            'styling' => ['backgroundColor' => '#abcdef'],
        ]);
        $controller = $this->makeController([$section]);

        $controller->settings(5, Request::create('/_content-blocks/section/5/settings'));

        // Pre-switch sections carry styling but no flag: shown as customized
        // so their values stay visible and survive the next save.
        $this->assertTrue($this->renderedFormView()['stylingCustom']->vars['checked']);
    }

    public function testTheFormPostsWhereverTheHostMountedTheRoute(): void
    {
        $section = $this->makeSettingsSection(id: 5);
        $controller = $this->makeController([$section]);

        $controller->settings(5, Request::create('/admin/cb/section/5/settings'));

        $this->assertSame('/admin/cb/section/5/settings', $this->renderedFormView()->vars['action']);
    }

    public function testGetListsTheLiveColumnsInDraftOrderWithTheirNames(): void
    {
        $section = $this->makeSettingsSection(id: 5);
        $second = $this->makeColumn($section, 51, 1);
        $second->setDraftSettings(['label' => 'Specs']);
        $this->makeColumn($section, 50, 0);
        $this->makeColumn($section, 52, 2)->setDeleted(true);
        $controller = $this->makeController([$section]);

        $controller->settings(5, Request::create('/_content-blocks/section/5/settings'));

        $this->assertSame(
            [['id' => 50, 'label' => null], ['id' => 51, 'label' => 'Specs']],
            $this->renderContext['columns'],
        );
        $this->assertSame(2, $this->renderContext['columnCount']);
        $this->assertSame(20, $this->renderContext['maxColumns']);
    }

    public function testTheDisplayFieldStartsFromTheStoredSetting(): void
    {
        $controller = $this->makeController([$this->makeSettingsSection(id: 5, settings: ['display' => 'tabs'])]);

        $controller->settings(5, Request::create('/_content-blocks/section/5/settings'));

        $this->assertSame('tabs', $this->renderedFormView()['display']->vars['value']);
    }

    public function testASavedDisplayLandsInTheDraftSettings(): void
    {
        $section = $this->makeSettingsSection(id: 5);
        $controller = $this->makeController([$section]);

        $response = $controller->settings(5, $this->makeFormRequest(['display' => 'tabs', 'widthMode' => 'full']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('tabs', $section->getDraftSettings()['display'] ?? null);
    }

    /** One column has nothing to reverse. */
    public function testReverseOnMobileIsOfferedFromTwoColumns(): void
    {
        $single = $this->makeSettingsSection(id: 5);
        $single->addColumn(new \ContentBlocks\Entity\Column());
        $this->makeController([$single])->settings(5, Request::create('/_content-blocks/section/5/settings'));
        $this->assertArrayNotHasKey('reverseOnMobile', $this->renderedFormView()->children);

        $pair = $this->makeSettingsSection(id: 5);
        $pair->addColumn(new \ContentBlocks\Entity\Column());
        $pair->addColumn(new \ContentBlocks\Entity\Column());
        $controller = $this->makeController([$pair]);
        $controller->settings(5, Request::create('/_content-blocks/section/5/settings'));
        $this->assertArrayHasKey('reverseOnMobile', $this->renderedFormView()->children);

        $controller->settings(5, $this->makeFormRequest(['reverseOnMobile' => '1', 'widthMode' => 'full']));
        $this->assertTrue($pair->getDraftSettings()['reverseOnMobile'] ?? null);
    }

    /** A host field comes from a stock type extension, no fork needed. */
    public function testAFieldAddedByATypeExtensionIsRenderedAndSaved(): void
    {
        $section = $this->makeSettingsSection(id: 5, settings: ['anchorId' => 'intro']);
        $extension = new class () extends \Symfony\Component\Form\AbstractTypeExtension {
            public static function getExtendedTypes(): iterable
            {
                return [SectionSettingsType::class];
            }

            public function buildForm(\Symfony\Component\Form\FormBuilderInterface $builder, array $options): void
            {
                $builder->add('anchorId', \Symfony\Component\Form\Extension\Core\Type\TextType::class, [
                    'required' => false,
                ]);
            }
        };

        $this->makeController([$section], [$extension])
            ->settings(5, Request::create('/_content-blocks/section/5/settings'));
        $this->assertSame('intro', $this->renderedFormView()['anchorId']->vars['value']);

        $response = $this->makeController([$section], [$extension])
            ->settings(5, $this->makeFormRequest(['anchorId' => 'faq', 'widthMode' => 'full']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('faq', $section->getDraftSettings()['anchorId'] ?? null);
    }

    // -------- plumbing --------

    private function makeSettingsSection(int $id, array $settings = []): Section
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, $id);
        if ($settings !== []) {
            $section->setDraftSettings($settings);
        }

        return $section;
    }

    /**
     * @param list<object> $entities
     * @param list<FormTypeExtensionInterface> $typeExtensions
     */
    private function makeController(array $entities, array $typeExtensions = []): SectionSidebarController
    {
        $styleRegistry = new SectionStyleRegistry([
            new class () implements SectionStyleProviderInterface {
                public function getStyles(): array
                {
                    return [new SectionStyle('airy', 'Airy', '', [
                        'styling' => ['padding' => ['desktop' => ['top' => 80, 'bottom' => 80]]],
                    ])];
                }
            },
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $tpl, array $context): string {
            $this->renderContext = $context;

            return '<form></form>';
        });

        $em = $this->makeEm($entities);

        return new SectionSidebarController(
            $em,
            $this->makeAccessChecker(),
            $this->makeFormFactory($styleRegistry, $typeExtensions),
            $twig,
            $this->makeCsrfManager(),
            new SectionSettingsDefaults([]),
            $styleRegistry,
            $this->makeJournal($em),
            $this->makeUrlGenerator(),
        );
    }

    /** A host that mounted the builder endpoints under `/admin/cb`. */
    private function makeUrlGenerator(): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('content_blocks_section_settings', new Route('/admin/cb/section/{id}/settings'));

        return new UrlGenerator($routes, new RequestContext());
    }

    /**
     * @param list<FormTypeExtensionInterface> $typeExtensions
     */
    private function makeFormFactory(SectionStyleRegistry $styleRegistry, array $typeExtensions = []): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addTypeExtensions($typeExtensions)
            ->addType(new SectionSettingsType($styleRegistry))
            ->addType(new PaletteColorType(new ColorPaletteRegistry([])))
            ->getFormFactory();
    }

    private function makeFormRequest(array $payload): Request
    {
        return Request::create(
            '/_content-blocks/section/5/settings',
            'POST',
            ['section_settings' => $payload],
            server: ['HTTP_X-CSRF-Token' => 'token'],
        );
    }

    private function renderedFormView(): FormView
    {
        $this->assertNotNull($this->renderContext, 'GET should have rendered the sidebar template');
        $this->assertInstanceOf(FormView::class, $this->renderContext['form']);

        return $this->renderContext['form'];
    }
}
