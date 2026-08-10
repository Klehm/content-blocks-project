<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\Kit\Block\AccordionBlock;
use ContentBlocks\Kit\Block\AlertBlock;
use ContentBlocks\Kit\Block\BreadcrumbBlock;
use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Block\CardBlock;
use ContentBlocks\Kit\Block\DividerBlock;
use ContentBlocks\Kit\Block\EmbedBlock;
use ContentBlocks\Kit\Block\GalleryBlock;
use ContentBlocks\Kit\Block\ImageBlock;
use ContentBlocks\Kit\Block\ListBlock;
use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\Block\TabsBlock;
use ContentBlocks\Kit\Block\TextBlock;
use ContentBlocks\Kit\Block\TitleBlock;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each block contributes to a section-library thumbnail.
 *
 * The hints are read by the core's SectionPosterBuilder off *stored* data, so
 * beyond the happy path these tests pin the two things that actually bite: a
 * block whose content was never filled in, and a row whose shape predates the
 * field being read.
 */
final class PreviewHintTest extends TestCase
{
    /** @return iterable<string, array{BlockPreviewHintInterface, array<string, mixed>, string, string|null}> */
    public static function hints(): iterable
    {
        yield 'title shows its heading' => [
            new TitleBlock(), ['text' => 'Our story'], BlockPreviewHint::KIND_HEADING, 'Our story',
        ];
        yield 'text shows its copy' => [
            new TextBlock(), ['content' => 'Some words'], BlockPreviewHint::KIND_TEXT, 'Some words',
        ];
        yield 'rich text is stripped of its markup' => [
            new RichTextBlock(), ['content' => '<p>Hello <b>world</b></p>'], BlockPreviewHint::KIND_TEXT, 'Hello world',
        ];
        yield 'button shows its label' => [
            new ButtonBlock(), ['text' => 'Buy now'], BlockPreviewHint::KIND_BUTTON, 'Buy now',
        ];
        yield 'list joins its items' => [
            new ListBlock(),
            ['items' => [['text' => 'One'], ['text' => 'Two']]],
            BlockPreviewHint::KIND_TEXT,
            'One · Two',
        ];
        yield 'breadcrumb reads as a trail' => [
            new BreadcrumbBlock(),
            ['items' => [['label' => 'Home'], ['label' => 'Shop']]],
            BlockPreviewHint::KIND_TEXT,
            'Home / Shop',
        ];
        yield 'alert prefers its title' => [
            new AlertBlock(),
            ['title' => 'Heads up', 'content' => 'Body'],
            BlockPreviewHint::KIND_HEADING,
            'Heads up',
        ];
        yield 'alert without a title falls back to its body' => [
            new AlertBlock(), ['title' => '', 'content' => 'Body'], BlockPreviewHint::KIND_TEXT, 'Body',
        ];
        yield 'accordion shows its first question' => [
            new AccordionBlock(), ['items' => [['title' => 'Why?']]], BlockPreviewHint::KIND_HEADING, 'Why?',
        ];
        yield 'tabs show the first tab' => [
            new TabsBlock(), ['items' => [['title' => 'Specs']]], BlockPreviewHint::KIND_HEADING, 'Specs',
        ];
        yield 'divider draws a rule' => [
            new DividerBlock(), ['style' => 'solid'], BlockPreviewHint::KIND_RULE, null,
        ];
        yield 'embed names itself with its title' => [
            new EmbedBlock(), ['title' => 'Trailer'], BlockPreviewHint::KIND_GENERIC, 'Trailer',
        ];
        yield 'card falls back to its first title when it has no cover' => [
            new CardBlock(),
            ['items' => [['src' => '', 'title' => 'Plan A']]],
            BlockPreviewHint::KIND_HEADING,
            'Plan A',
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('hints')]
    public function testHintForStoredData(
        BlockPreviewHintInterface $block,
        array $data,
        string $kind,
        ?string $text,
    ): void {
        $hint = $block->previewHint($data);

        $this->assertNotNull($hint);
        $this->assertSame($kind, $hint->kind);
        $this->assertSame($text, $hint->text);
    }

    public function testImageBlockShowsTheStoredPicture(): void
    {
        $hint = (new ImageBlock())->previewHint(['src' => '/uploads/hero.png', 'alt' => 'Hero']);

        $this->assertSame(BlockPreviewHint::KIND_IMAGE, $hint->kind);
        $this->assertSame('/uploads/hero.png', $hint->image);
    }

    public function testImageBlockWithNoFileYetNamesItselfWithItsCaption(): void
    {
        $hint = (new ImageBlock())->previewHint(['src' => '', 'caption' => 'Team at work']);

        $this->assertSame(BlockPreviewHint::KIND_GENERIC, $hint->kind);
        $this->assertSame('Team at work', $hint->text);
    }

    public function testGalleryAndCardUseTheFirstItemThatActuallyHasAPicture(): void
    {
        $items = [
            ['src' => '', 'title' => 'Empty'],
            ['src' => '/uploads/second.png', 'title' => 'Second'],
        ];

        $gallery = (new GalleryBlock())->previewHint(['items' => $items]);
        $this->assertSame(BlockPreviewHint::KIND_IMAGE, $gallery->kind);
        $this->assertSame('/uploads/second.png', $gallery->image);

        $card = (new CardBlock())->previewHint(['items' => $items]);
        $this->assertSame(BlockPreviewHint::KIND_IMAGE, $card->kind);
        $this->assertSame('/uploads/second.png', $card->image);
    }

    /**
     * Coded defaults are the shape a freshly-added block is stored with, so
     * every hint must survive them — several are empty strings.
     */
    public function testEveryHintSurvivesItsOwnDefaultData(): void
    {
        foreach ($this->hintingBlocks() as $type => $block) {
            $hint = $block->previewHint($block->getDefaultData());
            $this->assertNotNull($hint, "$type returned no hint for its own defaults");
        }
    }

    /**
     * A row written before a field existed — or by a host that changed its
     * schema — must cost the tile its detail, never throw. The poster builder
     * catches throwables as a backstop; the blocks should not need it.
     */
    #[DataProvider('malformedData')]
    public function testMalformedStoredDataDegradesInsteadOfThrowing(array $data): void
    {
        foreach ($this->hintingBlocks() as $type => $block) {
            $hint = $block->previewHint($data);
            $this->assertNotNull($hint, "$type returned no hint");
            $this->assertNotSame('', (string) $hint->kind, "$type produced no kind");
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedData(): iterable
    {
        yield 'empty' => [[]];
        yield 'scalars where strings are expected' => [['text' => 42, 'content' => false, 'src' => [], 'title' => null]];
        yield 'collection is a scalar' => [['items' => 'nope']];
        yield 'collection holds scalars' => [['items' => ['nope', 7]]];
        yield 'collection entries hold wrong types' => [['items' => [['text' => 1, 'title' => [], 'src' => false]]]];
    }

    /**
     * Every kit block implementing the seam, keyed by type — so a new block
     * that opts in is covered by the two loops above without being listed.
     *
     * @return iterable<string, BlockPreviewHintInterface>
     */
    private function hintingBlocks(): iterable
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            $block = new $class();
            if ($block instanceof BlockPreviewHintInterface) {
                yield $type => $block;
            }
        }
    }

    public function testTheBlocksThatOptedInAreTheOnesWeExpect(): void
    {
        $optedIn = array_keys(iterator_to_array($this->hintingBlocks()));
        sort($optedIn);

        $this->assertSame([
            'accordion', 'alert', 'breadcrumb', 'button', 'card', 'divider', 'embed',
            'gallery', 'image', 'list', 'rich_text', 'tabs', 'text', 'title',
        ], $optedIn, 'icon, table and html_raw deliberately stay generic tiles');
    }
}
