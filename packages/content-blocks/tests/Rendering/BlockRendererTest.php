<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Rendering;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\BlockDataResolverCollection;
use ContentBlocks\Rendering\BlockDataResolverInterface;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Rendering\CoreBlockDataResolver;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\Twig\SectionLayoutExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BlockRendererTest extends TestCase
{
    /**
     * Public mode shows the last published state: published blocks, at their
     * published `position` (not previewPosition).
     *
     * A soft-deleted block is still one of them. `deleted` is a draft flag —
     * an intent to remove at the next Publish — so honouring it here would
     * mean an editor pressing Delete edits the live site, which is precisely
     * what {@see PublishedRenderImmutabilityTest} exists to forbid.
     */
    public function testPublicModeShowsPublishedStateAndOrdersByPosition(): void
    {
        $area = $this->makeArea();

        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);

        // Three blocks: one published, one published then soft-deleted, one
        // never published.
        $published = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Visible'], position: 0, previewPosition: 0);
        $deleted = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Doomed'], position: 1, previewPosition: 1);
        $deleted->setDeleted(true);
        $neverPublished = $this->makeBlock($column, type: 'text', publishedData: null, draftData: ['title' => 'Pending'], position: 2, previewPosition: 2);

        $renderer = $this->makeRenderer(mode: RenderMode::PUBLIC);
        $html = $renderer->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('Visible', $html);
        $this->assertStringContainsString('Doomed', $html);
        $this->assertStringNotContainsString('Pending', $html);
        // …and without the draft marker: nothing on a public page is "pending".
        $this->assertStringNotContainsString('data-cb-deleted', $html);

        // No preview markers / overlay script in public mode.
        $this->assertStringNotContainsString('data-cb-block-id', $html);
        $this->assertStringNotContainsString('preview-overlay', $html);
    }

    /**
     * A section (and its columns) added in the builder is not on the public
     * page until Publish stamps it — otherwise adding one would drop an empty
     * section onto the live site, at position 0, ahead of everything.
     */
    public function testPublicModeSkipsSectionsAndColumnsThatWereNeverPublished(): void
    {
        $area = $this->makeArea();

        $live = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $liveColumn = $this->makeColumn($live, position: 0, previewPosition: 0);
        $this->makeBlock($liveColumn, type: 'text', publishedData: ['title' => 'Live'], position: 0, previewPosition: 0);

        $fresh = $this->makeSection($area, layout: Section::LAYOUT_TWO_COLS, position: 0, previewPosition: 1, published: false);
        $freshColumn = $this->makeColumn($fresh, position: 0, previewPosition: 0, published: false);
        $this->makeBlock($freshColumn, type: 'text', publishedData: null, draftData: ['title' => 'Draft'], position: 0, previewPosition: 0);

        $html = $this->makeRenderer(mode: RenderMode::PUBLIC)->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('Live', $html);
        $this->assertStringNotContainsString('Draft', $html);
        $this->assertSame(1, substr_count($html, '<section'), 'the unpublished section has no wrapper on the public page either');
    }

    /**
     * A published block dragged into another column keeps its published home
     * on the public page — the drag writes the column FK, which is the draft
     * location, and `publishedColumnId` is the note it leaves behind.
     */
    public function testPublicModeKeepsADraggedBlockInThePublishedColumn(): void
    {
        $area = $this->makeArea();

        $section = $this->makeSection($area, layout: Section::LAYOUT_TWO_COLS, position: 0, previewPosition: 0);
        $left = $this->makeColumn($section, position: 0, previewPosition: 0, id: 201);
        $right = $this->makeColumn($section, position: 1, previewPosition: 1, id: 202);

        $this->makeBlock($left, type: 'text', publishedData: ['title' => 'Stay'], position: 0, previewPosition: 0);
        $dragged = $this->makeBlock($left, type: 'text', publishedData: ['title' => 'Dragged'], position: 1, previewPosition: 1);
        $dragged->moveTo($right);

        $html = $this->makeRenderer(mode: RenderMode::PUBLIC)->render($area, new RenderContext(RenderMode::PUBLIC));

        $columns = explode('<div class="cb-col', $html);
        $this->assertStringContainsString('Dragged', $columns[1], 'still in the left column publicly');
        $this->assertStringNotContainsString('Dragged', $columns[2]);

        // …while the builder shows it where the editor dropped it.
        $preview = $this->makeRenderer(mode: RenderMode::PREVIEW)->render($area, new RenderContext(RenderMode::PREVIEW));
        $previewColumns = explode('<div class="cb-col', $preview);
        $this->assertStringNotContainsString('Dragged', $previewColumns[1]);
        $this->assertStringContainsString('Dragged', $previewColumns[2]);
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
        $html = $renderer->render($area, new RenderContext(RenderMode::PREVIEW));

        $this->assertStringContainsString('New', $html);
        $this->assertStringNotContainsString('Old', $html);
        $this->assertStringContainsString('Goodbye', $html);
        $this->assertStringContainsString('data-cb-deleted="1"', $html);
        $this->assertStringContainsString('data-cb-block-id', $html);
        $this->assertStringContainsString('data-cb-section-id', $html);
        $this->assertStringContainsString('data-cb-column-id', $html);
        $this->assertStringContainsString('content_blocks_asset_preview_overlay', $html);
    }

    /**
     * `?cb_chrome=0` — draft content, none of the builder's editing furniture.
     *
     * The distinction this pins is between the two things preview mode used to
     * mean at once: *which data* is rendered (draft) and *what is rendered
     * around it* (toolbars, tray, handles). A reader of a draft — a reviewer,
     * an approver, the translation workbench's preview pane — wants the first
     * without the second, and hiding the chrome in CSS afterwards does not
     * count: the overlay script would still load, bind and post messages.
     */
    public function testPreviewWithoutChromeRendersDraftContentAsAReaderWillSeeIt(): void
    {
        $area = $this->makeArea();

        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);

        $edited = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Old'], draftData: ['title' => 'New'], position: 0, previewPosition: 0);
        $deleted = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Goodbye'], position: 1, previewPosition: 1);
        $deleted->setDeleted(true);

        $renderer = $this->makeRenderer(mode: RenderMode::PREVIEW, query: ['cb_chrome' => '0']);
        $html = $renderer->render($area, new RenderContext(RenderMode::PREVIEW));

        // Draft data, exactly as in a normal preview.
        $this->assertStringContainsString('New', $html);
        $this->assertStringNotContainsString('Old', $html);

        // None of the editing furniture, at the source rather than hidden.
        $this->assertStringNotContainsString('content_blocks_asset_preview_overlay', $html);
        $this->assertStringNotContainsString('content_blocks_asset_builder', $html);
        $this->assertStringNotContainsString('cb-add-section-tray', $html);
        $this->assertStringNotContainsString('cb-section-handle', $html);
        $this->assertStringNotContainsString('cb-add-block-inline', $html);

        // Pending deletions are left out: with no chrome to strike them
        // through, showing them would read as live content.
        $this->assertStringNotContainsString('Goodbye', $html);

        // Ids stay: they are what lets a caller scroll to a block and swap one
        // in place — the whole reason this is an iframe and not a screenshot.
        $this->assertStringContainsString('data-cb-block-id', $html);
    }

    /**
     * The chrome is opt-out, so every preview URL that predates it is
     * unchanged.
     */
    public function testPreviewKeepsItsChromeUnlessAskedOtherwise(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $this->makeBlock($column, type: 'text', publishedData: null, draftData: ['title' => 'New'], position: 0, previewPosition: 0);

        foreach ([[], ['cb_chrome' => '1'], ['cb_chrome' => 'yes']] as $query) {
            $html = $this->makeRenderer(mode: RenderMode::PREVIEW, query: $query)
                ->render($area, new RenderContext(RenderMode::PREVIEW));

            $this->assertStringContainsString('content_blocks_asset_preview_overlay', $html, json_encode($query));
            $this->assertStringContainsString('cb-add-section-tray', $html, json_encode($query));
        }
    }

    /** A public page never had chrome, and asking for it cannot conjure any. */
    public function testChromeIsNeverAddedToAPublicRender(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Visible'], position: 0, previewPosition: 0);

        $html = $this->makeRenderer(mode: RenderMode::PUBLIC, query: ['cb_chrome' => '1'])
            ->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('Visible', $html);
        $this->assertStringNotContainsString('content_blocks_asset_preview_overlay', $html);
        $this->assertStringNotContainsString('cb-add-section-tray', $html);
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
        $html = $renderer->render($area, new RenderContext(RenderMode::PREVIEW));

        $this->assertLessThan(strpos($html, 'A'), strpos($html, 'B'), 'B should appear before A in preview (previewPosition 0 vs 1)');

        $publicHtml = $renderer->render($area, new RenderContext(RenderMode::PUBLIC));
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
        $html = $renderer->render($area, new RenderContext(RenderMode::PREVIEW));

        // Three deleted markers: section, column (cascaded), block (cascaded).
        $this->assertSame(3, substr_count($html, 'data-cb-deleted="1"'));
    }

    /**
     * A deleted section is still in the preview DOM, hidden. It must not keep
     * the area out of its empty state, or the overlay and a reload disagree.
     */
    public function testAreaWithOnlyDeletedSectionsRendersTheEmptyState(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $renderer = $this->makeRenderer(mode: RenderMode::PREVIEW);

        $live = $renderer->render($area, new RenderContext(RenderMode::PREVIEW));
        $this->assertStringNotContainsString('cb-content-area--empty', $live);

        $section->setDeleted(true);
        $html = $renderer->render($area, new RenderContext(RenderMode::PREVIEW));
        $this->assertStringContainsString('cb-content-area--empty', $html);
        $this->assertStringContainsString('data-cb-deleted="1"', $html);
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

        $bgDecorator = new class () implements \ContentBlocks\Section\SectionDecoratorInterface {
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
            new class () implements \ContentBlocks\Section\SectionSettingsDefaultsProviderInterface {
                public function getDefaults(): array
                {
                    return ['backgroundColor' => '#ffffff'];
                }
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
            new \ContentBlocks\Section\SectionStyleRegistry([]),
            $this->makeTranslator(),
            new \ContentBlocks\Block\BlockDecoratorCollection([]),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
        );

        $html = $renderer->render($area, new RenderContext(RenderMode::PUBLIC));

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

        $bgDecorator = new class () implements \ContentBlocks\Section\SectionDecoratorInterface {
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
                new class () implements \ContentBlocks\Section\SectionSettingsDefaultsProviderInterface {
                    public function getDefaults(): array
                    {
                        return ['backgroundColor' => '#ffffff'];
                    }
                },
            ]),
            new \ContentBlocks\Section\SectionStyleRegistry([]),
            $this->makeTranslator(),
            new \ContentBlocks\Block\BlockDecoratorCollection([]),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
        );

        $html = $renderer->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('background-color:#ff00ff', $html);
    }

    /**
     * A style preset's settings apply as the base layer under the section's
     * own settings: preset-only keys render, and the section's explicit
     * values win key-by-key.
     */
    public function testPresetSettingsMergeUnderneathSectionSettings(): void
    {
        $styleRegistry = new \ContentBlocks\Section\SectionStyleRegistry([
            new class () implements \ContentBlocks\Section\SectionStyleProviderInterface {
                public function getStyles(): array
                {
                    return [new \ContentBlocks\Section\SectionStyle(
                        'boxed',
                        'Boxed',
                        'sec--boxed',
                        ['backgroundColor' => '#111111'],
                    )];
                }
            },
        ]);

        $bgDecorator = new class () implements \ContentBlocks\Section\SectionDecoratorInterface {
            public function decorate(array $settings, Section $section): \ContentBlocks\Section\SectionDecoration
            {
                $color = $settings['backgroundColor'] ?? null;
                if (!\is_string($color)) {
                    return new \ContentBlocks\Section\SectionDecoration();
                }
                return new \ContentBlocks\Section\SectionDecoration(inlineStyles: ['background-color' => $color]);
            }
        };

        $registry = new BlockTypeRegistry();
        $registry->register($this->textBlockType());

        $makeRenderer = fn (): BlockRenderer => new BlockRenderer(
            $this->makeTwig(['text_view.html.twig' => '']),
            new RequestStack(),
            new AllowAllAccessChecker(),
            $registry,
            new \ContentBlocks\Section\SectionDecoratorCollection([
                new \ContentBlocks\Section\BuiltInSectionDecorator($styleRegistry),
                $bgDecorator,
            ]),
            new \ContentBlocks\Section\SectionSettingsDefaults([]),
            $styleRegistry,
            $this->makeTranslator(),
            new \ContentBlocks\Block\BlockDecoratorCollection([]),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
        );

        // Preset alone: its class AND its settings values render.
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $section->setPublishedSettings(['styleName' => 'boxed']);

        $html = $makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));
        $this->assertStringContainsString('sec--boxed', $html);
        $this->assertStringContainsString('background-color:#111111', $html);

        // Section's own value wins over the preset's for the same key.
        $area2 = $this->makeArea();
        $section2 = $this->makeSection($area2, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $section2->setPublishedSettings(['styleName' => 'boxed', 'backgroundColor' => '#222222']);

        $html2 = $makeRenderer()->render($area2, new RenderContext(RenderMode::PUBLIC));
        $this->assertStringContainsString('sec--boxed', $html2);
        $this->assertStringContainsString('background-color:#222222', $html2);
        $this->assertStringNotContainsString('#111111', $html2);

        // Unknown preset name: no class, no crash.
        $area3 = $this->makeArea();
        $section3 = $this->makeSection($area3, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $section3->setPublishedSettings(['styleName' => 'gone']);

        $html3 = $makeRenderer()->render($area3, new RenderContext(RenderMode::PUBLIC));
        $this->assertStringNotContainsString('background-color', $html3);
    }

    public function testColumnWidthsAreEmittedAsPerColumnFlexWeights(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_TWO_COLS, position: 0, previewPosition: 0);
        $this->makeColumn($section, position: 0, previewPosition: 0);
        $this->makeColumn($section, position: 1, previewPosition: 1);
        $section->setPublishedSettings(['columnWidths' => '40,60']);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('cb-col--weighted', $html);
        $this->assertStringContainsString('--cb-col-grow: 40', $html);
        $this->assertStringContainsString('--cb-col-grow: 60', $html);
    }

    public function testRenderSectionEmitsTheSectionWrapperWithColumnWidths(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_TWO_COLS, position: 0, previewPosition: 0);
        $this->makeColumn($section, position: 0, previewPosition: 0);
        $this->makeColumn($section, position: 1, previewPosition: 1);
        $section->setDraftSettings(['columnWidths' => '40,60']);

        $html = $this->makeRenderer(mode: RenderMode::PREVIEW)->renderSection($section, new RenderContext(RenderMode::PREVIEW));

        // A single <section> wrapper carrying its preview marker + the weighted
        // columns — i.e. exactly what the builder copies onto the live nodes.
        $this->assertSame(1, substr_count($html, '<section'));
        $this->assertStringContainsString('data-cb-section-id="' . $section->getId() . '"', $html);
        $this->assertStringContainsString('cb-col--weighted', $html);
        $this->assertStringContainsString('--cb-col-grow: 40', $html);
        $this->assertStringContainsString('--cb-col-grow: 60', $html);
    }

    public function testMalformedColumnWidthsFallBackToEqualLayout(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_TWO_COLS, position: 0, previewPosition: 0);
        $this->makeColumn($section, position: 0, previewPosition: 0);
        $this->makeColumn($section, position: 1, previewPosition: 1);
        // Wrong count for a 2-column section → ignored, no weighted markup.
        $section->setPublishedSettings(['columnWidths' => '33,33,34']);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringNotContainsString('cb-col--weighted', $html);
        $this->assertStringNotContainsString('--cb-col-grow', $html);
    }

    /**
     * If a block type defines a viewTemplate, it is included with `data` arg.
     */
    public function testATabsSectionRendersOneRadioAndLabelPerColumn(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'tabs', 0, 0, 40);
        $section->setPublishedSettings(['display' => 'tabs']);
        $first = $this->makeColumn($section, 0, 0, 41);
        $first->setPublishedSettings(['label' => 'Overview']);
        $this->makeColumn($section, 1, 1, 42);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertSame(2, substr_count($html, 'class="cb-tabs__radio"'));
        $this->assertMatchesRegularExpression('/id="cb-tabs-40-0"[^>]*checked>/', $html);
        $this->assertStringNotContainsString('id="cb-tabs-40-1" aria-controls="cb-tabs-40-1-panel" checked', $html);
        $this->assertStringContainsString('>Overview</label>', $html);
        // A column with no name is numbered.
        $this->assertStringContainsString('>cb.section.tabs.untitled</label>', $html);
        $this->assertStringContainsString('id="cb-tabs-40-1-panel" role="region" aria-labelledby="cb-tabs-40-1-label"', $html);
        // The nav comes after the radios and before the row: `~` needs both.
        $this->assertLessThan(strpos($html, 'cb-tabs__nav'), strrpos($html, 'cb-tabs__radio'));
        $this->assertLessThan(strpos($html, 'class="cb-row"'), strpos($html, 'cb-tabs__nav'));
    }

    public function testAnAccordionSectionPutsAToggleAndHeaderBeforeEachPanel(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'two_cols', 0, 0, 80);
        $section->setPublishedSettings(['display' => 'accordion']);
        $this->makeColumn($section, 0, 0, 81)->setPublishedSettings(['label' => 'Shipping']);
        $this->makeColumn($section, 1, 1, 82);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertSame(2, substr_count($html, 'class="cb-accordion__toggle"'));
        $this->assertStringNotContainsString('cb-tabs__', $html);
        $this->assertMatchesRegularExpression('/type="checkbox" class="cb-accordion__toggle" id="cb-accordion-80-0"[^>]*checked>/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="cb-accordion-80-1"[^>]*checked>/', $html);
        $this->assertStringContainsString('>Shipping</label>', $html);
        $this->assertStringContainsString('>cb.section.accordion.untitled</label>', $html);
        $this->assertStringContainsString('id="cb-accordion-80-1-panel" role="region" aria-labelledby="cb-accordion-80-1-label"', $html);
        // Toggle, header, panel: siblings in that order inside the row.
        $row = strpos($html, 'class="cb-row"');
        $toggle = strpos($html, 'id="cb-accordion-80-1"');
        $header = strpos($html, 'id="cb-accordion-80-1-label"');
        $panel = strpos($html, 'id="cb-accordion-80-1-panel"');
        $this->assertTrue($row < $toggle && $toggle < $header && $header < $panel);
    }

    /** Radios plus a "none" one, and a twin header that closes the panel. */
    public function testAOneAtATimeAccordionRendersRadiosAndCloseHeaders(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'two_cols', 0, 0, 84);
        $section->setPublishedSettings(['display' => 'accordion', 'accordionSingle' => true]);
        $this->makeColumn($section, 0, 0, 85)->setPublishedSettings(['label' => 'Shipping']);
        $this->makeColumn($section, 1, 1, 86);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringNotContainsString('type="checkbox"', $html);
        $this->assertSame(3, substr_count($html, 'type="radio" class="cb-accordion__toggle'));
        $this->assertMatchesRegularExpression('/id="cb-accordion-84-none"[^>]*aria-hidden="true">/', $html);
        $this->assertMatchesRegularExpression('/name="cb-accordion-84" id="cb-accordion-84-0"[^>]*checked>/', $html);
        $this->assertSame(2, substr_count($html, 'for="cb-accordion-84-none"'));
        $this->assertMatchesRegularExpression('/cb-accordion__header--close">Shipping<\/label>/', $html);
        // The close twin sits between the header and the panel.
        $this->assertLessThan(
            strpos($html, 'id="cb-accordion-84-0-panel"'),
            strpos($html, 'for="cb-accordion-84-none"'),
        );
    }

    public function testACollapsedAccordionOpensNothing(): void
    {
        $area = $this->makeArea();
        $multi = $this->makeSection($area, 'two_cols', 0, 0, 87);
        $multi->setPublishedSettings(['display' => 'accordion', 'accordionCollapsed' => true]);
        $this->makeColumn($multi, 0, 0, 88);
        $single = $this->makeSection($area, 'two_cols', 1, 1, 89);
        $single->setPublishedSettings(['display' => 'accordion', 'accordionSingle' => true, 'accordionCollapsed' => true]);
        $this->makeColumn($single, 0, 0, 90);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertDoesNotMatchRegularExpression('/id="cb-accordion-87-0"[^>]*checked>/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="cb-accordion-89-0"[^>]*checked>/', $html);
        $this->assertMatchesRegularExpression('/id="cb-accordion-89-none"[^>]*checked>/', $html);
    }

    /** As with tabs, a column pending deletion is skipped. */
    public function testThePreviewOpensTheFirstLiveAccordionPanel(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'two_cols', 0, 0, 90);
        $section->setDraftSettings(['display' => 'accordion']);
        $this->makeColumn($section, 0, 0, 91)->setDeleted(true);
        $this->makeColumn($section, 1, 1, 92);

        $html = $this->makeRenderer(RenderMode::PREVIEW)->render($area, new RenderContext(RenderMode::PREVIEW));

        $this->assertMatchesRegularExpression('/id="cb-accordion-90-1"[^>]*checked>/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="cb-accordion-90-0"[^>]*checked>/', $html);
        $this->assertStringContainsString('cb-accordion__header cb-accordion__header--deleted', $html);
    }

    /** The headers are labels for the tab radios: no second input. */
    public function testTabsTurningIntoAnAccordionAddHeadersForTheTabRadios(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'tabs', 0, 0, 93);
        $section->setPublishedSettings(['display' => 'tabs', 'displayMobile' => 'accordion']);
        $this->makeColumn($section, 0, 0, 94)->setPublishedSettings(['label' => 'Specs']);
        $this->makeColumn($section, 1, 1, 95);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertSame(2, substr_count($html, 'class="cb-tabs__radio"'));
        $this->assertStringNotContainsString('cb-accordion__toggle', $html);
        $this->assertStringContainsString('<label for="cb-tabs-93-0" class="cb-accordion__header">Specs</label>', $html);
        $this->assertStringContainsString('<label for="cb-tabs-93-1" class="cb-accordion__header">cb.section.tabs.untitled</label>', $html);
        // Inside the row, each right before its panel.
        $this->assertLessThan(strpos($html, 'for="cb-tabs-93-0" class="cb-accordion__header"'), strpos($html, 'class="cb-row"'));
        $this->assertLessThan(strpos($html, 'id="cb-tabs-93-0-panel"'), strpos($html, 'for="cb-tabs-93-0" class="cb-accordion__header"'));
    }

    public function testAGridTurningIntoAnAccordionShipsTheAccordionMarkup(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'two_cols', 0, 0, 96);
        $section->setPublishedSettings(['displayTablet' => 'accordion', 'accordionSingle' => true]);
        $this->makeColumn($section, 0, 0, 97);
        $this->makeColumn($section, 1, 1, 98);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringNotContainsString('cb-tabs__', $html);
        $this->assertMatchesRegularExpression('/id="cb-accordion-96-none"/', $html);
        $this->assertSame(2, substr_count($html, 'class="cb-accordion__header cb-accordion__header--close"'));
        $this->assertStringContainsString('id="cb-accordion-96-1-panel" role="region"', $html);
    }

    public function testASliderCarriesItsOptionsAndLoadsTheScript(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'three_cols', 0, 0, 99);
        $section->setPublishedSettings([
            'displayMobile' => 'slider',
            'sliderControls' => 'dots',
            'sliderAutoplay' => 5,
        ]);
        $this->makeColumn($section, 0, 0);
        $grid = $this->makeSection($area, 'full', 1, 1);
        $this->makeColumn($grid, 0, 0);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertSame(1, substr_count($html, 'data-cb-slider='));
        $this->assertStringContainsString('&quot;controls&quot;:&quot;dots&quot;,&quot;autoplay&quot;:5,&quot;loop&quot;:false', $html);
        $this->assertStringContainsString('&quot;previous&quot;:&quot;cb.slider.previous&quot;', $html);
        $this->assertSame(1, substr_count($html, 'src="/_route/content_blocks_asset_slider"'));
    }

    public function testAPageWithoutSliderLoadsNoScript(): void
    {
        $area = $this->makeArea();
        $this->makeColumn($this->makeSection($area, 'full', 0, 0), 0, 0);

        $public = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));
        $builder = $this->makeRenderer(RenderMode::PREVIEW)->render($area, new RenderContext(RenderMode::PREVIEW));

        $this->assertStringNotContainsString('content_blocks_asset_slider', $public);
        // The builder can turn a section into a slider in place.
        $this->assertStringContainsString('content_blocks_asset_slider', $builder);
    }

    public function testSectionRanksBecomeOrderVariablesOnEverySibling(): void
    {
        $area = $this->makeArea();
        $first = $this->makeSection($area, 'full', 0, 0, 301);
        $second = $this->makeSection($area, 'full', 1, 1, 302);
        $first->setPublishedSettings(['_order' => ['mobile' => 1]]);
        $second->setPublishedSettings(['_order' => ['mobile' => 0]]);
        $this->makeColumn($first, 0, 0);
        $this->makeColumn($second, 0, 0);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('class="cb-content-area cb-content-area--ordered"', $html);
        $this->assertStringContainsString('style="--cb-order-m:1;"', $html);
        $this->assertStringContainsString('style="--cb-order-m:0;"', $html);
    }

    /** Ranks are content like any other: the page keeps the published ones. */
    public function testThePublicPageReadsPublishedRanksAndThePreviewDraftOnes(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'full', 0, 0);
        $column = $this->makeColumn($section, 0, 0);
        $this->makeBlock($column, 'text', ['title' => 'A'], ['title' => 'A', '_order' => ['tablet' => 1]], 0, 0, 311);
        $this->makeBlock($column, 'text', ['title' => 'B'], ['title' => 'B', '_order' => ['tablet' => 0]], 1, 1, 312);

        $public = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));
        $preview = $this->makeRenderer(RenderMode::PREVIEW)->render($area, new RenderContext(RenderMode::PREVIEW));

        $this->assertStringNotContainsString('--cb-order-', $public);
        $this->assertStringNotContainsString('cb-content-area--ordered', $public);
        $this->assertMatchesRegularExpression('/data-cb-block-id="311"[^>]*style="--cb-order-t:1;"/s', $preview);
        $this->assertMatchesRegularExpression('/data-cb-block-id="312"[^>]*style="--cb-order-t:0;"/s', $preview);
        // Blocks ordered, sections not: the area stays a plain block box.
        $this->assertStringNotContainsString('cb-content-area--ordered', $preview);
    }

    /** A hot-swapped node must carry the same variables as a full render. */
    public function testRenderingOneSectionOrBlockKeepsItsOrderVariables(): void
    {
        $area = $this->makeArea();
        $top = $this->makeSection($area, 'full', 0, 0, 321);
        $bottom = $this->makeSection($area, 'full', 1, 1, 322);
        $bottom->setDraftSettings(['_order' => ['mobile' => 0]]);
        $column = $this->makeColumn($top, 0, 0);
        $this->makeColumn($bottom, 0, 0);
        $this->makeBlock($column, 'text', null, ['title' => 'A', '_order' => ['mobile' => 1]], 0, 0, 323);
        $block = $this->makeBlock($column, 'text', null, ['title' => 'B', '_order' => ['mobile' => 0]], 1, 1, 324);

        $renderer = $this->makeRenderer(RenderMode::PREVIEW);
        $section = $renderer->renderSection($top, new RenderContext(RenderMode::PREVIEW));
        $single = $renderer->renderBlock($block, new RenderContext(RenderMode::PREVIEW));

        // Unranked and first on desktop, so first on mobile too.
        $this->assertMatchesRegularExpression('/data-cb-section-id="321"[^>]*style="--cb-order-m:0;"/s', $section);
        $this->assertStringContainsString('style="--cb-order-m:0;"', $single);
    }

    /** A resolver (the i18n package's) decides the title a tab renders. */
    public function testAColumnSettingsResolverRewritesTheTabTitle(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'tabs', 0, 0, 70);
        $section->setPublishedSettings(['display' => 'tabs']);
        $this->makeColumn($section, 0, 0, 71)->setPublishedSettings(['label' => 'Détails']);

        $resolver = new class () implements \ContentBlocks\Rendering\ColumnSettingsResolverInterface {
            public ?RenderContext $seen = null;

            public function resolve(Column $column, RenderContext $context, array $settings): array
            {
                $this->seen = $context;

                return ['label' => 'Details'] + $settings;
            }
        };

        $renderer = $this->makeRenderer(
            columnResolvers: new \ContentBlocks\Rendering\ColumnSettingsResolverCollection([$resolver]),
        );

        $html = $renderer->render($area, RenderContext::forPublic('en'));

        $this->assertStringContainsString('>Details</label>', $html);
        $this->assertSame('en', $resolver->seen?->locale);
    }

    public function testAGridSectionHasNoTabMarkup(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'two_cols', 0, 0);
        $this->makeColumn($section, 0, 0)->setPublishedSettings(['label' => 'Unused']);

        $html = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringNotContainsString('cb-tabs__', $html);
        $this->assertStringNotContainsString('cb-accordion__', $html);
        $this->assertStringNotContainsString('role="region"', $html);
    }

    /** The builder shows the draft; the page keeps the published twins. */
    public function testPublicReadsThePublishedPresetAndLabelPreviewTheDraft(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'tabs', 0, 0, 50);
        $section->setPublishedSettings(['display' => 'tabs']);
        $column = $this->makeColumn($section, 0, 0, 51);
        $column->setPreset('col-12');
        $column->publish();
        $column->setPublishedSettings(['label' => 'Live']);
        $column->setPreset('col-6');
        $column->setDraftSettings(['label' => 'Draft']);

        $public = $this->makeRenderer()->render($area, new RenderContext(RenderMode::PUBLIC));
        $preview = $this->makeRenderer(RenderMode::PREVIEW)->render($area, new RenderContext(RenderMode::PREVIEW));

        $this->assertStringContainsString('cb-col--col-12', $public);
        $this->assertStringContainsString('>Live</label>', $public);
        $this->assertStringContainsString('cb-col--col-6', $preview);
        $this->assertStringContainsString('>Draft</label>', $preview);
    }

    /** The builder keeps a deleted column in the DOM: open a live tab. */
    public function testThePreviewOpensTheFirstLiveTab(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 'tabs', 0, 0, 60);
        $section->setDraftSettings(['display' => 'tabs']);
        $this->makeColumn($section, 0, 0, 61)->setDeleted(true);
        $this->makeColumn($section, 1, 1, 62);

        $html = $this->makeRenderer(RenderMode::PREVIEW)->render($area, new RenderContext(RenderMode::PREVIEW));

        $this->assertMatchesRegularExpression('/id="cb-tabs-60-1"[^>]*checked>/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="cb-tabs-60-0"[^>]*checked>/', $html);
        $this->assertStringContainsString('cb-tabs__tab cb-tabs__tab--deleted', $html);
    }

    public function testBlockViewTemplateIsIncluded(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $this->makeBlock($column, type: 'custom', publishedData: ['title' => 'Hello'], position: 0, previewPosition: 0, id: 77);

        $registry = new BlockTypeRegistry();
        $registry->register(new class () extends AbstractBlockType {
            public function getType(): string
            {
                return 'custom';
            }
            public function getLabel(): string
            {
                return 'Custom';
            }
            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
            }
            public function getDefaultData(): array
            {
                return [];
            }
            public function getViewTemplate(): ?string
            {
                return '@TestRender/custom_block.html.twig';
            }
        });

        $renderer = new BlockRenderer(
            $this->makeTwig(extraTemplates: ['custom_block.html.twig' => '<p class="cb-custom" id="b{{ block_id }}">Custom: {{ data.title }}</p>']),
            new RequestStack(),
            new AllowAllAccessChecker(),
            $registry,
            new \ContentBlocks\Section\SectionDecoratorCollection([]),
            new \ContentBlocks\Section\SectionSettingsDefaults([]),
            new \ContentBlocks\Section\SectionStyleRegistry([]),
            $this->makeTranslator(),
            new \ContentBlocks\Block\BlockDecoratorCollection([]),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
        );

        $html = $renderer->render($area, new RenderContext(RenderMode::PUBLIC));

        // The view also gets the block id, for anchors and ARIA ids.
        $this->assertStringContainsString('<p class="cb-custom" id="b77">Custom: Hello</p>', $html);
    }

    /**
     * renderBlock() produces a standalone fragment: just the block's own
     * wrapper (with its data-cb-block-id marker and rendered view), without
     * the surrounding section/column chrome or the preview overlay script.
     * This is what the builder hot-swaps into the iframe.
     */
    public function testRenderBlockProducesStandaloneFragment(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $block = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Old'], draftData: ['title' => 'Fresh'], position: 0, previewPosition: 0, id: 4242);

        $renderer = $this->makeRenderer(mode: RenderMode::PREVIEW);
        $html = $renderer->renderBlock($block, new RenderContext(RenderMode::PREVIEW));

        // Draft data wins in preview, and the block keeps its marker so the
        // overlay can pin focus on the swapped element.
        $this->assertStringContainsString('Fresh', $html);
        $this->assertStringNotContainsString('Old', $html);
        $this->assertStringContainsString('data-cb-block-id="4242"', $html);
        $this->assertStringContainsString('data-cb-block-type="text"', $html);

        // Fragment only — no section/column wrappers or overlay bootstrap.
        $this->assertStringNotContainsString('data-cb-section-id', $html);
        $this->assertStringNotContainsString('content_blocks_asset_preview_overlay', $html);
    }

    /**
     * In public mode renderBlock() omits the preview-only markers.
     */
    public function testRenderBlockPublicModeOmitsPreviewMarkers(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $block = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Pub'], position: 0, previewPosition: 0, id: 7);

        $renderer = $this->makeRenderer(mode: RenderMode::PUBLIC);
        $html = $renderer->renderBlock($block, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('Pub', $html);
        $this->assertStringNotContainsString('data-cb-block-id', $html);
    }

    // -------- Block data resolution seam --------

    /**
     * The point of the seam, end to end: a resolver registered after the core
     * one rewrites a field, and the rendered HTML shows the rewritten value.
     * This is what a translation package will do with $context->locale — proven
     * here without any such package existing.
     */
    public function testAHostResolverCanRewriteWhatABlockRenders(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn(
            $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0),
            position: 0,
            previewPosition: 0,
        );
        $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Hello']);

        $localeAware = new class () implements BlockDataResolverInterface {
            public function resolve(Block $block, RenderContext $context, array $data): array
            {
                if ($context->locale === 'fr') {
                    $data['title'] = 'Bonjour';
                }

                return $data;
            }
        };

        $renderer = $this->makeRenderer(extraResolvers: [$localeAware]);

        $this->assertStringContainsString(
            'Bonjour',
            $renderer->render($area, new RenderContext(RenderMode::PUBLIC, 'fr')),
        );
        $this->assertStringContainsString(
            'Hello',
            $renderer->render($area, new RenderContext(RenderMode::PUBLIC)),
        );
    }

    /**
     * The chain threads one payload: each resolver sees what the previous
     * produced, so the core seeding step is genuinely first and the rest
     * refine rather than compete.
     */
    public function testResolversRunInOrderEachSeeingThePreviousPayload(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn(
            $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0),
            position: 0,
            previewPosition: 0,
        );
        $this->makeBlock($column, type: 'text', publishedData: ['title' => 'a']);

        $append = fn (string $suffix) => new class ($suffix) implements BlockDataResolverInterface {
            public function __construct(private readonly string $suffix)
            {
            }

            public function resolve(Block $block, RenderContext $context, array $data): array
            {
                $data['title'] = ($data['title'] ?? '') . $this->suffix;

                return $data;
            }
        };

        $html = $this->makeRenderer(extraResolvers: [$append('b'), $append('c')])
            ->render($area, new RenderContext(RenderMode::PUBLIC));

        $this->assertStringContainsString('abc', $html);
    }

    /**
     * The mode is pinned before the pipeline runs, so a resolver can branch on
     * PREVIEW vs PUBLIC without ever handling a null.
     */
    public function testResolversAlwaysSeeAResolvedMode(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn(
            $this->makeSection($area, layout: Section::LAYOUT_FULL, position: 0, previewPosition: 0),
            position: 0,
            previewPosition: 0,
        );
        $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Pub']);

        $spy = new class () implements BlockDataResolverInterface {
            /** @var list<?RenderMode> */
            public array $seen = [];

            public function resolve(Block $block, RenderContext $context, array $data): array
            {
                $this->seen[] = $context->mode;

                return $data;
            }
        };

        // No context at all — the renderer falls back to the request heuristic.
        $this->makeRenderer(extraResolvers: [$spy])->render($area);

        $this->assertSame([RenderMode::PUBLIC], $spy->seen);
    }

    // -------- Test factories below --------

    /**
     * @param list<BlockDataResolverInterface> $extraResolvers
     *        appended after CoreBlockDataResolver, as a host's would be
     */
    private function makeRenderer(
        RenderMode $mode = RenderMode::PUBLIC,
        array $extraResolvers = [],
        array $query = [],
        ?\ContentBlocks\Rendering\ColumnSettingsResolverCollection $columnResolvers = null,
    ): BlockRenderer {
        $request = new Request(($mode === RenderMode::PREVIEW ? ['cb_preview' => '1'] : []) + $query);
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
            new \ContentBlocks\Section\SectionStyleRegistry([]),
            $this->makeTranslator(),
            new \ContentBlocks\Block\BlockDecoratorCollection([]),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver(), ...$extraResolvers]),
            $columnResolvers ?? new \ContentBlocks\Rendering\ColumnSettingsResolverCollection(),
        );
    }

    /**
     * Generic "text" block type used by most rendering tests; renders {{
     * data.title }}.
     */
    private function textBlockType(): AbstractBlockType
    {
        return new class () extends AbstractBlockType {
            public function getType(): string
            {
                return 'text';
            }
            public function getLabel(): string
            {
                return 'Text';
            }
            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
            }
            public function getDefaultData(): array
            {
                return ['title' => ''];
            }
            public function getViewTemplate(): ?string
            {
                return '@TestRender/text_view.html.twig';
            }
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
            new \ContentBlocks\Section\SectionStyleRegistry([]),
            $this->makeTranslator(),
            new \ContentBlocks\Block\BlockDecoratorCollection([]),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
        );
    }

    private function makeTranslator(): TranslatorInterface
    {
        return new class () implements TranslatorInterface {
            use TranslatorTrait;
        };
    }

    private function makeUrlGenerator(): UrlGeneratorInterface
    {
        return new class () implements UrlGeneratorInterface {
            private RequestContext $context;
            public function __construct()
            {
                $this->context = new RequestContext();
            }
            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }
            public function getContext(): RequestContext
            {
                return $this->context;
            }
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                // Stable, deterministic URL for assertions; mirrors the real
                // route shape.
                return '/_route/' . $name;
            }
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
        $env->addExtension(new SectionLayoutExtension(new SectionLayoutRegistry()));
        $env->addExtension(new RoutingExtension($this->makeUrlGenerator()));
        $env->addExtension(new \ContentBlocks\Twig\RoutingExtension($this->makeUrlGenerator()));

        return $env;
    }

    private function makeArea(int $id = 1): ContentArea
    {
        $area = new ContentArea();
        $this->setId($area, $id);
        return $area;
    }

    private function makeSection(ContentArea $area, string $layout, int $position, int $previewPosition, ?int $id = null, bool $published = true): Section
    {
        static $auto = 100;
        $section = new Section();
        $section->setLayout($layout);
        $section->setPosition($position);
        $section->setPreviewPosition($previewPosition);
        $area->addSection($section);
        $this->setId($section, $id ?? $auto++);
        if ($published) {
            $this->markPublished($section);
        }
        return $section;
    }

    private function makeColumn(Section $section, int $position, int $previewPosition, ?int $id = null, bool $published = true): Column
    {
        static $auto = 200;
        $column = new Column();
        $column->setPosition($position);
        $column->setPreviewPosition($previewPosition);
        $section->addColumn($column);
        $this->setId($column, $id ?? $auto++);
        if ($published) {
            $this->markPublished($column);
        }
        return $column;
    }

    /** Stamps publishedAt, the way Section/Column::publish() does. */
    private function markPublished(Section|Column $entity): void
    {
        (new \ReflectionProperty($entity::class, 'publishedAt'))
            ->setValue($entity, new \DateTimeImmutable('2026-01-01 00:00:00'));
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
