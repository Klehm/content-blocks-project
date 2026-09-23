<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Security;

use ContentBlocks\Security\PreviewResponseListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PreviewResponseListenerTest extends TestCase
{
    public function testAPreviewIsPrivateAndFramedByItsOwnOriginOnly(): void
    {
        $response = $this->respond('/page?cb_preview=1');

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testAHostFramingPolicyIsKept(): void
    {
        $response = $this->respond('/page?cb_preview=1', ['X-Frame-Options' => 'DENY']);

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testAPublicPageIsLeftAlone(): void
    {
        $response = $this->respond('/page', ['Cache-Control' => 'public, max-age=60']);

        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }

    /** @param array<string, string> $headers */
    private function respond(string $uri, array $headers = []): Response
    {
        $response = new Response('', 200, $headers);
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        (new PreviewResponseListener())($event);

        return $response;
    }
}
