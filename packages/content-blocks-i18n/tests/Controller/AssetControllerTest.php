<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Controller;

use ContentBlocks\I18n\Controller\AssetController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AssetControllerTest extends TestCase
{
    public function testBothFilesAreServedWithAnEtag(): void
    {
        $controller = new AssetController();

        foreach ([$controller->css(new Request()), $controller->js(new Request())] as $response) {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertNotSame('', (string) $response->getContent());
            $this->assertNotNull($response->getEtag());
            $this->assertSame('300', $response->headers->getCacheControlDirective('max-age'));
        }
    }

    // The workbench links each file by its version, which the route keeps.
    public function testTheLinkedVersionIsServedAsImmutable(): void
    {
        $request = new Request(['v' => AssetController::version('workbench.js')]);
        $response = (new AssetController())->js($request);

        $this->assertTrue($response->headers->hasCacheControlDirective('immutable'));
        $this->assertSame('text/javascript; charset=UTF-8', $response->headers->get('Content-Type'));
    }
}
