<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Builder;

use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionCollection;
use ContentBlocks\Builder\BuilderActionProviderInterface;
use ContentBlocks\Entity\ContentArea;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BuilderActionCollectionTest extends TestCase
{
    private function provider(BuilderAction ...$actions): BuilderActionProviderInterface
    {
        return new class($actions) implements BuilderActionProviderInterface {
            /** @param list<BuilderAction> $actions */
            public function __construct(private readonly array $actions)
            {
            }

            public function getActions(ContentArea $area): iterable
            {
                return $this->actions;
            }
        };
    }

    /** @return list<string> */
    private function keys(array $actions): array
    {
        return array_map(static fn (BuilderAction $a) => $a->key, $actions);
    }

    public function testProvidersComeBeforeTheFormsOwnEntries(): void
    {
        $collection = new BuilderActionCollection([
            $this->provider(new BuilderAction('a', 'A')),
            $this->provider(new BuilderAction('b', 'B')),
        ]);

        $this->assertSame(
            ['a', 'b', 'c'],
            $this->keys($collection->forArea(new ContentArea(), [['key' => 'c', 'label' => 'C']])),
        );
    }

    /**
     * Registration order is an accident of service definitions; a bundle that
     * needs to sit at the top of the menu has to be able to say so.
     */
    public function testPriorityWinsOverRegistrationOrder(): void
    {
        $collection = new BuilderActionCollection([
            $this->provider(new BuilderAction('low', 'Low', priority: -10)),
            $this->provider(new BuilderAction('high', 'High', priority: 100)),
        ]);

        $this->assertSame(['high', 'low'], $this->keys($collection->forArea(new ContentArea())));
    }

    /** Equal priorities must not reshuffle between runs. */
    public function testEqualPrioritiesKeepTheirIncomingOrder(): void
    {
        $collection = new BuilderActionCollection([
            $this->provider(
                new BuilderAction('first', 'First'),
                new BuilderAction('second', 'Second'),
                new BuilderAction('third', 'Third'),
            ),
        ]);

        $this->assertSame(['first', 'second', 'third'], $this->keys($collection->forArea(new ContentArea())));
    }

    /**
     * The key is what the host switches on in its `cb:builder:action` listener,
     * so two rows sharing one would fire the same handler from two places.
     */
    public function testADuplicateKeyCollapsesToTheFirstOccurrence(): void
    {
        $collection = new BuilderActionCollection([
            $this->provider(new BuilderAction('export', 'From the bundle')),
        ]);

        $actions = $collection->forArea(new ContentArea(), [['key' => 'export', 'label' => 'From the form']]);

        $this->assertCount(1, $actions);
        $this->assertSame('From the bundle', $actions[0]->label);
    }

    public function testAProviderCanHideItselfForAGivenArea(): void
    {
        $collection = new BuilderActionCollection([
            new class implements BuilderActionProviderInterface {
                public function getActions(ContentArea $area): iterable
                {
                    return [];
                }
            },
        ]);

        $this->assertSame([], $collection->forArea(new ContentArea()));
    }

    public function testATranslatableLabelSurvivesUntranslated(): void
    {
        // The collection must not touch it: translation happens in the
        // template, at the render boundary, the way block labels do.
        // Hand-rolled rather than a TranslatableMessage — this package depends
        // on translation-contracts, not on symfony/translation.
        $label = new class implements TranslatableInterface {
            public function trans(TranslatorInterface $translator, ?string $locale = null): string
            {
                return 'translated';
            }
        };
        $collection = new BuilderActionCollection([$this->provider(new BuilderAction('t', $label))]);

        $this->assertSame($label, $collection->forArea(new ContentArea())[0]->label);
    }

    public function testAFormEntryWithoutAKeyIsRejectedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BuilderActionCollection([]))->forArea(new ContentArea(), [['label' => 'Nameless']]);
    }

    public function testAFormEntryWithoutALabelIsRejectedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BuilderActionCollection([]))->forArea(new ContentArea(), [['key' => 'mute']]);
    }
}
