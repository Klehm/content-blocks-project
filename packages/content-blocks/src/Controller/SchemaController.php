<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Schema\ContentSchemaBuilder;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The content schema of one area, for a client generating content into it.
 * Read-only; the generated content comes back through the paste path.
 *
 * @see docs/guide/content-schema.md
 *
 * @internal the route is the contract, not this class
 */
final class SchemaController
{
    /** An example is a whole section payload; past this it is just weight. */
    private const MAX_EXAMPLES = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentSchemaBuilder $builder,
        private readonly int $schemaExamples = 3,
    ) {
    }

    /**
     * canEdit(): the schema names the area's palette and saved sections,
     * which only its editors are meant to read.
     */
    #[Route('/area/{id}/schema', name: 'content_blocks_area_schema', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function schema(int $id, Request $request): JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $examples = $request->query->get('examples');
        $examples = \is_string($examples) && ctype_digit($examples)
            ? min((int) $examples, self::MAX_EXAMPLES)
            : $this->schemaExamples;

        $schema = $this->builder->build($area, $request->getLocale(), $examples);

        $response = new JsonResponse($schema->toArray());
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_UNESCAPED_UNICODE);

        return $response;
    }
}
