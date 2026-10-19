<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Rendering;

use ContentBlocks\BlockType\BlockAssets;
use ContentBlocks\Rendering\BlockAssetUrls;
use ContentBlocks\Rendering\EncoreEntrypointResolver;
use ContentBlocks\Rendering\EntrypointResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\StaticVersionStrategy;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookup;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupCollection;

final class BlockAssetUrlsTest extends TestCase
{
    // Two gallery blocks on one page link the carousel script once.
    public function testEachUrlIsKeptOnceInTheOrderDeclared(): void
    {
        $urls = (new BlockAssetUrls())->collect([
            new BlockAssets(stylesheets: ['/a.css', '/b.css'], modules: ['/m.js']),
            new BlockAssets(stylesheets: ['/b.css', '/c.css'], scripts: ['/s.js'], modules: ['/m.js']),
        ]);

        $this->assertSame([
            'stylesheets' => ['/a.css', '/b.css', '/c.css'],
            'scripts' => ['/s.js'],
            'modules' => ['/m.js'],
        ], $urls);
    }

    public function testNothingDeclaredIsThreeEmptyLists(): void
    {
        $this->assertSame(
            ['stylesheets' => [], 'scripts' => [], 'modules' => []],
            (new BlockAssetUrls())->collect([]),
        );
    }

    // A host with framework.assets: AssetMapper, a manifest, a CDN base.
    public function testAnAssetPathGoesThroughThePackages(): void
    {
        $packages = new Packages(new Package(new StaticVersionStrategy('v7')));

        $urls = (new BlockAssetUrls($packages))->collect([
            new BlockAssets(stylesheets: ['styles/map.css']),
        ]);

        $this->assertSame(['styles/map.css?v7'], $urls['stylesheets']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urls(): iterable
    {
        yield 'an absolute path' => ['/build/map.css'];
        yield 'a protocol-relative URL' => ['//cdn.example.com/map.css'];
        yield 'an https URL' => ['https://cdn.example.com/map.css'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urls')]
    public function testAUrlIsLinkedAsWritten(string $url): void
    {
        $packages = new Packages(new Package(new StaticVersionStrategy('v7')));

        $urls = (new BlockAssetUrls($packages))->collect([new BlockAssets(modules: [$url])]);

        $this->assertSame([$url], $urls['modules']);
    }

    // ---------- Webpack Encore entries ----------

    // The runtime and shared chunk come with the entry, as with
    // encore_entry_script_tags(): the entry file alone would never run.
    public function testAnEntryBringsItsRuntimeChunksAndCss(): void
    {
        $urls = (new BlockAssetUrls(null, new EncoreEntrypointResolver($this->encore())))->collect([
            new BlockAssets(entries: ['blocks/counter']),
        ]);

        $this->assertSame(['/build/counter.css'], $urls['stylesheets']);
        $this->assertSame(['/build/runtime.js', '/build/104.js', '/build/counter.js'], $urls['scripts']);
    }

    // The layout's encore_entry_script_tags('app') ran first in the request:
    // the runtime and the chunk it shares are not linked a second time.
    public function testWhatTheLayoutAlreadyEmittedIsNotLinkedAgain(): void
    {
        $encore = $this->encore();
        $encore->getEntrypointLookup()->getJavaScriptFiles('app');

        $urls = (new BlockAssetUrls(null, new EncoreEntrypointResolver($encore)))->collect([
            new BlockAssets(entries: ['blocks/counter']),
        ]);

        $this->assertSame(['/build/counter.js'], $urls['scripts']);
    }

    // Another bundler's adapter (Vite emits modules), aliased by the host.
    public function testAnyResolverCanServeTheEntries(): void
    {
        $vite = new class () implements EntrypointResolverInterface {
            public function resolve(string $entry): BlockAssets
            {
                return new BlockAssets(stylesheets: ["/v/{$entry}.css"], modules: ["/v/{$entry}.js"]);
            }
        };

        $urls = (new BlockAssetUrls(null, $vite))->collect([new BlockAssets(entries: ['map'])]);

        $this->assertSame(['/v/map.css'], $urls['stylesheets']);
        $this->assertSame(['/v/map.js'], $urls['modules']);
    }

    // The adapter's URLs are final: no second pass through `asset()`.
    public function testAResolversUrlsAreNotResolvedAgain(): void
    {
        $packages = new Packages(new Package(new StaticVersionStrategy('v7')));
        $resolver = new class () implements EntrypointResolverInterface {
            public function resolve(string $entry): BlockAssets
            {
                return new BlockAssets(scripts: ['build/x.123.js']);
            }
        };

        $urls = (new BlockAssetUrls($packages, $resolver))->collect([new BlockAssets(entries: ['x'])]);

        $this->assertSame(['build/x.123.js'], $urls['scripts']);
    }

    public function testEntriesWithoutAResolverAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EntrypointResolverInterface');

        (new BlockAssetUrls())->collect([new BlockAssets(entries: ['blocks/counter'])]);
    }

    private function encore(): EntrypointLookupCollection
    {
        $file = tempnam(sys_get_temp_dir(), 'entrypoints');
        file_put_contents($file, json_encode(['entrypoints' => [
            'app' => ['js' => ['/build/runtime.js', '/build/104.js', '/build/app.js']],
            'blocks/counter' => [
                'js' => ['/build/runtime.js', '/build/104.js', '/build/counter.js'],
                'css' => ['/build/counter.css'],
            ],
        ]]));
        $lookup = new EntrypointLookup($file);

        return new EntrypointLookupCollection(new ServiceLocator(['_default' => fn () => $lookup]), '_default');
    }

    // No framework.assets: the path is linked as the block wrote it.
    public function testWithoutPackagesAPathIsLinkedAsWritten(): void
    {
        $urls = (new BlockAssetUrls())->collect([new BlockAssets(scripts: ['js/map.js'])]);

        $this->assertSame(['js/map.js'], $urls['scripts']);
    }
}
