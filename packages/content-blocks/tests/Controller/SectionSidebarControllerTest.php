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
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
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
                'padding' => ['d' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '']],
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
        $this->assertSame('80', $view['styling']['padding']['d']['top']->vars['value']);
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

    /** @param list<object> $entities */
    private function makeController(array $entities): SectionSidebarController
    {
        $styleRegistry = new SectionStyleRegistry([
            new class implements SectionStyleProviderInterface {
                public function getStyles(): array
                {
                    return [new SectionStyle('airy', 'Airy', '', [
                        'styling' => ['padding' => ['d' => ['top' => 80, 'bottom' => 80]]],
                    ])];
                }
            },
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $tpl, array $context): string {
            $this->renderContext = $context;

            return '<form></form>';
        });

        return new SectionSidebarController(
            $this->makeEm($entities),
            $this->makeAccessChecker(),
            $this->makeFormFactory($styleRegistry),
            $twig,
            $this->makeCsrfManager(),
            new SectionSettingsDefaults([]),
            $styleRegistry,
        );
    }

    private function makeFormFactory(SectionStyleRegistry $styleRegistry): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
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
