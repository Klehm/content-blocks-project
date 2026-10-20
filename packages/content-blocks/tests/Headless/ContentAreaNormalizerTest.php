<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Headless;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDecoratorCollection;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Headless\BlockJsonNormalizerCollection;
use ContentBlocks\Headless\BlockJsonNormalizerInterface;
use ContentBlocks\Headless\ContentAreaNormalizer;
use ContentBlocks\Rendering\BlockDataResolverCollection;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Rendering\BlockView;
use ContentBlocks\Rendering\CoreBlockDataResolver;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionSettingsDefaultsProviderInterface;
use ContentBlocks\Section\SectionStyle;
use ContentBlocks\Section\SectionStyleProviderInterface;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\Twig\HeadlessExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ContentAreaNormalizerTest extends TestCase
{
    public function testThePublishedTree(): void
    {
        $area = $this->area();
        $area->setContentVersion(3);

        $tree = $this->normalizer()->normalize($area, RenderContext::forPublic('fr'));

        $this->assertSame(1, $tree['format']);
        $this->assertSame(1, $tree['id']);
        $this->assertSame('public', $tree['mode']);
        $this->assertSame('fr', $tree['locale']);
        $this->assertSame(3, $tree['contentVersion']);
        $this->assertSame([['A', 'B'], ['C']], $this->titles($tree)[0]);

        $column = $tree['sections'][0]['columns'][0];
        $this->assertSame(20, $column['id']);
        $this->assertSame('col-6', $column['preset']);
        $this->assertSame(6, $column['span']);
        $this->assertNull($column['width']);
        $this->assertNull($column['label']);
        $this->assertSame([
            'id' => 30,
            'type' => 'text',
            'data' => ['title' => 'A'],
            'order' => ['tablet' => null, 'mobile' => null],
        ], $column['blocks'][0]);
    }

    public function testADraftIsOnlyInThePreview(): void
    {
        $area = $this->area();
        $block = $this->block($this->column($area, 20), 40, 'draft only', 2, published: false);
        $this->find($area, 30)->setDraftData(['title' => 'A edited']);

        $normalizer = $this->normalizer();

        $this->assertSame([['A', 'B'], ['C']], $this->titles($normalizer->normalize($area, RenderContext::forPublic()))[0]);
        $this->assertSame(
            [['A edited', 'B', 'draft only'], ['C']],
            $this->titles($normalizer->normalize($area, RenderContext::forPreview()))[0],
        );
        $this->assertNull($normalizer->normalizeBlock($block, RenderContext::forPublic()));
        $this->assertSame('draft only', $normalizer->normalizeBlock($block, RenderContext::forPreview())['data']['title']);
    }

    public function testASoftDeletedNodeStaysPublicAndLeavesThePreview(): void
    {
        $area = $this->area();
        $this->find($area, 31)->setDeleted(true);
        $this->column($area, 21)->setDeleted(true);

        $normalizer = $this->normalizer();

        $this->assertSame([['A', 'B'], ['C']], $this->titles($normalizer->normalize($area, RenderContext::forPublic()))[0]);
        $this->assertSame([['A']], $this->titles($normalizer->normalize($area, RenderContext::forPreview()))[0]);
        $this->assertNull($normalizer->normalizeBlock($this->find($area, 31), RenderContext::forPreview()));
    }

    public function testAMovedBlockStaysInItsPublishedColumn(): void
    {
        $area = $this->area();
        $this->find($area, 30)->moveTo($this->column($area, 21))->setPreviewPosition(1);

        $normalizer = $this->normalizer();

        $this->assertSame([['A', 'B'], ['C']], $this->titles($normalizer->normalize($area, RenderContext::forPublic()))[0]);
        $this->assertSame([['B'], ['C', 'A']], $this->titles($normalizer->normalize($area, RenderContext::forPreview()))[0]);
    }

    public function testOrderPerViewportIsAPosition(): void
    {
        $area = $this->area();
        $this->find($area, 30)->setPublishedData(['title' => 'A', '_order' => ['mobile' => 1]]);
        $this->find($area, 31)->setPublishedData(['title' => 'B', '_order' => ['mobile' => 0]]);

        $blocks = $this->normalizer()->normalize($area, RenderContext::forPublic())['sections'][0]['columns'][0]['blocks'];

        $this->assertSame(['tablet' => null, 'mobile' => 1], $blocks[0]['order']);
        $this->assertSame(['tablet' => null, 'mobile' => 0], $blocks[1]['order']);
        $this->assertSame(['title' => 'B'], $blocks[1]['data']);
    }

    public function testSectionSettingsAreWhatTheHtmlRenderReads(): void
    {
        $area = $this->area();
        $area->getSections()->first()->setPublishedSettings([
            'styleName' => 'boxed',
            'display' => 'slider',
            'displayMobile' => 'accordion',
            'gap' => 'md',
            'columnWidths' => '40,60',
            '_order' => ['tablet' => 1],
        ]);
        $area->getSections()[1]->setPublishedSettings(['_order' => ['tablet' => 0]]);

        $section = $this->normalizer()->normalize($area, RenderContext::forPublic())['sections'][0];

        $this->assertEquals([
            'styleName' => 'boxed',
            'display' => 'slider',
            'displayMobile' => 'accordion',
            'columnWidths' => '40,60',
            'padding' => 40,
        ], $section['settings']);
        $this->assertSame(['desktop' => 'slider', 'tablet' => 'slider', 'mobile' => 'accordion'], $section['display']);
        $this->assertSame(['single' => false, 'collapsed' => false], $section['accordion']);
        $this->assertSame([
            'controls' => 'both',
            'autoplay' => 0,
            'loop' => false,
            'perView' => ['desktop' => 1, 'tablet' => 1, 'mobile' => 1],
        ], $section['slider']);
        $this->assertSame(['tablet' => 1, 'mobile' => null], $section['order']);
        $this->assertSame([40, 60], array_column($section['columns'], 'width'));
    }

    public function testBlockNormalizersRunInTurnWithTheRenderLocale(): void
    {
        $seen = [];
        $first = new class ($seen) implements BlockJsonNormalizerInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }
            public function normalize(BlockView $block, array $data, RenderContext $context): array
            {
                $this->seen[] = $block->type . '@' . $context->locale;

                return $data + ['upper' => strtoupper((string) $data['title'])];
            }
        };
        $second = new class () implements BlockJsonNormalizerInterface {
            public function normalize(BlockView $block, array $data, RenderContext $context): array
            {
                return $data + ['length' => \strlen((string) $data['upper'])];
            }
        };
        $stack = new RequestStack();
        $request = new Request();
        $request->setLocale('de');
        $stack->push($request);

        $block = $this->normalizer(normalizers: [$first, $second], stack: $stack)
            ->normalizeBlock($this->find($this->area(), 30), RenderContext::forPublic());

        $this->assertSame(['title' => 'A', 'upper' => 'A', 'length' => 1], $block['data']);
        $this->assertSame(['text@de'], $seen);
    }

    public function testNormalizeSection(): void
    {
        $area = $this->area();
        $draft = $this->section($area, 12, 2, published: false);

        $normalizer = $this->normalizer();

        $this->assertSame(11, $normalizer->normalizeSection($area->getSections()[1], RenderContext::forPublic())['id']);
        $this->assertNull($normalizer->normalizeSection($draft, RenderContext::forPublic()));
        $this->assertSame(12, $normalizer->normalizeSection($draft, RenderContext::forPreview())['id']);
    }

    public function testWithNoModeTheRequestDecidesAsForHtml(): void
    {
        $area = $this->area();
        $this->find($area, 30)->setDraftData(['title' => 'A edited']);

        $this->assertSame('public', $this->normalizer(new AllowAllAccessChecker(), [], $this->stack([]))->normalize($area)['mode']);
        $this->assertSame('public', $this->normalizer(new DenyAllAccessChecker(), [], $this->stack(['cb_preview' => '1']))->normalize($area)['mode']);
        $tree = $this->normalizer(new AllowAllAccessChecker(), [], $this->stack(['cb_preview' => '1']))->normalize($area);
        $this->assertSame('preview', $tree['mode']);
        $this->assertSame('A edited', $tree['sections'][0]['columns'][0]['blocks'][0]['data']['title']);
    }

    public function testJsonKeepsMapsObjectsAndIsSafeInAScript(): void
    {
        $area = $this->area();
        $this->find($area, 30)->setPublishedData(['title' => '</script><b>"x"</b>']);
        $this->find($area, 33)->setPublishedData([]);

        $json = $this->normalizer()->toJson($area, RenderContext::forPublic());

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertStringContainsString('"settings":{}', $json);
        $this->assertStringContainsString('"data":{}', $json);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('</script><b>"x"</b>', $decoded['sections'][0]['columns'][0]['blocks'][0]['data']['title']);
    }

    public function testTwigFunctions(): void
    {
        $extension = new HeadlessExtension($this->normalizer());

        $this->assertNull($extension->data(null));
        $this->assertSame('null', $extension->json(null));
        $this->assertSame('fr', $extension->data($this->area(), 'fr')['locale']);
        $this->assertSame('public', json_decode($extension->json($this->area()), true)['mode']);
    }

    // ---------------------------------------------------------------

    /**
     * #10 two_cols ─ col #20 ─ #30 "A", #31 "B"
     *              └ col #21 ─ #32 "C"
     * #11 full     ─ col #22 ─ #33 "D".
     */
    private function area(): ContentArea
    {
        $area = new ContentArea();
        $this->setId($area, 1);
        $s1 = $this->section($area, 10, 0);
        $c1 = $this->addColumn($s1, 20, 'col-6', 0);
        $this->block($c1, 30, 'A', 0);
        $this->block($c1, 31, 'B', 1);
        $this->block($this->addColumn($s1, 21, 'col-6', 1), 32, 'C', 0);
        $s2 = $this->section($area, 11, 1);
        $this->block($this->addColumn($s2, 22, 'col-12', 0), 33, 'D', 0);

        return $area;
    }

    private function section(ContentArea $area, int $id, int $position, bool $published = true): Section
    {
        $section = new Section();
        $this->setId($section, $id);
        $section->setLayout($id === 10 ? Section::LAYOUT_TWO_COLS : Section::LAYOUT_FULL);
        $section->setPosition($position);
        $section->setPreviewPosition($position);
        $section->setPublishedSettings([]);
        if ($published) {
            $this->markPublished($section);
        }
        $area->addSection($section);

        return $section;
    }

    private function addColumn(Section $section, int $id, string $preset, int $position): Column
    {
        $column = new Column();
        $this->setId($column, $id);
        $column->setPreset($preset);
        $column->setPosition($position);
        $column->setPreviewPosition($position);
        $this->markPublished($column);
        $section->addColumn($column);

        return $column;
    }

    private function block(Column $column, int $id, string $title, int $position, bool $published = true): Block
    {
        $block = new Block();
        $this->setId($block, $id);
        $block->setType('text');
        $published ? $block->setPublishedData(['title' => $title]) : $block->setDraftData(['title' => $title]);
        $block->setPosition($position);
        $block->setPreviewPosition($position);
        $column->addBlock($block);

        return $block;
    }

    private function column(ContentArea $area, int $id): Column
    {
        foreach ($area->getSections() as $section) {
            foreach ($section->getColumns() as $column) {
                if ($column->getId() === $id) {
                    return $column;
                }
            }
        }

        throw new \LogicException('No column #' . $id);
    }

    private function find(ContentArea $area, int $id): Block
    {
        foreach ($area->getSections() as $section) {
            foreach ($section->getColumns() as $column) {
                foreach ($column->getBlocks() as $block) {
                    if ($block->getId() === $id) {
                        return $block;
                    }
                }
            }
        }

        throw new \LogicException('No block #' . $id);
    }

    /**
     * Block titles per section, per column.
     *
     * @param array<string, mixed> $tree
     *
     * @return list<list<list<string>>>
     */
    private function titles(array $tree): array
    {
        return array_map(
            static fn (array $section): array => array_map(
                static fn (array $column): array => array_map(
                    static fn (array $block): string => $block['data']['title'],
                    $column['blocks'],
                ),
                $section['columns'],
            ),
            $tree['sections'],
        );
    }

    private function markPublished(Section|Column $entity): void
    {
        (new \ReflectionProperty($entity::class, 'publishedAt'))
            ->setValue($entity, new \DateTimeImmutable('2026-01-01'));
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }

    /** @param array<string, string> $query */
    private function stack(array $query): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(new Request($query));

        return $stack;
    }

    /** @param list<BlockJsonNormalizerInterface> $normalizers */
    private function normalizer(
        AccessCheckerInterface $access = new AllowAllAccessChecker(),
        array $normalizers = [],
        RequestStack $stack = new RequestStack(),
    ): ContentAreaNormalizer {
        $styles = new SectionStyleRegistry([new class () implements SectionStyleProviderInterface {
            public function getStyles(): array
            {
                return [new SectionStyle('boxed', 'Boxed', 'x', ['padding' => 40, 'gap' => 'md'])];
            }
        }]);
        $defaults = new SectionSettingsDefaults([new class () implements SectionSettingsDefaultsProviderInterface {
            public function getDefaults(): array
            {
                return ['gap' => 'md'];
            }
        }]);
        $renderer = new BlockRenderer(
            new Environment(new ArrayLoader()),
            $stack,
            $access,
            new BlockTypeRegistry(),
            new SectionDecoratorCollection([]),
            $defaults,
            $styles,
            new class () implements TranslatorInterface {
                use TranslatorTrait;
            },
            new BlockDecoratorCollection([]),
            new BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
        );

        return new ContentAreaNormalizer(
            $renderer,
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
            new BlockJsonNormalizerCollection($normalizers),
            styleRegistry: $styles,
            settingsDefaults: $defaults,
            requestStack: $stack,
        );
    }
}
