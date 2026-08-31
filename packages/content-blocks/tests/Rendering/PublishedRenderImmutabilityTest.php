<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Rendering;

use Doctrine\Common\Collections\ArrayCollection;
use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDecoratorCollection;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Controller\BlocksController;
use ContentBlocks\Controller\ReplaceController;
use ContentBlocks\Controller\SectionsController;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Publishing\ContentAreaPublisher;
use ContentBlocks\Rendering\BlockDataResolverCollection;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Rendering\CoreBlockDataResolver;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Section\SectionCloner;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Security\AllowAllAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The published render is immutable until Publish is pressed.
 *
 * This is the one guarantee an editor bets their live site on: they open the
 * builder on a published page, move things around, delete a block, insert a
 * section — and the public page must keep serving exactly what it served
 * before they opened the builder, byte for byte, until they press Publish.
 *
 * Every test here follows the same three beats:
 *   1. snapshot the PUBLIC html of a fully-published area,
 *   2. run one real builder action through its real controller,
 *   3. assert the PUBLIC html is unchanged.
 *
 * Then {@see testPublishCommitsEveryPendingChangeAtOnce} closes the loop: once
 * Publish runs, the public render must finally match the draft. Immutability
 * that never lets a change through would be just as broken.
 *
 * The actions are driven through the controllers rather than the entities so
 * that a leak introduced in a controller (writing to a published field instead
 * of its draft twin) fails here, not only in a hand-written entity scenario.
 */
final class PublishedRenderImmutabilityTest extends TestCase
{
    /** @var list<object> Everything em->find() can resolve. */
    private array $managed = [];

    /** Stands in for the database's auto-increment. */
    private int $nextId = 1000;

    // ---------------------------------------------------------------
    // Section-scoped actions
    // ---------------------------------------------------------------

    public function testAddingASectionDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->sectionsController()->create(1, $this->jsonRequest(['layout' => Section::LAYOUT_TWO_COLS]));

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testDeletingASectionDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->sectionsController()->delete(10, $this->jsonRequest());

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testReorderingSectionsDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->sectionsController()->move(11, $this->jsonRequest(['direction' => 'up']));

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testDuplicatingASectionDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->sectionsController()->duplicate(10, $this->jsonRequest());

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testChangingSectionSettingsDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        // What the section settings sidebar writes on save.
        $this->find(Section::class, 10)->setDraftSettings([
            'styling' => ['backgroundColor' => '#ff0000'],
        ]);

