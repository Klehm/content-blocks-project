<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Rendering;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Security\DenyAllAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BlockRendererTest extends TestCase
{
    /**
     * Public mode strips deleted entities, blocks without publishedData, and
     * orders by `position` (not previewPosition).
     */
    public function testPublicModeFiltersAndOrdersByPosition(): void
    {
        $area = $this->makeArea();

        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);

        // Three blocks: one published, one deleted (soft), one never published.
        $published = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Visible'], position: 0, previewPosition: 0);
        $deleted = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Old'], position: 1, previewPosition: 1);
        $deleted->setDeleted(true);
        $neverPublished = $this->makeBlock($column, type: 'text', publishedData: null, draftData: ['title' => 'Pending'], position: 2, previewPosition: 2);

        $renderer = $this->makeRenderer(mode: RenderMode::PUBLIC);
        $html = $renderer->render($area, RenderMode::PUBLIC);

        $this->assertStringContainsString('Visible', $html);
        $this->assertStringNotContainsString('Old', $html);
        $this->assertStringNotContainsString('Pending', $html);

        // No preview markers / overlay script in public mode.
        $this->assertStringNotContainsString('data-cb-block-id', $html);
        $this->assertStringNotContainsString('preview-overlay', $html);
    }

    /**
     * Preview mode keeps every entity (deleted ones get a marker), uses
     * draftData when present, orders by previewPosition.
     */
    public function testPreviewModeIncludesEverythingWithMarkers(): void
    {
        $area = $this->makeArea();

        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);

        // Block with both published and draft: draft wins in preview.
        $edited = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Old'], draftData: ['title' => 'New'], position: 0, previewPosition: 0);
        // Soft-deleted: still rendered, with marker.
        $deleted = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Goodbye'], position: 1, previewPosition: 1);
        $deleted->setDeleted(true);

        $renderer = $this->makeRenderer(mode: RenderMode::PREVIEW);
        $html = $renderer->render($area, RenderMode::PREVIEW);

        $this->assertStringContainsString('New', $html);
        $this->assertStringNotContainsString('Old', $html);
        $this->assertStringContainsString('Goodbye', $html);
        $this->assertStringContainsString('data-cb-deleted="1"', $html);
        $this->assertStringContainsString('data-cb-block-id', $html);
        $this->assertStringContainsString('data-cb-section-id', $html);
        $this->assertStringContainsString('data-cb-column-id', $html);
        $this->assertStringContainsString('preview-overlay', $html);
    }

    /**
     * In preview, sort is by previewPosition — verify order swap when
     * preview/published positions differ.
     */
    public function testPreviewSortsByPreviewPosition(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);

        $a = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'A'], position: 0, previewPosition: 1);
        $b = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'B'], position: 1, previewPosition: 0);

        $renderer = $this->makeRenderer(mode: RenderMode::PREVIEW);
        $html = $renderer->render($area, RenderMode::PREVIEW);

        $this->assertLessThan(strpos($html, 'A'), strpos($html, 'B'), 'B should appear before A in preview (previewPosition 0 vs 1)');

        $publicHtml = $renderer->render($area, RenderMode::PUBLIC);
        $this->assertLessThan(strpos($publicHtml, 'B'), strpos($publicHtml, 'A'), 'A should appear before B in public (position 0 vs 1)');
    }

    /**
     * Deletion cascades visually: a block in a deleted section is rendered
     * with the deleted marker even if its own `deleted` flag is false.
     */
    public function testDeletedFlagCascadesFromSection(): void
    {
        $area = $this->makeArea();

        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $section->setDeleted(true);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $block = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Survives'], position: 0, previewPosition: 0);
        // Note: block itself is NOT deleted.

        $renderer = $this->makeRenderer(mode: RenderMode::PREVIEW);
        $html = $renderer->render($area, RenderMode::PREVIEW);

        // Three deleted markers: section, column (cascaded), block (cascaded).
        $this->assertSame(3, substr_count($html, 'data-cb-deleted="1"'));
    }

    /**
     * resolveMode ignores the query param if the user is not allowed to edit.
     */
    public function testResolveModeFallsBackToPublicWhenAccessDenied(): void
    {
        $area = $this->makeArea();
        $request = new Request(['cb_preview' => '1']);
        $stack = new RequestStack();
        $stack->push($request);

        $renderer = $this->makeRendererWith($stack, new DenyAllAccessChecker());

        $this->assertSame(RenderMode::PUBLIC, $renderer->resolveMode($area));
    }

    public function testResolveModeReturnsPreviewWithGrantedAccessAndQueryParam(): void
    {
        $area = $this->makeArea();
        $request = new Request(['cb_preview' => '1']);
        $stack = new RequestStack();
        $stack->push($request);

        $renderer = $this->makeRendererWith($stack, new AllowAllAccessChecker());

        $this->assertSame(RenderMode::PREVIEW, $renderer->resolveMode($area));
    }

    public function testResolveModeReturnsPublicWithoutQueryParam(): void
    {
        $area = $this->makeArea();
        $request = new Request();
        $stack = new RequestStack();
        $stack->push($request);

        $renderer = $this->makeRendererWith($stack, new AllowAllAccessChecker());

        $this->assertSame(RenderMode::PUBLIC, $renderer->resolveMode($area));
    }

    public function testResolveModeReturnsPublicWithoutRequest(): void
    {
        $area = $this->makeArea();
        $renderer = $this->makeRendererWith(new RequestStack(), new AllowAllAccessChecker());

        $this->assertSame(RenderMode::PUBLIC, $renderer->resolveMode($area));
    }

    public function testDefaultEqualSectionSettingsAreNotEmittedToTheDom(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $section->setPublishedSettings([
            'backgroundColor' => '#ffffff', // matches default → stripped
            'classes' => 'kept',            // not in defaults → passes through
        ]);

        $registry = new BlockTypeRegistry();
        $registry->register($this->textBlockType());

        $bgDecorator = new class implements \ContentBlocks\Section\SectionDecoratorInterface {
            public function decorate(array $settings, Section $section): \ContentBlocks\Section\SectionDecoration
            {
                $color = $settings['backgroundColor'] ?? null;
                if (!\is_string($color)) {
                    return new \ContentBlocks\Section\SectionDecoration();
                }
                return new \ContentBlocks\Section\SectionDecoration(inlineStyles: ['background-color' => $color]);
            }
        };

        $defaults = new \ContentBlocks\Section\SectionSettingsDefaults([
            new class implements \ContentBlocks\Section\SectionSettingsDefaultsProviderInterface {
                public function getDefaults(): array { return ['backgroundColor' => '#ffffff']; }
            },
        ]);

        $renderer = new BlockRenderer(
            $this->makeTwig(['text_view.html.twig' => '']),
            new RequestStack(),
            new AllowAllAccessChecker(),
            $registry,
            new \ContentBlocks\Section\SectionDecoratorCollection([
                new \ContentBlocks\Section\BuiltInSectionDecorator(new \ContentBlocks\Section\SectionStyleRegistry()),
                $bgDecorator,
            ]),
            $defaults,
            $this->makeTranslator(),
        );

        $html = $renderer->render($area, RenderMode::PUBLIC);

        $this->assertStringNotContainsString('background-color', $html);
        $this->assertStringContainsString('kept', $html);
    }

    public function testNonDefaultSectionSettingsValuesReachTheDecorators(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $section->setPublishedSettings(['backgroundColor' => '#ff00ff']);

        $registry = new BlockTypeRegistry();
        $registry->register($this->textBlockType());

        $bgDecorator = new class implements \ContentBlocks\Section\SectionDecoratorInterface {
            public function decorate(array $settings, Section $section): \ContentBlocks\Section\SectionDecoration
            {
                $color = $settings['backgroundColor'] ?? null;
                if (!\is_string($color)) {
                    return new \ContentBlocks\Section\SectionDecoration();
                }
                return new \ContentBlocks\Section\SectionDecoration(inlineStyles: ['background-color' => $color]);
            }
        };

        $renderer = new BlockRenderer(
            $this->makeTwig(['text_view.html.twig' => '']),
            new RequestStack(),
            new AllowAllAccessChecker(),
            $registry,
            new \ContentBlocks\Section\SectionDecoratorCollection([$bgDecorator]),
            new \ContentBlocks\Section\SectionSettingsDefaults([
                new class implements \ContentBlocks\Section\SectionSettingsDefaultsProviderInterface {
                    public function getDefaults(): array { return ['backgroundColor' => '#ffffff']; }
                },
            ]),
            $this->makeTranslator(),
        );

        $html = $renderer->render($area, RenderMode::PUBLIC);

        $this->assertStringContainsString('background-color:#ff00ff', $html);
    }

    /**
     * If a block type defines a viewTemplate, it is included with `data` arg.
     */
    public function testBlockViewTemplateIsIncluded(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $block = $this->makeBlock($column, type: 'custom', publishedData: ['title' => 'Hello'], position: 0, previewPosition: 0);

        $registry = new BlockTypeRegistry();
        $registry->register(new class extends AbstractBlockType {
            public static function getType(): string { return 'custom'; }
            public static function getLabel(): string { return 'Custom'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return []; }
            public function getViewTemplate(): ?string { return '@TestRender/custom_block.html.twig'; }
        });

        $renderer = new BlockRenderer(
            $this->makeTwig(extraTemplates: ['custom_block.html.twig' => '<p class="cb-custom">Custom: {{ data.title }}</p>']),
            new RequestStack(),
            new AllowAllAccessChecker(),
            $registry,
            new \ContentBlocks\Section\SectionDecoratorCollection([]),
            new \ContentBlocks\Section\SectionSettingsDefaults([]),
            $this->makeTranslator(),
        );

        $html = $renderer->render($area, RenderMode::PUBLIC);

        $this->assertStringContainsString('<p class="cb-custom">Custom: Hello</p>', $html);
    }

    // -------- Test factories below --------

    private function makeRenderer(RenderMode $mode = RenderMode::PUBLIC): BlockRenderer
    {
        $request = new Request($mode === RenderMode::PREVIEW ? ['cb_preview' => '1'] : []);
        $stack = new RequestStack();
        $stack->push($request);

        $registry = new BlockTypeRegistry();
        $registry->register($this->textBlockType());

        return new BlockRenderer(
            $this->makeTwig(['text_view.html.twig' => '{{ data.title|default("") }}']),
            $stack,
            new AllowAllAccessChecker(),
            $registry,
            new \ContentBlocks\Section\SectionDecoratorCollection([]),
            new \ContentBlocks\Section\SectionSettingsDefaults([]),
            $this->makeTranslator(),
        );
    }

    /**
     * Generic "text" block type used by most rendering tests; renders {{ data.title }}.
     */
    private function textBlockType(): AbstractBlockType
    {
        return new class extends AbstractBlockType {
            public static function getType(): string { return 'text'; }
            public static function getLabel(): string { return 'Text'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return ['title' => '']; }
            public function getViewTemplate(): ?string { return '@TestRender/text_view.html.twig'; }
        };
    }

    private function makeRendererWith(RequestStack $stack, AccessCheckerInterface $checker): BlockRenderer
    {
        return new BlockRenderer(
            $this->makeTwig(),
            $stack,
            $checker,
            new BlockTypeRegistry(),
            new \ContentBlocks\Section\SectionDecoratorCollection([]),
            new \ContentBlocks\Section\SectionSettingsDefaults([]),
            $this->makeTranslator(),
        );
    }

    private function makeTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            use TranslatorTrait;
        };
    }

    /**
     * Real Twig environment with the package's templates, optionally augmented
     * with extra templates written to a temp dir under namespace `@TestRender`.
     *
     * @param array<string, string> $extraTemplates filename => content
     */
    private function makeTwig(array $extraTemplates = []): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ContentBlocks');

        if (!empty($extraTemplates)) {
            $tmpDir = sys_get_temp_dir() . '/cb-test-' . uniqid('', true);
            mkdir($tmpDir, 0o777, true);
            foreach ($extraTemplates as $name => $content) {
                file_put_contents($tmpDir . '/' . $name, $content);
            }
            $loader->addPath($tmpDir, 'TestRender');
        }

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension($this->makeTranslator()));

        return $env;
    }

    private function makeArea(int $id = 1): ContentArea
    {
        $area = new ContentArea();
        $this->setId($area, $id);
        return $area;
    }

    private function makeSection(ContentArea $area, string $layout, int $position, int $previewPosition, ?int $id = null): Section
    {
        static $auto = 100;
        $section = new Section();
        $section->setLayout($layout);
        $section->setPosition($position);
        $section->setPreviewPosition($previewPosition);
        $area->addSection($section);
        $this->setId($section, $id ?? $auto++);
        return $section;
    }

    private function makeColumn(Section $section, int $position, int $previewPosition, ?int $id = null): Column
    {
        static $auto = 200;
        $column = new Column();
        $column->setPosition($position);
        $column->setPreviewPosition($previewPosition);
        $section->addColumn($column);
        $this->setId($column, $id ?? $auto++);
        return $column;
    }

    /**
     * @param array<string, mixed>|null $publishedData
     * @param array<string, mixed>|null $draftData
     */
    private function makeBlock(
        Column $column,
        string $type,
        ?array $publishedData,
        ?array $draftData = null,
        int $position = 0,
        int $previewPosition = 0,
        ?int $id = null,
    ): Block {
        static $auto = 1000;
        $block = new Block();
        $block->setType($type);
        $block->setPublishedData($publishedData);
        $block->setDraftData($draftData);
        $block->setPosition($position);
        $block->setPreviewPosition($previewPosition);
        $column->addBlock($block);
        $this->setId($block, $id ?? $auto++);
        return $block;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setValue($entity, $id);
    }
}
