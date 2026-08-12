<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Field;

use ContentBlocks\I18n\Field\FieldPath;
use PHPUnit\Framework\TestCase;

/**
 * The grammar every stored translation is keyed by. If any of this drifts,
 * translations silently attach to the wrong field — so the awkward cases
 * (reordering, missing ids, malformed input) are pinned individually.
 */
final class FieldPathTest extends TestCase
{
    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'title' => 'Hello',
            'items' => [
                ['_id' => 'aa11', 'label' => 'One', 'url' => '/one'],
                ['_id' => 'bb22', 'label' => 'Two', 'url' => '/two'],
                // Predates the `_id` backfill.
                ['label' => 'Three'],
            ],
            'rows' => [
                ['_id' => 'r1', 'cells' => [
                    ['_id' => 'c1', 'text' => 'A'],
                    ['_id' => 'c2', 'text' => 'B'],
                ]],
            ],
            'nested' => ['deep' => 'value'],
            'empty' => [],
        ];
    }

    public function testExpandsASimpleField(): void
    {
        $this->assertSame(['title'], FieldPath::expand('title', $this->data()));
    }

    public function testExpandsACollectionByEntryId(): void
    {
        $this->assertSame(
            ['items[aa11].label', 'items[bb22].label'],
            FieldPath::expand('items[].label', $this->data()),
        );
    }

    public function testAnEntryWithoutAnIdIsSkippedRatherThanGuessedAt(): void
    {
        // The third entry has no `_id`. Keying it by position would attach its
        // translation to whichever card later occupies index 2 — the exact bug
        // `_id` exists to prevent. Such content needs
        // `content-blocks:backfill-collection-ids`, not a guess.
        $paths = FieldPath::expand('items[].label', $this->data());

        $this->assertCount(2, $paths);
        $this->assertSame([], array_filter($paths, static fn (string $p) => str_contains($p, '[]')));
    }

    public function testExpandsNestedCollections(): void
    {
        $this->assertSame(
            ['rows[r1].cells[c1].text', 'rows[r1].cells[c2].text'],
            FieldPath::expand('rows[].cells[].text', $this->data()),
        );
    }

    public function testExpandingYieldsNothingWhenTheDataHasNoSuchKey(): void
    {
        $this->assertSame([], FieldPath::expand('missing', $this->data()));
        $this->assertSame([], FieldPath::expand('items[].nope', $this->data()));
        $this->assertSame([], FieldPath::expand('empty[].x', $this->data()));
    }

    public function testReadsThroughCollectionsById(): void
    {
        $this->assertSame('Two', FieldPath::read($this->data(), 'items[bb22].label'));
        $this->assertSame('B', FieldPath::read($this->data(), 'rows[r1].cells[c2].text'));
        $this->assertSame('value', FieldPath::read($this->data(), 'nested.deep'));
        $this->assertNull(FieldPath::read($this->data(), 'items[zzzz].label'));
    }

    public function testHasDistinguishesAbsentFromNull(): void
    {
        $data = ['a' => null, 'b' => ''];

        $this->assertTrue(FieldPath::has($data, 'a'));
        $this->assertTrue(FieldPath::has($data, 'b'));
        $this->assertFalse(FieldPath::has($data, 'c'));
    }

    public function testWritesIntoACollectionEntry(): void
    {
        $written = FieldPath::write($this->data(), 'items[bb22].label', 'ZWEI');

        $this->assertSame('ZWEI', $written['items'][1]['label']);
        $this->assertSame('One', $written['items'][0]['label']);
    }

    public function testWritesIntoANestedCollectionEntry(): void
    {
        $written = FieldPath::write($this->data(), 'rows[r1].cells[c1].text', 'AAA');

        $this->assertSame('AAA', $written['rows'][0]['cells'][0]['text']);
        $this->assertSame('B', $written['rows'][0]['cells'][1]['text']);
    }

    public function testWritingNeverCreatesMissingStructure(): void
    {
        // A stale row for a deleted card, or for a field the block type has
        // since dropped, must be a no-op — not a resurrection.
        $data = $this->data();

        $this->assertSame($data, FieldPath::write($data, 'items[gone].label', 'x'));
        $this->assertSame($data, FieldPath::write($data, 'removedField', 'x'));
        $this->assertSame($data, FieldPath::write($data, 'nested.absent', 'x'));
    }

    public function testPatternOfStripsEntryIds(): void
    {
        $this->assertSame('items[].label', FieldPath::patternOf('items[aa11].label'));
        $this->assertSame('rows[].cells[].text', FieldPath::patternOf('rows[r1].cells[c2].text'));
        $this->assertSame('title', FieldPath::patternOf('title'));
    }

    public function testMatchesAnyComparesShapes(): void
    {
        $this->assertTrue(FieldPath::matchesAny('items[aa11].label', ['items[].label']));
        $this->assertFalse(FieldPath::matchesAny('items[aa11].url', ['items[].label']));
    }

    public function testEntryIndexIsOneBasedAndPositional(): void
    {
        $this->assertSame(1, FieldPath::entryIndex($this->data(), 'items[aa11].label'));
        $this->assertSame(2, FieldPath::entryIndex($this->data(), 'items[bb22].label'));
        $this->assertNull(FieldPath::entryIndex($this->data(), 'title'));
    }

    /**
     * A path arrives from a stored row, and rows outlive the code that wrote
     * them. Anything the grammar cannot parse whole must be inert everywhere
     * rather than partially matched somewhere.
     */
    public function testMalformedPathsParseToNothing(): void
    {
        foreach (['', 'bad..path', 'x[', 'a]b', '.leading', 'trailing.'] as $malformed) {
            $this->assertSame([], FieldPath::segments($malformed), $malformed);
            $this->assertSame([], FieldPath::expand($malformed, $this->data()), $malformed);
            $this->assertNull(FieldPath::read($this->data(), $malformed), $malformed);
        }
    }
}
