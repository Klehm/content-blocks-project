<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionCollection;
use ContentBlocks\Builder\BuilderActionProviderInterface;
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

    /**
     * The template iterates one homogeneous list, so the array shape accepted
     * by the option is normalized here rather than in Twig.
     */
    public function testBuildViewNormalizesTopbarActionsToValueObjects(): void
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

        $exposed = $view->vars['topbar_actions'];
        $this->assertCount(1, $exposed);
        $this->assertInstanceOf(BuilderAction::class, $exposed[0]);
        $this->assertSame('save-as-model', $exposed[0]->key);
        $this->assertSame('Save as model', $exposed[0]->label);
        $this->assertSame('💾', $exposed[0]->icon);
        $this->assertNull($exposed[0]->title);
    }

    /**
     * Providers get a say only once there is an area to describe — on the
     * "save first" placeholder there is no builder to hang a menu off.
     */
    public function testProvidersContributeOnlyForAPersistedArea(): void
    {
        $provider = new class implements BuilderActionProviderInterface {
            public function getActions(ContentArea $area): iterable
            {
                yield new BuilderAction('from-bundle', 'From a bundle');
            }
        };
        $collection = new BuilderActionCollection([$provider]);

        $em = $this->createMock(EntityManagerInterface::class);
        $type = new ContentAreaType($em, $collection);
        $options = $this->resolveOptions([
            'topbar_actions' => [['key' => 'from-form', 'label' => 'From the form']],
        ]);

        $pendingForm = $this->createMock(FormInterface::class);
        $pendingForm->method('getData')->willReturn(null);
        $pendingView = new FormView();
        $type->buildView($pendingView, $pendingForm, $options);
        $this->assertSame(
            ['from-form'],
            array_map(static fn (BuilderAction $a) => $a->key, $pendingView->vars['topbar_actions']),
        );

        $savedForm = $this->createMock(FormInterface::class);
        $savedForm->method('getData')->willReturn($this->makePersistedArea(3));
        $savedView = new FormView();
        $type->buildView($savedView, $savedForm, $options);
        $this->assertSame(
            ['from-bundle', 'from-form'],
            array_map(static fn (BuilderAction $a) => $a->key, $savedView->vars['topbar_actions']),
        );
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
