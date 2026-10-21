<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\SchemaController;
use ContentBlocks\Entity\SectionTemplate;
use ContentBlocks\Schema\ContentSchema;
use ContentBlocks\Schema\SectionTemplateExamples;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\Tests\Fixtures\MinimalSchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SchemaControllerTest extends ControllerTestCase
{
    public function testItAnswersTheAreaSchema(): void
    {
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]));

        $response = $controller->schema(1, new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $document = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(ContentSchema::FORMAT, $document['format']);
    }

    public function testUnknownAreaIs404(): void
    {
        $response = $this->controller($this->makeEm([]))->schema(99, new Request());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReadingTheSchemaRequiresEditAccess(): void
    {
        // It names the palette and the saved sections: editors only.
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]), allow: false);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->schema(1, new Request());
    }

    public function testTheExampleCountIsCapped(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        // Asked for 50, read at most 10, times the scan factor of 4.
        $repository->expects($this->once())->method('findBy')
            ->with([], $this->anything(), 40)->willReturn([]);
        $em = $this->makeEm([$this->makeArea(1)]);
        $em->method('getRepository')->with(SectionTemplate::class)->willReturn($repository);

        $builder = MinimalSchemaBuilder::make(examples: new SectionTemplateExamples($em, $this->makeRegistry()));
        $controller = new SchemaController($em, $this->makeAccessChecker(), $builder);

        $controller->schema(1, new Request(['examples' => '50']));
    }

    private function controller(EntityManagerInterface $em, bool $allow = true): SchemaController
    {
        return new SchemaController(
            $em,
            $allow ? $this->makeAccessChecker() : new DenyAllAccessChecker(),
            MinimalSchemaBuilder::make(),
        );
    }
}
