<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Form\Type\ContentAreaType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormView;

final class ContentAreaTypeTest extends TestCase
{
    public function testReverseTransformPersistsButDoesNotFlushOnSubmit(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->never())->method('flush');

        $type = new ContentAreaType($em);
        $area = $type->reverseTransform(null);

        $this->assertInstanceOf(ContentArea::class, $area);
    }

    public function testReverseTransformLooksUpExistingArea(): void
    {
        $existing = new ContentArea();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $em->expects($this->once())
            ->method('find')
            ->with(ContentArea::class, 42)
            ->willReturn($existing);

        $type = new ContentAreaType($em);

        $this->assertSame($existing, $type->reverseTransform('42'));
    }

    public function testTransformReturnsIdOrNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $type = new ContentAreaType($em);

        $this->assertNull($type->transform(null));

        $area = new ContentArea();
        $this->assertNull($type->transform($area)); // not persisted yet

        $persisted = $this->makePersistedArea(7);
        $this->assertSame(7, $type->transform($persisted));
    }

    private function makePersistedArea(int $id): ContentArea
    {
        $area = new ContentArea();
        $reflection = new \ReflectionProperty($area, 'id');
        $reflection->setValue($area, $id);

        return $area;
    }
}
