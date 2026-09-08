<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Asset;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use PHPUnit\Framework\TestCase;

final class AssetReferenceCollectorTest extends TestCase
{
    /**
     * Resolver double with the shape of the real one: a whole-string prefix
     * test, which is precisely why embedded references need finding.
     */
    private function makeCollector(string $prefix = '/uploads/'): AssetReferenceCollector
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(
            static fn (string $value) => str_starts_with($value, $prefix),
        );

        return new AssetReferenceCollector($resolver);
    }

    public function testCollectsAPathStoredAsAWholeValue(): void
    {
        $paths = $this->makeCollector()->collect(['src' => '/uploads/a.png', 'alt' => 'A picture']);

        $this->assertSame(['/uploads/a.png'], $paths);
    }

    public function testWalksNestedArraysAndCollectionEntries(): void
    {
        $paths = $this->makeCollector()->collect([
            'items' => [
                ['_id' => 'x1', 'src' => '/uploads/a.png'],
                ['_id' => 'x2', 'src' => '/uploads/b.png'],
            ],
            'styling' => ['backgroundColor' => '#eb0540'],
        ]);

        $this->assertSame(['/uploads/a.png', '/uploads/b.png'], $paths);
    }

    /**
     * The regression this class exists for: a rich-text editor's uploads live
     * inside the HTML, where a whole-string prefix test cannot see them.
     */
    public function testCollectsPathsEmbeddedInRichTextMarkup(): void
    {
        $paths = $this->makeCollector()->collect([
            'html' => '<p>Hello</p><figure><img src="/uploads/hero.png" alt="Hero"></figure>',
        ]);

        $this->assertSame(['/uploads/hero.png'], $paths);
    }

    public function testCollectsEveryCandidateOfASrcsetAndOfCssUrlSyntax(): void
    {
        $paths = $this->makeCollector()->collect([
            'html' => '<img srcset="/uploads/a.png 1x, /uploads/b.png 2x">',
            'style' => 'background-image: url(/uploads/c.png);',
        ]);

        $this->assertSame(['/uploads/a.png', '/uploads/b.png', '/uploads/c.png'], $paths);
    }

    /**
     * A prefix-testing resolver says yes to `/uploads/a.png.` as readily as to
     * `/uploads/a.png`, so the collector reports both rather than guessing.
     * Over-marking only ever spares a file from the sweep.
     */
    public function testReportsBothVariantsOfABarePathFollowedByProsePunctuation(): void
    {
        $paths = $this->makeCollector()->collect(['html' => '<p>See /uploads/a.png.</p>']);

        $this->assertContains('/uploads/a.png', $paths);
        $this->assertContains('/uploads/a.png.', $paths);
    }

    /**
     * The export side resolves the ambiguity above: the reader answers for the
     * over-long variant with null (no such file), so it is left alone, and the
     * real path is substituted inside it — punctuation intact.
     */
    public function testMapSubstitutesTheRealPathAndLeavesProsePunctuationOutside(): void
    {
        $mapped = $this->makeCollector()->map(
            ['html' => '<p>See /uploads/a.png.</p>'],
            static fn (string $path) => $path === '/uploads/a.png' ? 'asset://hash-of-a' : $path,
        );

        $this->assertSame(['html' => '<p>See asset://hash-of-a.</p>'], $mapped);
    }

    public function testDeduplicatesRepeatedReferences(): void
    {
        $paths = $this->makeCollector()->collect([
            'a' => '/uploads/a.png',
            'html' => '<img src="/uploads/a.png"><img src="/uploads/a.png">',
        ]);

        $this->assertSame(['/uploads/a.png'], $paths);
    }

    /**
     * Found by running the sweep against a real database: TinyMCE rewrites the
     * absolute URL the upload endpoint returns into a document-relative one
     * (`relative_urls` is on by default), so this — not the absolute spelling —
     * is how an editor-uploaded image is normally stored. The file is on a live
     * page; a prefix test says no to it.
     */
    public function testCollectsARelativePathWrittenByARichTextEditor(): void
    {
        $paths = $this->makeCollector()->collect([
            'content' => '<p><img src="../../uploads/hero.png" alt="Hero" width="368"></p>',
        ]);

        $this->assertSame(['/uploads/hero.png'], $paths);
    }

    public function testCollectsARelativePathWithNoLeadingDots(): void
    {
        $paths = $this->makeCollector()->collect(['content' => '<img src="uploads/hero.png">']);

        $this->assertSame(['/uploads/hero.png'], $paths);
    }

    /**
     * The substitution unit for a relative reference is the whole URL, not the
     * part the storage recognized — `../../asset://…` would be nonsense, and
     * the import side would rebuild a broken relative path from it.
     */
    public function testMapReplacesAWholeRelativeUrlRatherThanItsTail(): void
    {
        $mapped = $this->makeCollector()->map(
            ['content' => '<img src="../../uploads/hero.png" alt="Hero">'],
            static fn (string $path) => 'asset://hash-of-hero',
        );

        $this->assertSame(
            ['content' => '<img src="asset://hash-of-hero" alt="Hero">'],
            $mapped,
        );
    }

    /**
     * The guard on shape 3: an ordinary absolute link that merely happens to
     * contain the storage prefix further along must not be mistaken for an
     * asset, or the export would mangle it.
     */
    public function testDoesNotMistakeAnAbsoluteLinkContainingThePrefixForAnAsset(): void
    {
        $paths = $this->makeCollector()->collect([
            'content' => '<a href="/blog/uploads/hero.png">Read</a>',
        ]);

        $this->assertSame([], $paths);
    }

    public function testIgnoresStringsThatOnlyLookLikePaths(): void
    {
        $paths = $this->makeCollector()->collect([
            'html' => '<a href="/blog/hello">Hello</a><img src="https://cdn.example.com/x.png">',
            'text' => 'Nothing to see here',
        ]);

        $this->assertSame([], $paths);
    }

    public function testWorksWithAResolverThatRecognizesAbsoluteUrls(): void
    {
        $collector = $this->makeCollector('https://cdn.example.com/');

        $paths = $collector->collect(['html' => '<img src="https://cdn.example.com/a.png">']);

        $this->assertSame(['https://cdn.example.com/a.png'], $paths);
    }

    public function testMapReplacesAWholeValue(): void
    {
        $mapped = $this->makeCollector()->map(
            ['src' => '/uploads/a.png'],
            static fn (string $path) => 'asset://hash-of-a',
        );

        $this->assertSame(['src' => 'asset://hash-of-a'], $mapped);
    }

    public function testMapReplacesInPlaceInsideMarkupAndKeepsTheSurroundingHtml(): void
    {
        $mapped = $this->makeCollector()->map(
            ['html' => '<p>Hi</p><img src="/uploads/a.png" alt="A">'],
            static fn (string $path) => 'asset://hash-of-a',
        );

        $this->assertSame(
            ['html' => '<p>Hi</p><img src="asset://hash-of-a" alt="A">'],
            $mapped,
        );
    }

    /**
     * Longest-first replacement: substituting the shorter path first would
     * leave `asset://hash-of-a.webp` behind for the longer one.
     */
    public function testMapDoesNotCorruptAPathThatIsAPrefixOfAnother(): void
    {
        $mapped = $this->makeCollector()->map(
            ['html' => '<img src="/uploads/a.png"><img src="/uploads/a.png.webp">'],
            static fn (string $path) => 'asset://' . md5($path),
        );

        $this->assertSame(
            [
                'html' => sprintf(
                    '<img src="asset://%s"><img src="asset://%s">',
                    md5('/uploads/a.png'),
                    md5('/uploads/a.png.webp'),
                ),
            ],
            $mapped,
        );
    }

    public function testMapLeavesNonStringLeavesAlone(): void
    {
        $mapped = $this->makeCollector()->map(
            ['width' => 400, 'rounded' => true, 'ratio' => 1.5, 'nothing' => null],
            static fn (string $path) => 'replaced',
        );

        $this->assertSame(['width' => 400, 'rounded' => true, 'ratio' => 1.5, 'nothing' => null], $mapped);
    }

    public function testAReplacementThatReturnsThePathIsANoOp(): void
    {
        $payload = ['html' => '<img src="/uploads/a.png">'];

        $this->assertSame(
            $payload,
            $this->makeCollector()->map($payload, static fn (string $path) => $path),
        );
    }
}
