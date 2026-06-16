<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Form\Type\ContentAreaType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ContentAreaTypeTest extends TestCase
{
    public function testTopbarActionsDefaultsToEmptyArray(): void
    {
        $options = $this->resolveOptions();

        $this->assertSame([], $options['topbar_actions']);
    }

    public function testTopbarActionsRejectsNonArray(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->resolveOptions(['topbar_actions' => 'nope']);
    }

    public function testBuildViewExposesTopbarActions(): void
    {
        $actions = [
            ['key' => 'save-as-model', 'label' => 'Save as model', 'icon' => '💾'],
        ];

        $em = $this->createMock(EntityManagerInterface::class);
        $type = new ContentAreaType($em);

        $form = $this->createMock(FormInterface::class);
        $form->method('getData')->willReturn(null);

        $view = new FormView();
        $type->buildView($view, $form, $this->resolveOptions(['topbar_actions' => $actions]));

        $this->assertSame($actions, $view->vars['topbar_actions']);
    }

    /** Resolve the type's options the way Symfony's form factory would. */
    private function resolveOptions(array $options = []): array
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $resolver = new OptionsResolver();
        (new ContentAreaType($em))->configureOptions($resolver);

        return $resolver->resolve($options);
    }

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
