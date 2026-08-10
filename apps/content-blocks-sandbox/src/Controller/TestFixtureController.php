<?php

declare(strict_types=1);

namespace App\Controller;

use ContentBlocks\Entity\SectionTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fixture endpoints for the Playwright suite. This sandbox *is* the browser-test
 * fixture (see the package's playwright.config.js), and some states cannot be
 * reached through the UI at all: a section template referencing a block type
 * that does not exist here can only be written directly, since the library's own
 * save endpoint snapshots real, registered blocks.
 *
 * Guarded on kernel.debug so it never exists in a production build, and kept out
 * of the package — this is app-side test scaffolding, not a feature.
 */
final class TestFixtureController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    /**
     * Writes a section template with an arbitrary payload, so a spec can stage
     * a library entry whose blocks (or envelope format) this build cannot use.
     */
    #[Route('/test-fixtures/section-template', name: 'app_test_fixture_section_template', methods: ['POST'])]
    public function sectionTemplate(Request $request): JsonResponse
    {
        if (!$this->debug) {
            return new JsonResponse(['error' => 'Not available'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !is_string($body['name'] ?? null) || !is_array($body['payload'] ?? null)) {
            return new JsonResponse(['error' => 'Expected {name, payload, blockTypes}'], Response::HTTP_BAD_REQUEST);
        }

        $template = (new SectionTemplate())
            ->setName($body['name'])
            ->setPayload($body['payload'])
            ->setBlockTypes(array_values(array_filter(
                (array) ($body['blockTypes'] ?? []),
                is_string(...),
            )))
            // Explicit so a spec can stage a snapshot from an older schema
            // generation; omit it for "predates versioning".
            ->setContentVersion(is_int($body['contentVersion'] ?? null) ? $body['contentVersion'] : null);

        $this->em->persist($template);
        $this->em->flush();

        return new JsonResponse(['id' => $template->getId()]);
    }

    /**
     * A real, always-present image at a stable URL.
     *
     * The section-library thumbnail renders a stored picture as an `<img>` and
     * falls back to a labelled tile when the file is gone — so a spec asserting
     * the *picture* path needs a source that genuinely resolves. Uploads live
     * under a gitignored directory, which makes them unavailable on a fresh
     * clone; a route can't go missing.
     *
     * Deliberately extension-less. Playwright's webServer runs PHP's built-in
     * server with no router script, and that server treats any path ending in a
     * known static extension as a file on disk — `/…/pixel.png` would 404
     * without ever reaching Symfony. The browser reads the Content-Type header,
     * not the URL, so an `<img>` is perfectly happy with this.
     */
    #[Route('/test-fixtures/pixel', name: 'app_test_fixture_pixel', methods: ['GET'])]
    public function pixel(): Response
    {
        if (!$this->debug) {
            return new Response('Not available', Response::HTTP_NOT_FOUND);
        }

        // 1×1 transparent PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true,
        );

        return new Response($png, Response::HTTP_OK, ['Content-Type' => 'image/png']);
    }
}
