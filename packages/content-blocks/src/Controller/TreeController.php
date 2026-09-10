<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Builder\AreaTreeBuilder;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The area's outline, for the builder's tree panel. Read-only: every action
 * the tree offers goes to the endpoint that already owns it.
 *
 * @see docs/internals/frontend.md#the-tree-is-a-second-view-not-a-second-state
 *
 * @internal the route is the contract, not this class
 */
#[Route('/_content-blocks')]
final class TreeController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly AreaTreeBuilder $treeBuilder,
    ) {
    }

    /**
     * canEdit(), not canView(): the panel exists to move, duplicate and
     * delete, and it is only ever rendered inside the builder.
     */
    #[Route('/area/{id}/tree', name: 'content_blocks_area_tree', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function tree(int $id): JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return new JsonResponse($this->treeBuilder->build($area));
    }
}
