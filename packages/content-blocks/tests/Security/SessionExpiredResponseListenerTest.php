<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Security;

use ContentBlocks\Security\SessionExpiredResponseListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SessionExpiredResponseListenerTest extends TestCase
{
    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $headers
     */
    private function dispatch(
        array $attributes,
        array $headers,
        ?Response $response = null,
    ): Response {
        $request = Request::create('/whatever');
        $request->attributes->add($attributes);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response ?? new RedirectResponse('/login'),
        );
        (new SessionExpiredResponseListener())($event);

        return $event->getResponse();
    }

    /**
     * @return iterable<string, array{
     *     array<string, string>,
     *     array<string, string>
     * }>
     */
    public static function builderFetches(): iterable
    {
        yield 'fetch on a builder route' => [
            ['_route' => 'content_blocks_section_sidebar_save'],
            ['Sec-Fetch-Mode' => 'cors'],
        ];
        yield 'the block Live Component' => [
            ['_route' => 'ux_live_component', '_live_component' => 'ContentBlocks:Block'],
            ['Sec-Fetch-Mode' => 'same-origin'],
        ];
        yield 'no fetch metadata, JSON accepted' => [
            ['_route' => 'content_blocks_area_state'],
            ['Accept' => 'application/json'],
        ];
        yield 'no fetch metadata, Live accept header' => [
            ['_live_component' => 'ContentBlocks:Block'],
            ['Accept' => 'application/vnd.live-component+html'],
        ];
        yield 'no fetch metadata, XMLHttpRequest' => [
            ['_route' => 'content_blocks_upload'],
            ['X-Requested-With' => 'XMLHttpRequest'],
        ];
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $headers
     */
    #[DataProvider('builderFetches')]
    public function testALoginRedirectBecomesA401(array $attributes, array $headers): void
    {
        $response = $this->dispatch($attributes, $headers);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertSame('expired', $response->headers->get('X-Content-Blocks-Session'));
        $this->assertSame(
            ['error' => 'session_expired', 'login' => '/login'],
            json_decode((string) $response->getContent(), true),
        );
    }

    /**
     * @return iterable<string, array{
     *     array<string, string>,
     *     array<string, string>
     * }>
     */
    public static function untouchedRequests(): iterable
    {
        yield 'a navigation to a package page' => [
            ['_route' => 'content_blocks_i18n_workbench'],
            ['Sec-Fetch-Mode' => 'navigate'],
        ];
        yield 'a host route' => [
            ['_route' => 'app_admin_page_edit'],
            ['Sec-Fetch-Mode' => 'cors'],
        ];
        yield 'a host Live Component' => [
            ['_live_component' => 'App:Search'],
            ['Sec-Fetch-Mode' => 'same-origin'],
        ];
        yield 'no fetch metadata, browser accept header' => [
            ['_route' => 'content_blocks_assets_report'],
            ['Accept' => 'text/html,application/xhtml+xml'],
        ];
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $headers
     */
    #[DataProvider('untouchedRequests')]
    public function testOtherRedirectsAreLeftAlone(array $attributes, array $headers): void
    {
        $response = $this->dispatch($attributes, $headers);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testANonRedirectResponseIsLeftAlone(): void
    {
        $original = new JsonResponse(['ok' => true]);

        $response = $this->dispatch(
            ['_route' => 'content_blocks_area_state'],
            ['Sec-Fetch-Mode' => 'cors'],
            $original,
        );

        $this->assertSame($original, $response);
    }
}
