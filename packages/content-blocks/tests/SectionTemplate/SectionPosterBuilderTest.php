<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\SectionTemplate;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Section\SectionStyle;
use ContentBlocks\Section\SectionStyleProviderInterface;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\SectionTemplate\SectionPosterBuilder;
use ContentBlocks\Tests\Fixtures\EchoTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

final class SectionPosterBuilderTest extends TestCase
{
    private function makeBuilder(): SectionPosterBuilder
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new PosterHeadingBlock());
        $registry->register(new PosterPictureBlock());
        $registry->register(new PosterMuteBlock());
        $registry->register(new PosterExplodingBlock());

        return new SectionPosterBuilder($registry, new EchoTranslator(), $this->makeStyleRegistry());
    }

    private function makeStyleRegistry(): SectionStyleRegistry
    {
        return new SectionStyleRegistry([new class implements SectionStyleProviderInterface {
            public function getStyles(): array
            {
                return [
                    new SectionStyle('midnight', 'Midnight', 'my--midnight', [
                        'styling' => ['backgroundColor' => '#101828'],
                    ]),
                    new SectionStyle('classes-only', 'Classes only', 'my--plain'),
                ];
            }
        }]);
    }

    /**
     * @param list<array<string, mixed>>  $columns
     * @param array<string, mixed>|null   $settings
     */
    private function payload(array $columns, string $layout = 'full', ?array $settings = null): array
    {
        return [
            'format' => 'content-blocks/section-v1',
            'layout' => $layout,
            'settings' => $settings,
            'columns' => $columns,
        ];
    }

    public function testDrawsOneTilePerBlockKeepingOrder(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'poster_heading', 'data' => ['text' => 'Our team']],
                ['type' => 'poster_picture', 'data' => ['src' => '/uploads/team.png']],
            ]],
        ]));

        $this->assertNotNull($poster);
        $this->assertSame('full', $poster['layout']);
        $this->assertCount(1, $poster['columns']);

        $tiles = $poster['columns'][0]['tiles'];
        $this->assertCount(2, $tiles);
        $this->assertSame(BlockPreviewHint::KIND_HEADING, $tiles[0]['kind']);
        $this->assertSame('Our team', $tiles[0]['text'], 'the stored copy reaches the tile');
        $this->assertSame(BlockPreviewHint::KIND_IMAGE, $tiles[1]['kind']);
        $this->assertSame('/uploads/team.png', $tiles[1]['image']);
    }

    public function testColumnWidthComesFromTheStoredPreset(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-8', 'blocks' => []],
            ['preset' => 'col-4', 'blocks' => []],
        ], 'two_cols'));

        $this->assertSame([8, 4], array_column($poster['columns'], 'width'));
        $this->assertSame('two_cols', $poster['layout']);
    }

    /**
     * A preset the poster cannot read must not collapse the column to nothing —
     * a zero-width column would silently disappear from the thumbnail.
     */
    public function testUnreadablePresetFallsBackToFullWidth(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'sidebar', 'blocks' => []],
            ['preset' => 'col-99', 'blocks' => []],
            ['blocks' => []],
        ]));

        $this->assertSame([12, 12, 12], array_column($poster['columns'], 'width'));
    }

    public function testBlockWithoutAHintFallsBackToItsLabel(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [['type' => 'poster_mute', 'data' => []]]],
        ]));

        $tile = $poster['columns'][0]['tiles'][0];
        $this->assertSame(BlockPreviewHint::KIND_GENERIC, $tile['kind']);
        $this->assertSame('Muted block', $tile['label'], 'the label is translated at this boundary');
        $this->assertFalse($tile['missing']);
    }

    public function testUnregisteredTypeStillGetsATileFlaggedAsMissing(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'poster_heading', 'data' => ['text' => 'Kept']],
                ['type' => 'gone_block', 'data' => []],
            ]],
        ]));

        $tiles = $poster['columns'][0]['tiles'];
        $this->assertFalse($tiles[0]['missing']);
        $this->assertTrue($tiles[1]['missing'], 'the hole is drawn where it sits');
        $this->assertSame('gone_block', $tiles[1]['label']);
        $this->assertSame(BlockPreviewHint::KIND_GENERIC, $tiles[1]['kind']);
    }

    /**
     * A hint reads stored data of unknown age. One block type blowing up on an
     * old row must cost that tile its detail, never the whole library listing.
     */
    public function testAThrowingHintDegradesToAGenericTile(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [['type' => 'poster_boom', 'data' => []]]],
        ]));

        $tile = $poster['columns'][0]['tiles'][0];
        $this->assertSame(BlockPreviewHint::KIND_GENERIC, $tile['kind']);
        $this->assertSame('Exploding block', $tile['label']);
    }

    public function testTilesAreCappedAndTheRemainderCounted(): void
    {
        $blocks = array_fill(0, 10, ['type' => 'poster_heading', 'data' => ['text' => 'x']]);

        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => $blocks],
        ]));

        $this->assertCount(6, $poster['columns'][0]['tiles']);
        $this->assertSame(4, $poster['columns'][0]['more']);
    }

    public function testNoRemainderWhenEverythingFits(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [['type' => 'poster_heading', 'data' => ['text' => 'x']]]],
        ]));

        $this->assertSame(0, $poster['columns'][0]['more']);
    }

    /**
     * The tile's `image` goes straight into an `<img src>` in the admin, so
     * anything that is not a same-origin path or an http(s) URL is dropped.
     */
    public function testOnlySafeImageSourcesSurvive(): void
    {
        $sources = [
            '/uploads/ok.png' => '/uploads/ok.png',
            'https://cdn.example.com/ok.png' => 'https://cdn.example.com/ok.png',
            'javascript:alert(1)' => null,
            'data:image/svg+xml;base64,PHN2Zz4=' => null,
            '//evil.example.com/x.png' => null,
        ];

        foreach ($sources as $stored => $expected) {
            $poster = $this->makeBuilder()->build($this->payload([
                ['preset' => 'col-12', 'blocks' => [['type' => 'poster_picture', 'data' => ['src' => $stored]]]],
            ]));

            $this->assertSame($expected, $poster['columns'][0]['tiles'][0]['image'], $stored);
        }
    }

    public function testTheSectionBackgroundReachesThePoster(): void
    {
        $poster = $this->makeBuilder()->build($this->payload(
            [['preset' => 'col-12', 'blocks' => []]],
            settings: ['styling' => ['backgroundColor' => '#FAF5EE']],
        ));

        $this->assertSame('#faf5ee', $poster['background']);
        $this->assertFalse($poster['dark'], 'a pale ground keeps the default tiles');
    }

    public function testADarkBackgroundFlagsThePosterSoItsCopyStaysReadable(): void
    {
        $poster = $this->makeBuilder()->build($this->payload(
            [['preset' => 'col-12', 'blocks' => []]],
            settings: ['styling' => ['backgroundColor' => '#101828']],
        ));

        $this->assertSame('#101828', $poster['background']);
        $this->assertTrue($poster['dark']);
    }

    /**
     * A section styled entirely by a preset stores no colour of its own — the
     * poster has to resolve the preset or it posts a blank thumbnail for a
     * section that renders dark navy.
     */
    public function testAStylePresetSuppliesTheBackgroundWhenTheSectionSetsNone(): void
    {
        $poster = $this->makeBuilder()->build($this->payload(
            [['preset' => 'col-12', 'blocks' => []]],
            settings: ['styleName' => 'midnight'],
        ));

        $this->assertSame('#101828', $poster['background']);
        $this->assertTrue($poster['dark']);
    }

    /** Same precedence as the renderer: an explicit value beats the preset. */
    public function testAnExplicitBackgroundBeatsThePresetItOverrides(): void
    {
        $poster = $this->makeBuilder()->build($this->payload(
            [['preset' => 'col-12', 'blocks' => []]],
            settings: ['styleName' => 'midnight', 'styling' => ['backgroundColor' => '#ffffff']],
        ));

        $this->assertSame('#ffffff', $poster['background']);
        $this->assertFalse($poster['dark']);
    }

    public function testAClassOnlyPresetLeavesThePosterUntinted(): void
    {
        $poster = $this->makeBuilder()->build($this->payload(
            [['preset' => 'col-12', 'blocks' => []]],
            settings: ['styleName' => 'classes-only'],
        ));

        $this->assertNull($poster['background']);
        $this->assertFalse($poster['dark']);
    }

    /**
     * The styling forms only ever store `#hex` or ''. Anything else came from
     * elsewhere and must not be written into a style attribute.
     */
    public function testOnlyHexBackgroundsAreAccepted(): void
    {
        foreach (['', 'red', 'rgb(0,0,0)', 'url(x)', '#12', '#1234567', null, 42] as $stored) {
            $poster = $this->makeBuilder()->build($this->payload(
                [['preset' => 'col-12', 'blocks' => []]],
                settings: ['styling' => ['backgroundColor' => $stored]],
            ));

            $this->assertNull($poster['background'], var_export($stored, true));
        }
    }

    public function testShorthandHexIsAccepted(): void
    {
        $poster = $this->makeBuilder()->build($this->payload(
            [['preset' => 'col-12', 'blocks' => []]],
            settings: ['styling' => ['backgroundColor' => '#000']],
        ));

        $this->assertSame('#000', $poster['background']);
        $this->assertTrue($poster['dark']);
    }

    /**
     * `styling` is added to every block by the core's BlockFormType, so unlike
     * a block's own fields it is read directly — no hint involved.
     */
    public function testABlockBackgroundReachesItsTile(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'poster_heading', 'data' => ['text' => 'Card', 'styling' => ['backgroundColor' => '#EB0540']]],
                ['type' => 'poster_heading', 'data' => ['text' => 'Plain']],
            ]],
        ]));

        $tiles = $poster['columns'][0]['tiles'];
        $this->assertSame('#eb0540', $tiles[0]['background']);
        $this->assertNull($tiles[1]['background']);
    }

    /**
     * A tile answers the contrast question for itself: a saturated card can be
     * dark ground inside a light section, so it cannot inherit the section's
     * answer.
     */
    public function testATileWithItsOwnDarkColourFlagsItself(): void
    {
        $poster = $this->makeBuilder()->build($this->payload(
            [['preset' => 'col-12', 'blocks' => [
                ['type' => 'poster_heading', 'data' => ['text' => 'Dark card', 'styling' => ['backgroundColor' => '#eb0540']]],
                ['type' => 'poster_heading', 'data' => ['text' => 'Pale card', 'styling' => ['backgroundColor' => '#fff8e1']]],
            ]]],
            settings: ['styling' => ['backgroundColor' => '#faf5ee']],
        ));

        $tiles = $poster['columns'][0]['tiles'];
        $this->assertFalse($poster['dark'], 'the section itself is light…');
        $this->assertTrue($tiles[0]['backgroundDark'], '…but this card is not');
        $this->assertFalse($tiles[1]['backgroundDark']);
    }

    public function testAMissingBlockTypeTileCarriesNoBackground(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [['type' => 'gone', 'data' => ['styling' => ['backgroundColor' => '#eb0540']]]]],
        ]));

        $this->assertNull($poster['columns'][0]['tiles'][0]['background']);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('undrawablePayloads')]
    public function testPayloadWithNoDrawableStructureYieldsNoPoster(array $payload): void
    {
        $this->assertNull($this->makeBuilder()->build($payload));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function undrawablePayloads(): iterable
    {
        yield 'no columns key' => [['format' => 'content-blocks/section-v1']];
        yield 'empty columns' => [['format' => 'content-blocks/section-v1', 'columns' => []]];
        yield 'columns not a list' => [['columns' => 'nope']];
        yield 'columns hold no arrays' => [['columns' => ['nope', 42]]];
    }

    public function testMalformedBlockEntriesAreIgnoredRatherThanDrawn(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => ['nope', ['type' => 'poster_heading', 'data' => ['text' => 'Kept']]]],
        ]));

        $tiles = $poster['columns'][0]['tiles'];
        $this->assertCount(1, $tiles);
        $this->assertSame('Kept', $tiles[0]['text']);
    }

    public function testBlockDataOfTheWrongShapeDoesNotBreakTheTile(): void
    {
        $poster = $this->makeBuilder()->build($this->payload([
            ['preset' => 'col-12', 'blocks' => [['type' => 'poster_heading', 'data' => 'not-an-array']]],
        ]));

        $tile = $poster['columns'][0]['tiles'][0];
        $this->assertSame(BlockPreviewHint::KIND_GENERIC, $tile['kind'], 'empty data has nothing to show');
    }
}

