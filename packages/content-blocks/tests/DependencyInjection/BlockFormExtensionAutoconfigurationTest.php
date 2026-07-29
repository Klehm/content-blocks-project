<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Tests\Fixtures\Form\Extension\GlobalFixtureExtension;
use ContentBlocks\Tests\Fixtures\Form\Extension\MultiTargetFixtureExtension;
use ContentBlocks\Tests\Fixtures\Form\Extension\RecordingExtension;
use ContentBlocks\Tests\Fixtures\Form\Extension\TargetedFixtureExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * End-to-end wiring test for the per-block form extension seam: an
 * `#[AsBlockFormExtension]` class registered with nothing but
 * `autoconfigure: true` must come out of a compiled container as a working,
 * correctly-targeted, priority-ordered entry of the collection.
 *
 * The unit tests around it cover each link in isolation (attribute
 * normalization, compiler pass pairing, collection dispatch); this one proves
 * the links are actually joined — the attribute autoconfiguration registered in
 * {@see ContentBlocksBundle::build()} is only exercised here.
 */
final class BlockFormExtensionAutoconfigurationTest extends TestCase
{
    public function testAttributeAloneWiresATargetedExtension(): void
    {
        $collection = $this->compileWith(TargetedFixtureExtension::class);

        $collection->applyTo($this->builder(), [], 'button');
        $collection->applyTo($this->builder(), [], 'text');

        $this->assertSame(['button'], $this->extension(TargetedFixtureExtension::class)->seen);
    }

    public function testAttributeWithoutArgumentsWiresAGlobalExtension(): void
    {
        $collection = $this->compileWith(GlobalFixtureExtension::class);

        $collection->applyTo($this->builder(), [], 'button');
        $collection->applyTo($this->builder(), [], 'text');

        $this->assertSame(['button', 'text'], $this->extension(GlobalFixtureExtension::class)->seen);
    }

    public function testAttributeWithSeveralTypesWiresEachOfThem(): void
    {
        $collection = $this->compileWith(MultiTargetFixtureExtension::class);

        $collection->applyTo($this->builder(), [], 'button');
        $collection->applyTo($this->builder(), [], 'card');
        $collection->applyTo($this->builder(), [], 'text');

        $this->assertSame(['button', 'card'], $this->extension(MultiTargetFixtureExtension::class)->seen);
    }

    public function testAttributePriorityOrdersTheCollection(): void
    {
        // Registered lowest-priority first on purpose: the compiler pass, not
        // the registration order, must decide who runs first.
        $collection = $this->compileWith(
            TargetedFixtureExtension::class,      // priority 10, 'button'
            MultiTargetFixtureExtension::class,   // priority 0,  ['button', 'card']
            GlobalFixtureExtension::class,        // priority 100, '*'
        );

        $order = [];
        foreach ([
            GlobalFixtureExtension::class,
            TargetedFixtureExtension::class,
            MultiTargetFixtureExtension::class,
        ] as $class) {
            $this->extension($class)->onBuild = static function () use (&$order, $class): void {
                $order[] = $class;
            };
        }

        $collection->applyTo($this->builder(), [], 'button');

        $this->assertSame([
            GlobalFixtureExtension::class,      // 100
            TargetedFixtureExtension::class,    // 10
            MultiTargetFixtureExtension::class, // 0
        ], $order);
    }

    public function testCollectionIsEmptyWhenNothingIsTagged(): void
    {
        $collection = $this->compileWith();

        // No extension registered: dispatching must be a silent no-op.
        $collection->applyTo($this->builder(), [], 'button');
        $this->addToAssertionCount(1);
    }

    private ?ContainerBuilder $container = null;

    /**
     * Compile a minimal container carrying the bundle's own autoconfiguration
     * (that is all `build()` contributes here — no extension config, no
     * services.php) plus the given extension classes registered exactly as a
     * host's `App\` block would be: autoconfigured, nothing else.
     *
     * @param class-string<RecordingExtension> ...$extensionClasses
     */
    private function compileWith(string ...$extensionClasses): BlockFormExtensionCollection
    {
        $container = new ContainerBuilder();
        (new ContentBlocksBundle())->build($container);

        $container->setDefinition(
            BlockFormExtensionCollection::class,
            (new Definition(BlockFormExtensionCollection::class))->setPublic(true),
        );

        foreach ($extensionClasses as $class) {
            $container->register($class, $class)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        $container->compile();
        $this->container = $container;

        $collection = $container->get(BlockFormExtensionCollection::class);
        \assert($collection instanceof BlockFormExtensionCollection);

        return $collection;
    }

    /** @param class-string<RecordingExtension> $class */
    private function extension(string $class): RecordingExtension
    {
        $extension = $this->container?->get($class);
        \assert($extension instanceof RecordingExtension);

        return $extension;
    }

    private function builder(): FormBuilderInterface
    {
        return $this->createStub(FormBuilderInterface::class);
    }
}
