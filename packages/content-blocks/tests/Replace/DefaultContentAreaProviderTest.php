<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Replace;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Replace\DefaultContentAreaProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class DefaultContentAreaProviderTest extends TestCase
{
    public function testLabelIncludesIdAndUpdatedAtWhenAvailable(): void
    {
        $area = $this->makeArea(42, new \DateTimeImmutable('2026-05-18 14:32:00'));

        $label = (new DefaultContentAreaProvider($this->makeEm()))->getLabel($area);

        $this->assertSame('#42 — 2026-05-18 14:32', $label);
    }

    public function testLabelFallsBackToEmDashWhenUpdatedAtMissing(): void
    {
        $area = $this->makeArea(7, null);

        $label = (new DefaultContentAreaProvider($this->makeEm()))->getLabel($area);

        $this->assertSame('#7 — —', $label);
    }

    public function testQueryBuilderHasNoFilterWhenFilterIsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());
        $qb = new QueryBuilder($em);
        $em->method('createQueryBuilder')->willReturn($qb);

        $result = (new DefaultContentAreaProvider($em))->createQueryBuilder(null);

        $this->assertSame($qb, $result);
        $this->assertNull($result->getDQLPart('where'));
        $this->assertSame([ContentArea::class => 'a'], $this->rootAliasMap($result));
    }

    public function testQueryBuilderIgnoresNonNumericFilter(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());
        $em->method('createQueryBuilder')->willReturn(new QueryBuilder($em));

        $result = (new DefaultContentAreaProvider($em))->createQueryBuilder('hello');

        // No WHERE part added — host implementations are responsible for
        // text search.
        $this->assertNull($result->getDQLPart('where'));
        $this->assertSame([], $result->getParameters()->toArray());
    }

    public function testQueryBuilderFiltersByIdForPureNumericInput(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());
        $em->method('createQueryBuilder')->willReturn(new QueryBuilder($em));

        $result = (new DefaultContentAreaProvider($em))->createQueryBuilder('42');

        $this->assertNotNull($result->getDQLPart('where'));
        $idParam = $result->getParameter('id');
        $this->assertNotNull($idParam);
        $this->assertSame(42, $idParam->getValue());
    }

    public function testQueryBuilderTrimsWhitespaceBeforeNumericCheck(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getExpressionBuilder')->willReturn(new Expr());
        $em->method('createQueryBuilder')->willReturn(new QueryBuilder($em));

        $result = (new DefaultContentAreaProvider($em))->createQueryBuilder('  42  ');

        $idParam = $result->getParameter('id');
        $this->assertNotNull($idParam);
        $this->assertSame(42, $idParam->getValue());
    }

    private function makeArea(int $id, ?\DateTimeImmutable $updatedAt): ContentArea
    {
        $area = new ContentArea();
        $idRef = new \ReflectionProperty(ContentArea::class, 'id');
        $idRef->setValue($area, $id);
        if ($updatedAt !== null) {
            $area->setUpdatedAt($updatedAt);
        }

        return $area;
    }

    private function makeEm(): EntityManagerInterface
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    /**
     * @return array<string, string>
     */
    private function rootAliasMap(QueryBuilder $qb): array
    {
        $entities = $qb->getRootEntities();
        $aliases = $qb->getRootAliases();
        $map = [];
        foreach ($entities as $i => $entity) {
            $map[$entity] = $aliases[$i] ?? '?';
        }

        return $map;
    }
}
