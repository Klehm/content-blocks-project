<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Controller\BlockRenderController;
use ContentBlocks\Entity\Block;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The hot-swap endpoint, and in particular the `?locale=` it carries.
 *
 * The core does nothing with a locale on its own — the value is only meaningful
 * once a satellite package resolves it — so the thing worth pinning is exactly
 * that it is *carried through unchanged*, and that its absence still produces
 * the historical "let the request decide" null.
 */
final class BlockRenderControllerTest extends ControllerTestCase
{
    public function testTheLocaleQueryParameterReachesTheRenderContext(): void
    {
        $captured = null;
        $controller = $this->makeController($captured);

        $response = $controller->render(1, Request::create('/_content-blocks/block/1/render?locale=de'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(RenderContext::class, $captured);
        $this->assertSame('de', $captured->locale);

        // Still a preview render: the workbench swaps draft content, same as
        // the builder does. Only the language is new.
        $this->assertSame(RenderMode::PREVIEW, $captured->mode);

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['hotReload']);
        $this->assertSame('<p>rendered</p>', $payload['html']);
    }

    /**
     * No parameter, and an empty one, both mean "decide for me". The empty case
     * is not hypothetical: a JS caller building the URL from a variable that
     * happens to be blank would otherwise pin the render to a locale named `''`.
     */
    public function testAnAbsentOrEmptyLocaleLeavesTheContextUndecided(): void
    {
        foreach (['/_content-blocks/block/1/render', '/_content-blocks/block/1/render?locale='] as $uri) {
            $captured = null;
            $controller = $this->makeController($captured);

            $controller->render(1, Request::create($uri));

            $this->assertInstanceOf(RenderContext::class, $captured);
            $this->assertNull($captured->locale, $uri . ' must not pin a locale');
        }
    }

    public function testAnUnknownBlockIsNotFoundAndRendersNothing(): void
    {
        $captured = null;
        $controller = $this->makeController($captured);

        $response = $controller->render(404, Request::create('/_content-blocks/block/404/render?locale=de'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($captured, 'a missing block must never reach the renderer');
    }

    /**
     * The locale is attacker-supplied, so it must not become a way to read a
     * block whose area the user cannot edit. The access check runs first.
     */
    public function testAccessIsCheckedBeforeAnythingIsRendered(): void
    {
        $captured = null;
        $controller = $this->makeController($captured, new DenyAllAccessChecker());

        $this->expectException(ContentBlocksAccessDeniedException::class);

        try {
            $controller->render(1, Request::create('/_content-blocks/block/1/render?locale=de'));
        } finally {
            $this->assertNull($captured, 'a denied request must never reach the renderer');
        }
    }

    /**
     * A type that needs its JavaScript re-run answers `hotReload: false`, and
     * that short-circuit sits *above* the locale handling — a language cannot
     * turn a non-swappable block into a swappable one.
     */
    public function testATypeThatOptsOutOfHotReloadStillOptsOutWithALocale(): void
    {
        $captured = null;
        $controller = $this->makeController($captured, type: FakeBlockType::TYPE);

        $response = $controller->render(1, Request::create('/_content-blocks/block/1/render?locale=de'));

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['hotReload']);
        $this->assertNull($captured);
    }

    private function makeController(
        ?RenderContext &$captured,
        ?object $accessChecker = null,
        string $type = HotSwappableBlockType::TYPE,
    ): BlockRenderController {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $column = $this->makeColumn($section, 100);
        $block = $this->makeBlock($column, 1, type: $type);

        $registry = new BlockTypeRegistry();
        $registry->register(new HotSwappableBlockType());
        $registry->register(new FakeBlockType());

        $renderer = $this->createMock(BlockRendererInterface::class);
        $renderer->method('renderBlock')->willReturnCallback(
            function (Block $b, ?RenderContext $context = null) use (&$captured): string {
                $captured = $context;

                return '<p>rendered</p>';
            },
        );

        return new BlockRenderController(
            $this->makeEm([$area, $section, $column, $block]),
            $accessChecker ?? $this->makeAccessChecker(),
            $renderer,
            $registry,
        );
    }
}

/** Opts *in* to hot reload, so the renderer is actually reached. */
final class HotSwappableBlockType extends AbstractBlockType
{
    public const TYPE = 'hot_swappable';

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getLabel(): string
    {
        return 'Hot swappable';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }

    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
