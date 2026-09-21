<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form;

use ContentBlocks\Form\Extension\IconChoiceTypeExtension;
use ContentBlocks\Form\Extension\SidebarLayoutTypeExtension;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Form\Type\Styling\StylingType;
use ContentBlocks\Icon\CoreUiIcons;
use ContentBlocks\Icon\UiIconRegistry;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Twig\UiIconExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * The section sidebar rendered for real: tabs from `cb_group`, panels from
 * `cb_panel`, icon choices from `cb_icons`, a tooltip from `cb_help_tooltip`.
 */
final class SidebarLayoutRenderTest extends TestCase
{
    private \DOMXPath $dom;

    public function testTheTabsComeInTheirFixedOrderWithAHostTabBeforeStyle(): void
    {
        $this->render($this->form(withHostFields: true));

        $this->assertSame(['cb.section.settings.tab.structure', 'SEO', 'cb.section.settings.tab.styling'], $this->texts($this->q(self::cls('cb-sidebar-tabs__tab'))));
        $this->assertCount(3, $this->q(self::cls('cb-sidebar-tabs__panel')));
        $this->assertCount(2, $this->q(self::cls('cb-sidebar-tabs__panel') . '[@hidden]'), 'only the first tab shows');
    }

    public function testAnUngroupedHostFieldLandsOnTheFirstTab(): void
    {
        $this->render($this->form(withHostFields: true));

        $this->assertCount(1, $this->q('//input[@name="section_settings[anchorId]"]', $this->tab(0)));
        $this->assertCount(1, $this->q('//input[@name="section_settings[seoTitle]"]', $this->tab(1)));
    }

    public function testTheStructureTabOpensOnItsColumnsPanel(): void
    {
        $this->render($this->form());
        $panels = $this->panels($this->tab(0));

        $this->assertSame(
            [
                'cb.section.columns.title',
                SectionSettingsType::PANEL_LAYOUT,
                SectionSettingsType::PANEL_WIDTH,
                SectionSettingsType::PANEL_ADVANCED,
            ],
            $this->attrs($panels, 'data-cb-panel'),
        );
        $this->assertSame(['', null, null, null], $this->attrs($panels, 'open'), 'a bare `open` reads as ""');
        $summary = $this->q('.' . self::cls('cb-panel__summary'), $panels[0])[0];
        $this->assertSame('cb.section.columns.count', $this->attr($summary, 'data-cb-summary'));
        $this->assertCount(1, $this->q('.' . self::cls('cb-columns-editor'), $panels[0]));
    }

    public function testPanelsOfATabShareANameSoOnlyOneStaysOpen(): void
    {
        $this->render($this->form());

        $names = $this->attrs($this->panels($this->tab(0)), 'name');
        $this->assertCount(1, array_unique($names));
        $this->assertNotNull($names[0]);

        $styling = $this->panels($this->tab(1));
        $this->assertSame(
            [StylingType::PANEL_SPACING, StylingType::PANEL_BACKGROUND, StylingType::PANEL_LAYOUT],
            $this->attrs($styling, 'data-cb-panel'),
        );
        $this->assertNotNull($this->attr($styling[0], 'open'));
    }

    /** A field and those it gates share a panel: the display and its own. */
    public function testTheDisplayAndEveryFieldItGatesShareAPanel(): void
    {
        $this->render($this->form());
        $layout = $this->q(sprintf('.//details[@data-cb-panel="%s"]', SectionSettingsType::PANEL_LAYOUT), $this->tab(0))[0];

        foreach ([
            'display', 'displayTablet', 'displayMobile', 'accordionSingle', 'accordionCollapsed',
            'sliderControls', 'sliderAutoplay', 'sliderLoop', 'reverseOnMobile', 'columnWidths',
        ] as $field) {
            $this->assertGreaterThan(
                0,
                $this->q(sprintf('.//*[starts-with(@name, "section_settings[%s]")]', $field), $layout)->length,
                $field,
            );
        }
        $this->assertCount(1, $this->q('.' . self::cls('cb-display-row'), $layout));
        $this->assertCount(1, $this->q('.' . self::cls('cb-col-widths'), $layout));
    }

    public function testAnInvalidDisplayOpensItsPanel(): void
    {
        $form = $this->form();
        $form->submit(['display' => 'carousel', 'widthMode' => 'full']);
        $this->render($form);

        $layout = $this->q(sprintf('//details[@data-cb-panel="%s"]', SectionSettingsType::PANEL_LAYOUT))[0];
        $this->assertNotNull($this->attr($layout, 'open'));
        $this->assertStringContainsString('cb-panel--has-error', (string) $this->attr($layout, 'class'));
        $this->assertNull($this->attr($this->q('//details[@data-cb-panel="cb.section.columns.title"]')[0], 'open'));
    }

