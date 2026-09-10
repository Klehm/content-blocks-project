<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Builder\AreaTreeBuilder;
use ContentBlocks\Controller\TreeController;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\Tests\Fixtures\EchoTranslator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class TreeControllerTest extends ControllerTestCase
{
    private function makeController(EntityManagerInterface $em, bool $allow = true): TreeController
    {
        return new TreeController(
            $em,
            $allow ? $this->makeAccessChecker() : new DenyAllAccessChecker(),
            new AreaTreeBuilder($this->makeRegistry(), new EchoTranslator()),
        );
    }

    public function testTreeReturnsTheAreaOutline(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $column = $this->makeColumn($section, 3);
        $this->makeBlock($column, 4);
        $controller = $this->makeController($this->makeEm([$area]));

        $payload = json_decode((string) $controller->tree(1)->getContent(), true);

        $this->assertSame(1, $payload['areaId']);
        $this->assertSame(2, $payload['sections'][0]['id']);
        $this->assertSame(3, $payload['sections'][0]['columns'][0]['id']);
        $this->assertSame(4, $payload['sections'][0]['columns'][0]['blocks'][0]['id']);
    }

    public function testUnknownAreaIs404(): void
    {
        $controller = $this->makeController($this->makeEm([]));

        $this->assertSame(Response::HTTP_NOT_FOUND, $controller->tree(99)->getStatusCode());
    }

    public function testReadingTheOutlineRequiresEditAccess(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), allow: false);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->tree(1);
    }
}
