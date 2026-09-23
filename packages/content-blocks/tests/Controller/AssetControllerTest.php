<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\PublicAsset\AssetController;
use ContentBlocks\PublicAsset\PackageAssets;
use ContentBlocks\PublicAsset\StaticAssetResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * These four routes serve files straight off disk into the *public* preview
 * page, with no bundler in the path — so the wiring is worth pinning.
 */
final class AssetControllerTest extends TestCase
{
    /**
     * builder.css reads var(--cb-*) everywhere but cannot @import the token
     * file: the browser would resolve it against /_content-blocks/public/,
     * which serves no such route. The controller concatenates instead, and if
     * that ever regresses the preview iframe loses every color at once.
     */
    public function testBuilderCssShipsTheDesignTokensWithIt(): void
    {
        $css = (new AssetController())->builderCss(new Request())->getContent();

        $this->assertIsString($css);
        $this->assertStringContainsString('--cb-accent-rgb:', $css, 'token definitions must be present');
        // The in-preview popover header is a caption and reads --cb-font-mono.
        // Nothing else defines that token inside the iframe, so if the type
        // tokens ever move out of tokens.css the header silently loses it.
        $this->assertStringContainsString('--cb-font-mono:', $css, 'type tokens must reach the iframe too');
        $this->assertStringContainsString('.cb-overlay-toolbar', $css, 'builder rules must be present');
        $this->assertLessThan(
            strpos($css, '.cb-overlay-toolbar'),
            strpos($css, '--cb-accent-rgb:'),
            'tokens must come first, otherwise the rules below them resolve to nothing',
        );
    }

    public function testTheOtherAssetsAreServedUnchanged(): void
    {
        $controller = new AssetController();

        // Content styles: they belong to the rendered page, not the chrome, so
        // they deliberately do not carry the builder's tokens.
        $this->assertStringNotContainsString('--cb-accent-rgb:', (string) $controller->layoutCss(new Request())->getContent());
        $this->assertStringNotContainsString('--cb-accent-rgb:', (string) $controller->stylingCss(new Request())->getContent());

        foreach ([$controller->layoutCss(new Request()), $controller->stylingCss(new Request()), $controller->builderCss(new Request())] as $response) {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('text/css; charset=UTF-8', $response->headers->get('Content-Type'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }

        $overlay = $controller->previewOverlay(new Request());
        $this->assertSame(200, $overlay->getStatusCode());
        $this->assertSame('application/javascript; charset=UTF-8', $overlay->headers->get('Content-Type'));
    }

    public function testAnUnversionedUrlIsCachedBrieflyWithAnEtag(): void
    {
        $response = (new AssetController())->layoutCss(new Request());

        $this->assertSame('"' . $this->version('layout') . '"', $response->getEtag());
        $this->assertSame('300', $response->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('immutable'));
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    // The URL names the content, so it can be kept for as long as it exists.
    public function testAVersionedUrlIsImmutable(): void
    {
        $request = new Request(['v' => $this->version('slider')]);
        $response = (new AssetController())->slider($request);

        $this->assertSame('31536000', $response->headers->getCacheControlDirective('max-age'));
        $this->assertTrue($response->headers->hasCacheControlDirective('immutable'));
    }

    // A stale version (an old page in a cache) is served, but not kept.
    public function testAStaleVersionIsNotKeptForAYear(): void
    {
        $response = (new AssetController())->slider(new Request(['v' => 'stale']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('300', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testAMatchingEtagAnswers304WithoutABody(): void
    {
        $request = new Request();
        $request->headers->set('If-None-Match', '"' . $this->version('styling') . '"');

        $response = (new AssetController())->stylingCss($request);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getContent());
    }

    public function testEveryPackageAssetIsReadable(): void
    {
        foreach (PackageAssets::names() as $name) {
            $this->assertNotNull(PackageAssets::content($name), $name);
        }
    }

    private function version(string $name): string
    {
        return StaticAssetResponse::version((string) PackageAssets::content($name));
    }
}