    public function testAHostPanelJoinsTheStylingOnes(): void
    {
        $this->render($this->form(withHostFields: true));

        $panel = $this->q('.//details[@data-cb-panel="Patterns"]', $this->tab(2));
        $this->assertCount(1, $panel);
        $this->assertCount(4, $this->q('.//input[@type="radio"][@name="section_settings[styling][corner]"]', $panel[0]));
    }

    public function testPanelsCanBeLeftIndependent(): void
    {
        $this->render($this->form(exclusive: false));

        $this->assertCount(0, $this->q('//details[@name]'));
        $this->assertGreaterThan(3, $this->q('//details')->length);
    }

    public function testAnInvalidFieldOpensItsPanelAndMarksItsTab(): void
    {
        $form = $this->form();
        $form->submit(['display' => 'grid', 'widthMode' => 'centered', 'maxWidth' => 'wide']);
        $this->render($form);

        $structure = $this->tab(0);
        $this->assertNull($this->attr($this->q('.//details[@data-cb-panel="cb.section.columns.title"]', $structure)[0], 'open'));
        $width = $this->q('.//details[@data-cb-panel="cb.section.settings.panel.width"]', $structure)[0];
        $this->assertNotNull($this->attr($width, 'open'));
        $this->assertStringContainsString('cb-panel--has-error', (string) $this->attr($width, 'class'));
        $this->assertStringContainsString(
            'cb-sidebar-tabs__tab--has-error',
            (string) $this->attr($this->q(self::cls('cb-sidebar-tabs__tab'))[0], 'class'),
        );
    }

    public function testDisplayIsAGridOfLabelledIcons(): void
    {
        $this->render($this->form());
        $display = $this->byId('section_settings_display');

        $this->assertStringContainsString('cb-icon-choice--grid', (string) $this->attr($display, 'class'));
        $this->assertStringContainsString('--cb-icon-cols: 4', (string) $this->attr($display, 'style'));
        $this->assertSame('radiogroup', $this->attr($display, 'role'));
        $this->assertSame(['grid', 'slider', 'tabs', 'accordion'], $this->attrs($this->q('.//input', $display), 'value'));
        $this->assertCount(4, $this->q('.//*[local-name()="svg"]', $display));
        $this->assertCount(4, $this->q('.' . self::cls('cb-icon-choice__text'), $display), 'labels stay visible');
        $this->assertSame('checked', $this->attr($this->q('.//input[@value="grid"]', $display)[0], 'checked'));
    }

    public function testThePositionGridReadsLeftToRightWithTheCentreInTheMiddle(): void
    {
        $this->render($this->form());
        $grid = $this->byId('section_settings_styling_backgroundPosition');

        $this->assertSame(
            ['top left', 'top', 'top right', 'left', '', 'right', 'bottom left', 'bottom', 'bottom right'],
            $this->attrs($this->q('.//input', $grid), 'value'),
        );
        $this->assertSame('cb.styling.background_position.top_right', $this->attr($this->q('.//label', $grid)[2], 'title'));
        // Icon-only: the label is kept for screen readers.
        $this->assertCount(9, $this->q('.' . self::cls('cb-form-sr-only'), $grid));
    }

    public function testAMultipleChoiceIsCheckboxesAndAnUndrawnIconFallsBackToText(): void
    {
        $this->render($this->form(withHostFields: true));
        $sides = $this->byId('section_settings_styling_sides');

        $this->assertSame('group', $this->attr($sides, 'role'));
        $this->assertCount(2, $this->q('.//input[@type="checkbox"][@name="section_settings[styling][sides][]"]', $sides));
        $this->assertCount(1, $this->q('.//*[local-name()="svg"]', $sides));
        $this->assertSame(['Pattern'], $this->texts($this->q('.' . self::cls('cb-icon-choice__text'), $sides)));
    }

    public function testTheLongHelpMovesIntoATooltip(): void
    {
        $this->render($this->form());
        $help = $this->byId('section_settings_stylingCustom_help');

        $this->assertStringContainsString('cb.section.settings.styling_custom_help', $help->textContent);
        $bubble = $this->q('.' . self::cls('cb-help-tip__bubble'), $help)[0];
        $this->assertSame('tooltip', $this->attr($bubble, 'role'));
        $this->assertSame('cb.section.settings.styling_custom_tooltip', trim($bubble->textContent));
        $button = $this->q('.' . self::cls('cb-help-tip__button'), $help)[0];
        $this->assertSame($this->attr($bubble, 'id'), $this->attr($button, 'aria-describedby'));
    }

