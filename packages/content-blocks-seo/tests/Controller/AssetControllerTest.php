<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Tests\Controller;

use ContentBlocks\PublicAsset\StaticAssetResponse;
use ContentBlocks\Seo\Controller\AssetController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AssetControllerTest extends TestCase
{
    /** @return iterable<array{string, string, string}> */
    public static function files(): iterable
    {
        yield 'stylesheet' => ['css', 'text/css; charset=UTF-8', '.cb-seo'];
        yield 'panel' => ['js', 'text/javascript; charset=UTF-8', 'class SeoPanel'];
        yield 'audit' => ['audit', 'text/javascript; charset=UTF-8', 'export function audit'];
    }

    #[DataProvider('files')]
    public function testEachFileIsServedWithItsType(string $type, string $contentType, string $needle): void
    {
        $response = (new AssetController())->serve(new Request(), $type);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($contentType, $response->headers->get('Content-Type'));
        $this->assertStringContainsString($needle, (string) $response->getContent());
    }

    public function testAVersionedUrlIsCachedForAYear(): void
    {
        $request = new Request(['v' => AssetController::version('audit')]);
        $response = (new AssetController())->serve($request, 'audit');

        $this->assertSame(StaticAssetResponse::VERSIONED_MAX_AGE, $response->getMaxAge());
    }
}