        $this->assertSame($before, $this->publicHtml($area));
    }

    // ---------------------------------------------------------------
    // Block-scoped actions
    // ---------------------------------------------------------------

    public function testAddingABlockDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->blocksController()->create(20, $this->jsonRequest(['type' => 'text']));

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testDeletingABlockDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->blocksController()->delete(30, $this->jsonRequest());

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testReorderingBlocksInsideAColumnDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        // Second block of column 20 dragged to the top.
        $this->blocksController()->move(31, $this->jsonRequest(['toColumnId' => 20, 'position' => 0]));

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testMovingABlockToAnotherColumnDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        // The drag that crosses a column boundary — the one an editor makes
        // when they decide a paragraph belongs on the right instead.
        $this->blocksController()->move(30, $this->jsonRequest(['toColumnId' => 21, 'position' => 0]));

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testMovingABlockToAnotherSectionDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->blocksController()->move(30, $this->jsonRequest(['toColumnId' => 22, 'position' => 0]));

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testDuplicatingABlockDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->blocksController()->duplicate(30, $this->jsonRequest());

        $this->assertSame($before, $this->publicHtml($area));
    }

    public function testEditingABlockDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        // What BlockComponent::persistDraft() writes on every keystroke.
        $this->find(Block::class, 30)->setDraftData(['title' => 'Edited in the sidebar']);

        $this->assertSame($before, $this->publicHtml($area));
    }

    // ---------------------------------------------------------------
    // Area-scoped actions
    // ---------------------------------------------------------------

    public function testReplacingTheAreaContentDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $source = $this->otherPublishedArea();
        $before = $this->publicHtml($area);

        $this->replaceController()->replaceWith(1, $source->getId(), $this->jsonRequest());

        $this->assertSame($before, $this->publicHtml($area));
    }

    /**
     * The compound case the RC3 report described: several unrelated edits in
     * one builder session. Each one is individually covered above; this pins
     * that they do not combine into a leak either.
     */
    public function testAWholeEditingSessionDoesNotTouchThePublicRender(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->sectionsController()->create(1, $this->jsonRequest(['layout' => Section::LAYOUT_TWO_COLS]));
        $this->sectionsController()->move(11, $this->jsonRequest(['direction' => 'up']));
        $this->sectionsController()->delete(11, $this->jsonRequest());
        $this->blocksController()->create(20, $this->jsonRequest(['type' => 'text']));
        $this->blocksController()->move(30, $this->jsonRequest(['toColumnId' => 21, 'position' => 0]));
        $this->blocksController()->duplicate(31, $this->jsonRequest());
        $this->blocksController()->delete(31, $this->jsonRequest());
        $this->find(Block::class, 32)->setDraftData(['title' => 'Edited']);
        $this->find(Section::class, 10)->setDraftSettings(['styling' => ['backgroundColor' => '#ff0000']]);

        $this->assertSame($before, $this->publicHtml($area));
    }

    // ---------------------------------------------------------------
    // …and the other half of the contract
    // ---------------------------------------------------------------

    /**
     * Immutability is only worth anything if Publish still lands. After it,
     * the public render must carry every pending change — which is the same
     * as saying it must equal what the editor was looking at.
     */
    public function testPublishCommitsEveryPendingChangeAtOnce(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->blocksController()->move(30, $this->jsonRequest(['toColumnId' => 21, 'position' => 0]));
        $this->blocksController()->delete(31, $this->jsonRequest());
        $this->find(Block::class, 32)->setDraftData(['title' => 'Edited']);

        $this->assertSame($before, $this->publicHtml($area), 'guard: nothing leaked before Publish');

        $draft = $this->draftBody($area);
        (new ContentAreaPublisher($this->em()))->publish($area);

        $this->assertNotSame($before, $this->publicHtml($area), 'Publish must actually change the public page');
        $this->assertSame($draft, $this->publicBody($area), 'the published page is what the editor saw');
    }

    /**
     * Discard is the other exit from a builder session: it must land the
     * public render exactly where it already was.
     */
    public function testDiscardLeavesThePublicRenderWhereItAlreadyWas(): void
    {
        $area = $this->publishedArea();
        $before = $this->publicHtml($area);

        $this->sectionsController()->create(1, $this->jsonRequest(['layout' => Section::LAYOUT_FULL]));
        $this->blocksController()->move(30, $this->jsonRequest(['toColumnId' => 21, 'position' => 0]));
        $this->blocksController()->delete(31, $this->jsonRequest());
        $this->find(Block::class, 32)->setDraftData(['title' => 'Edited']);

        (new ContentAreaPublisher($this->em()))->discardDraft($area);

        $this->assertSame($before, $this->publicHtml($area));
        $this->assertSame($this->publicBody($area), $this->draftBody($area), 'the draft is back to the published state');
    }

    // ---------------------------------------------------------------
    // Fixture: an area whose every entity is published and clean
    // ---------------------------------------------------------------

    /**
     * Two published sections; the first split in two columns so a
     * cross-column drag has somewhere to go.
     *
     *   #10 two_cols  ├─ col #20 ─ block #30 "A", block #31 "B"
     *                 └─ col #21 ─ block #32 "C"
     *   #11 full      └─ col #22 ─ block #33 "D"
     */
    private function publishedArea(): ContentArea
    {
        $area = $this->makeArea(1);

        $s1 = $this->makeSection($area, 10, Section::LAYOUT_TWO_COLS, 0);
        $c1 = $this->makeColumn($s1, 20, 'col-6', 0);
        $this->makeBlock($c1, 30, 'A', 0);
        $this->makeBlock($c1, 31, 'B', 1);
        $c2 = $this->makeColumn($s1, 21, 'col-6', 1);
        $this->makeBlock($c2, 32, 'C', 0);

        $s2 = $this->makeSection($area, 11, Section::LAYOUT_FULL, 1);
        $c3 = $this->makeColumn($s2, 22, 'col-12', 0);
        $this->makeBlock($c3, 33, 'D', 0);

        return $area;
    }

    /** A second published area, used as the source of a replace. */
    private function otherPublishedArea(): ContentArea
    {
        $area = $this->makeArea(2);
        $s = $this->makeSection($area, 50, Section::LAYOUT_FULL, 0);
        $c = $this->makeColumn($s, 51, 'col-12', 0);
        $this->makeBlock($c, 52, 'Z', 0);

        return $area;
    }

    private function makeArea(int $id): ContentArea
    {
        $area = new ContentArea();
        $this->setId($area, $id);

        return $this->manage($area);
    }

    private function makeSection(ContentArea $area, int $id, string $layout, int $position): Section
    {
        $section = new Section();
        $this->setId($section, $id);
        $section->setLayout($layout);
        $section->setPosition($position);
        $section->setPreviewPosition($position);
        $section->setPublishedSettings([]);
        $this->markPublished($section);
        $area->addSection($section);

        return $this->manage($section);
    }

    private function makeColumn(Section $section, int $id, string $preset, int $position): Column
    {
        $column = new Column();
        $this->setId($column, $id);
        $column->setPreset($preset);
        $column->setPosition($position);
        $column->setPreviewPosition($position);
        $this->markPublished($column);
        $section->addColumn($column);

        return $this->manage($column);
    }

    private function makeBlock(Column $column, int $id, string $title, int $position): Block
    {
        $block = new Block();
        $this->setId($block, $id);
        $block->setType('text');
        $block->setPublishedData(['title' => $title]);
        $block->setPosition($position);
        $block->setPreviewPosition($position);
        $column->addBlock($block);

        return $this->manage($block);
    }

    /** Stamps publishedAt the way Section/Column::publish() would. */
    private function markPublished(Section|Column $entity): void
    {
        $ref = new \ReflectionProperty($entity::class, 'publishedAt');
        $ref->setValue($entity, new \DateTimeImmutable('2026-01-01 00:00:00'));
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }

    /** @template T of object @param T $entity @return T */
    private function manage(object $entity): object
    {
        $this->managed[] = $entity;

        return $entity;
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function find(string $class, int $id): object
    {
        foreach ($this->managed as $entity) {
            if ($entity instanceof $class && $entity->getId() === $id) {
                return $entity;
            }
        }

        throw new \LogicException(sprintf('No managed %s #%d.', $class, $id));
    }

    // ---------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------

    private function publicHtml(ContentArea $area): string
    {
        $this->reload();

        return $this->renderer(RenderMode::PUBLIC)->render($area, new RenderContext(RenderMode::PUBLIC));
    }

    /**
     * Rebuilds every collection from the parent FK of the entities that hold
     * it, ordered by `position` — i.e. exactly what the next request gets
     * when Doctrine hydrates the area from the database.
     *
     * Without this the harness would lie about one action in particular:
     * moving a block to another column writes the owning side (Block::column)
     * only, so the in-memory inverse collections still show the block where
     * it used to be, while the database — and therefore the public page —
     * already shows it somewhere else.
     */
    private function reload(): void
    {
        foreach ($this->managed as $entity) {
            match (true) {
                $entity instanceof ContentArea => $this->rehydrate($entity, 'sections', Section::class, fn (Section $s) => $s->getContentArea() === $entity),
                $entity instanceof Section => $this->rehydrate($entity, 'columns', Column::class, fn (Column $c) => $c->getSection() === $entity),
                $entity instanceof Column => $this->rehydrate($entity, 'blocks', Block::class, fn (Block $b) => $b->getColumn() === $entity),
                default => null,
            };
        }
    }

    /** @param class-string $childClass */
    private function rehydrate(object $parent, string $property, string $childClass, callable $belongsToParent): void
    {
        $children = array_values(array_filter(
            $this->managed,
            fn (object $m) => $m instanceof $childClass && $belongsToParent($m),
        ));
        // The mappings all carry #[ORM\OrderBy(['position' => 'ASC'])]; id
        // breaks ties the way an auto-increment primary key does.
        usort($children, fn ($a, $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        (new \ReflectionProperty($parent::class, $property))->setValue($parent, new ArrayCollection($children));
    }

    /**
     * The block titles the public page shows, in reading order — the render
     * reduced to the thing an editor would compare across a Publish. Used
     * where full-html equality would be defeated by preview-only markup.
     *
     * @return list<string>
     */
    private function publicBody(ContentArea $area): array
    {
        return $this->titles($this->publicHtml($area));
    }

    /** Same, for the draft the editor is looking at. */
    private function draftBody(ContentArea $area): array
    {
        $this->reload();
        $html = $this->renderer(RenderMode::PREVIEW)->render($area, new RenderContext(RenderMode::PREVIEW));

        // Soft-deleted entities are still in the preview DOM, flagged; they
        // are precisely what the editor knows is on its way out.
        $html = preg_replace('#<div[^>]*data-cb-deleted="1".*?</div>#s', '', $html) ?? $html;

        return $this->titles($html);
    }

    /** @return list<string> */
    private function titles(string $html): array
    {
        preg_match_all('#<cb-title>(.*?)</cb-title>#', $html, $m);

        return $m[1];
    }

    private function renderer(RenderMode $mode): BlockRenderer
    {
        $stack = new RequestStack();
        $stack->push(new Request($mode === RenderMode::PREVIEW ? ['cb_preview' => '1'] : []));

        return new BlockRenderer(
            $this->twig(),
            $stack,
            new AllowAllAccessChecker(),
            $this->registry(),
            new SectionDecoratorCollection([]),
            new SectionSettingsDefaults([]),
            new SectionStyleRegistry([]),
            $this->translator(),
            new BlockDecoratorCollection([]),
            new BlockDataDefaults(),
            new BlockDataResolverCollection([new CoreBlockDataResolver()]),
        );
    }

    private function twig(): Environment
    {
        static $dir = null;
        if ($dir === null) {
            $dir = sys_get_temp_dir() . '/cb-immutability-' . uniqid('', true);
            mkdir($dir, 0o777, true);
            file_put_contents($dir . '/text_view.html.twig', '<cb-title>{{ data.title|default("") }}</cb-title>');
        }

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ContentBlocks');
        $loader->addPath($dir, 'TestRender');

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension($this->translator()));
        $env->addExtension(new RoutingExtension($this->urlGenerator()));

        return $env;
    }

    private function registry(): BlockTypeRegistry
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new class extends AbstractBlockType {
            public static function getType(): string { return 'text'; }
            public static function getLabel(): string { return 'Text'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return ['title' => '']; }
            public function getViewTemplate(): ?string { return '@TestRender/text_view.html.twig'; }
        });

        return $registry;
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            use TranslatorTrait;
        };
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;
            public function __construct() { $this->context = new RequestContext(); }
            public function setContext(RequestContext $context): void { $this->context = $context; }
            public function getContext(): RequestContext { return $this->context; }
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/_route/' . $name;
            }
        };
    }

    // ---------------------------------------------------------------
    // Controllers, wired on doubles
    // ---------------------------------------------------------------

    /**
     * EM double over the in-memory graph. remove() detaches the entity from
     * its parent collection, which is what Doctrine's orphanRemoval + cascade
     * do to the object graph on flush — without it the publisher's removals
     * would be invisible to the renderer walking that same graph.
     */
    private function em(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            foreach ($this->managed as $entity) {
                if ($entity instanceof $class && $entity->getId() === $id) {
                    return $entity;
                }
            }

            return null;
        });
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if (!\in_array($entity, $this->managed, true)) {
                $this->setId($entity, ++$this->nextId);
                $this->managed[] = $entity;
            }
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            match (true) {
                $entity instanceof Section => $entity->getContentArea()?->removeSection($entity),
                $entity instanceof Column => $entity->getSection()?->removeColumn($entity),
                $entity instanceof Block => $entity->getColumn()?->removeBlock($entity),
                default => null,
            };
            $this->managed = array_values(array_filter($this->managed, fn ($m) => $m !== $entity));
        });

        return $em;
    }

    private function sectionsController(): SectionsController
    {
        return new SectionsController(
            $this->em(),
            new AllowAllAccessChecker(),
            $this->csrf(),
            new SectionCloner(),
            $this->renderer(RenderMode::PREVIEW),
            $this->registry(),
        );
    }

    private function blocksController(): BlocksController
    {
        return new BlocksController(
            $this->em(),
            new AllowAllAccessChecker(),
            $this->registry(),
            $this->csrf(),
            $this->translator(),
            $this->renderer(RenderMode::PREVIEW),
        );
    }

    private function replaceController(): ReplaceController
    {
        $provider = $this->createMock(ContentAreaProviderInterface::class);

        return new ReplaceController(
            $this->em(),
            new AllowAllAccessChecker(),
            $provider,
            new SectionCloner(),
            $this->csrf(),
        );
    }

    private function csrf(): CsrfTokenManagerInterface
    {
        $manager = $this->createMock(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturn(true);

        return $manager;
    }

    private function jsonRequest(array $payload = []): Request
    {
        return Request::create(
            '/_content-blocks/test',
            'POST',
            server: ['HTTP_X-CSRF-Token' => 'token'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }
}
