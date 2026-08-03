<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\AssetController;
use PHPUnit\Framework\TestCase;

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
        $css = (new AssetController())->builderCss()->getContent();

        $this->assertIsString($css);
        $this->assertStringContainsString('--cb-accent-rgb:', $css, 'token definitions must be present');
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
        $this->assertStringNotContainsString('--cb-accent-rgb:', (string) $controller->layoutCss()->getContent());
        $this->assertStringNotContainsString('--cb-accent-rgb:', (string) $controller->stylingCss()->getContent());

        foreach ([$controller->layoutCss(), $controller->stylingCss(), $controller->builderCss()] as $response) {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('text/css; charset=UTF-8', $response->headers->get('Content-Type'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }

        $overlay = $controller->previewOverlay();
        $this->assertSame(200, $overlay->getStatusCode());
        $this->assertSame('application/javascript; charset=UTF-8', $overlay->headers->get('Content-Type'));
    }
}
