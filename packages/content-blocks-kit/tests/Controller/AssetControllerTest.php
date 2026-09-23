<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Controller;

use ContentBlocks\Kit\Controller\AssetController;
use ContentBlocks\Kit\Twig\StylesheetExtension;
use ContentBlocks\PublicAsset\StaticAssetResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class AssetControllerTest extends TestCase
{
    public function testTheStylesheetIsServedWithAnEtag(): void
    {
        $response = (new AssetController())->kitCss(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/css; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.cb-kit-', (string) $response->getContent());
        $this->assertNotNull($response->getEtag());
        $this->assertSame('300', $response->headers->getCacheControlDirective('max-age'));
    }

    // The helper and the route agree on the version, so the linked URL is
    // the one served as immutable.
    public function testTheVersionedUrlIsServedAsImmutable(): void
    {
        $url = (new StylesheetExtension($this->generator()))->stylesheetUrl();
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $response = (new AssetController())->kitCss(new Request($query));

        $this->assertTrue($response->headers->hasCacheControlDirective('immutable'));
        $this->assertSame(
            StaticAssetResponse::version((string) file_get_contents(AssetController::STYLESHEET)),
            $query['v'],
        );
    }

    public function testAMatchingEtagAnswers304(): void
    {
        $etag = (string) (new AssetController())->kitCss(new Request())->getEtag();
        $request = new Request();
        $request->headers->set('If-None-Match', $etag);

        $this->assertSame(304, (new AssetController())->kitCss($request)->getStatusCode());
    }

    private function generator(): UrlGeneratorInterface
    {
        return new class () implements UrlGeneratorInterface {
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/kit.css?' . http_build_query($parameters);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
    }
}