    // ---------- DOM helpers ----------

    private static function cls(string $class): string
    {
        return sprintf('//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $class);
    }

    /** @return \DOMNodeList<\DOMNode> */
    private function q(string $xpath, ?\DOMNode $context = null): \DOMNodeList
    {
        $nodes = $this->dom->query($xpath, $context);
        \assert($nodes instanceof \DOMNodeList);

        return $nodes;
    }

    private function tab(int $index): \DOMNode
    {
        $tab = $this->q(sprintf('//section[@data-cb-tab="%d"]', $index))[0];
        \assert($tab instanceof \DOMNode);

        return $tab;
    }

    /** @return \DOMNodeList<\DOMNode> */
    private function panels(\DOMNode $tab): \DOMNodeList
    {
        return $this->q('.//details[contains(@class, "cb-panel")]', $tab);
    }

    private function byId(string $id): \DOMNode
    {
        $node = $this->q(sprintf('//*[@id="%s"]', $id))[0];
        \assert($node instanceof \DOMNode);

        return $node;
    }

    private function attr(?\DOMNode $node, string $name): ?string
    {
        return $node instanceof \DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    /**
     * @param \DOMNodeList<\DOMNode> $nodes
     *
     * @return list<string|null>
     */
    private function attrs(\DOMNodeList $nodes, string $name): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = $this->attr($node, $name);
        }

        return $out;
    }

    /**
     * @param \DOMNodeList<\DOMNode> $nodes
     *
     * @return list<string>
     */
    private function texts(\DOMNodeList $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = trim($node->textContent);
        }

        return $out;
    }

    private function form(bool $withHostFields = false, bool $exclusive = true): FormInterface
    {
        $builder = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new SidebarLayoutTypeExtension())
            ->addTypeExtension(new IconChoiceTypeExtension())
            ->addType(new SectionSettingsType(new SectionStyleRegistry([])))
            ->addType(new PaletteColorType(new ColorPaletteRegistry([])));
        if ($withHostFields) {
            $builder->addTypeExtension(new HostSectionFields())->addTypeExtension(new HostStylingFields());
        }

        return $builder->getFormFactory()->createNamed('section_settings', SectionSettingsType::class, [], [
            'column_count' => 2,
            'cb_panels_exclusive' => $exclusive,
        ]);
    }

    private function render(FormInterface $form): void
    {
        $root = \dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root . '/templates', 'ContentBlocks');
        $loader->addPath($root . '/vendor/symfony/twig-bridge/Resources/views/Form');

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $twig->addExtension(new UiIconExtension(new UiIconRegistry([new CoreUiIcons()])));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());

        $html = $twig->render('@ContentBlocks/builder/sidebar_section.html.twig', [
            'form' => $form->createView(),
            'sectionId' => 5,
            'columns' => [['id' => 1, 'label' => null], ['id' => 2, 'label' => 'Intro']],
            'columnCount' => 2,
            'maxColumns' => 20,
        ]);

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $this->dom = new \DOMXPath($document);
    }
}

/** A host adding one field to a tab of its own, and one left ungrouped. */
final class HostSectionFields extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [SectionSettingsType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('anchorId', TextType::class, ['required' => false])
            ->add('seoTitle', TextType::class, ['required' => false, 'cb_group' => 'SEO']);
    }
}

/** A host adding a panel of icon choices to the styling. */
final class HostStylingFields extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [StylingType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('corner', ChoiceType::class, [
                'required' => false,
                'choices' => ['Top left' => 'tl', 'Top right' => 'tr', 'Bottom left' => 'bl', 'Bottom right' => 'br'],
                'cb_icons' => ['tl' => 'corner-tl', 'tr' => 'corner-tr', 'bl' => 'corner-bl', 'br' => 'corner-br'],
                'cb_icon_layout' => 'grid',
                'cb_icon_columns' => 2,
                'cb_panel' => 'Patterns',
                'placeholder' => false,
            ])
            ->add('sides', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'choices' => ['Top' => 'top', 'Pattern' => 'pattern'],
                'cb_icons' => ['top' => 'side-top', 'pattern' => 'not-drawn'],
                'cb_panel' => 'Patterns',
            ]);
    }
}
