<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Builder;

use ContentBlocks\Builder\BuilderShellExtensionInterface;
use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Builder\BuilderShellFragmentCollection;
use ContentBlocks\Entity\ContentArea;
use PHPUnit\Framework\TestCase;

final class BuilderShellFragmentCollectionTest extends TestCase
{
    private function extension(BuilderShellFragment ...$fragments): BuilderShellExtensionInterface
    {
        return new class ($fragments) implements BuilderShellExtensionInterface {
            /** @param list<BuilderShellFragment> $fragments */
            public function __construct(private readonly array $fragments)
            {
            }

            public function getFragments(ContentArea $area): iterable
            {
                return $this->fragments;
            }
        };
    }

    /**
     * @param list<BuilderShellFragment> $fragments
     *
     * @return list<string>
     */
    private function templates(array $fragments): array
    {
        return array_map(static fn (BuilderShellFragment $f) => $f->template, $fragments);
    }

    public function testExtensionsRenderInServiceOrderByDefault(): void
    {
        $collection = new BuilderShellFragmentCollection([
            $this->extension(new BuilderShellFragment('a.html.twig')),
            $this->extension(new BuilderShellFragment('b.html.twig')),
        ]);

        $this->assertSame(['a.html.twig', 'b.html.twig'], $this->templates($collection->forArea(new ContentArea())));
    }

    /**
     * Registration order is an accident of service definitions; a bundle whose
     * overlay must stack above another's has to be able to say so.
     */
    public function testPriorityWinsOverRegistrationOrder(): void
    {
        $collection = new BuilderShellFragmentCollection([
            $this->extension(new BuilderShellFragment('low.html.twig', priority: -10)),
            $this->extension(new BuilderShellFragment('high.html.twig', priority: 100)),
        ]);

        $this->assertSame(['high.html.twig', 'low.html.twig'], $this->templates($collection->forArea(new ContentArea())));
    }

    /** Equal priorities must not reshuffle between runs. */
    public function testEqualPrioritiesKeepTheirIncomingOrder(): void
    {
        $collection = new BuilderShellFragmentCollection([
            $this->extension(
                new BuilderShellFragment('first.html.twig'),
                new BuilderShellFragment('second.html.twig'),
                new BuilderShellFragment('third.html.twig'),
            ),
        ]);

        $this->assertSame(
            ['first.html.twig', 'second.html.twig', 'third.html.twig'],
            $this->templates($collection->forArea(new ContentArea())),
        );
    }

    /**
     * Fragments carry no key, so two identical ones are two renders, not a
     * conflict.
     */
    public function testTheSameTemplateFromTwoExtensionsIsNotDeduplicated(): void
    {
        $collection = new BuilderShellFragmentCollection([
            $this->extension(new BuilderShellFragment('same.html.twig', ['from' => 1])),
            $this->extension(new BuilderShellFragment('same.html.twig', ['from' => 2])),
        ]);

        $fragments = $collection->forArea(new ContentArea());

        $this->assertCount(2, $fragments);
        $this->assertSame([1, 2], array_column(array_map(static fn ($f) => $f->context, $fragments), 'from'));
    }

    public function testAnExtensionCanHideItselfForAGivenArea(): void
    {
        $collection = new BuilderShellFragmentCollection([
            new class () implements BuilderShellExtensionInterface {
                public function getFragments(ContentArea $area): iterable
                {
                    return [];
                }
            },
        ]);

        $this->assertSame([], $collection->forArea(new ContentArea()));
    }

    /** A generator is the natural way to write a conditional extension. */
    public function testAGeneratorIsConsumedLikeAnArray(): void
    {
        $collection = new BuilderShellFragmentCollection([
            new class () implements BuilderShellExtensionInterface {
                public function getFragments(ContentArea $area): iterable
                {
                    yield new BuilderShellFragment('one.html.twig');
                    yield new BuilderShellFragment('two.html.twig');
                }
            },
        ]);

        $this->assertSame(['one.html.twig', 'two.html.twig'], $this->templates($collection->forArea(new ContentArea())));
    }

    public function testTheAreaReachesTheExtension(): void
    {
        $seen = null;
        $collection = new BuilderShellFragmentCollection([
            new class ($seen) implements BuilderShellExtensionInterface {
                public function __construct(private mixed &$seen)
                {
                }

                public function getFragments(ContentArea $area): iterable
                {
                    $this->seen = $area;

                    return [];
                }
            },
        ]);
        $area = new ContentArea();

        $collection->forArea($area);

        $this->assertSame($area, $seen);
    }
}