abstract class PosterTestBlock extends AbstractBlockType
{
    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}

final class PosterHeadingBlock extends PosterTestBlock implements BlockPreviewHintInterface
{
    public static function getType(): string
    {
        return 'poster_heading';
    }

    public static function getLabel(): string
    {
        return 'Heading block';
    }

    public function previewHint(array $data): ?BlockPreviewHint
    {
        return BlockPreviewHint::heading($data['text'] ?? null);
    }
}

final class PosterPictureBlock extends PosterTestBlock implements BlockPreviewHintInterface
{
    public static function getType(): string
    {
        return 'poster_picture';
    }

    public static function getLabel(): string
    {
        return 'Picture block';
    }

    public function previewHint(array $data): ?BlockPreviewHint
    {
        return BlockPreviewHint::image((string) ($data['src'] ?? ''));
    }
}

/** Implements nothing: the "existing block types keep working" case. */
final class PosterMuteBlock extends PosterTestBlock
{
    public static function getType(): string
    {
        return 'poster_mute';
    }

    public static function getLabel(): string
    {
        return 'Muted block';
    }
}

final class PosterExplodingBlock extends PosterTestBlock implements BlockPreviewHintInterface
{
    public static function getType(): string
    {
        return 'poster_boom';
    }

    public static function getLabel(): string
    {
        return 'Exploding block';
    }

    public function previewHint(array $data): ?BlockPreviewHint
    {
        throw new \RuntimeException('stored data from another era');
    }
}
