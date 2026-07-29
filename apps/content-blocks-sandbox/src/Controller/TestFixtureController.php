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
            )));

        $this->em->persist($template);
        $this->em->flush();

        return new JsonResponse(['id' => $template->getId()]);
    }
}
