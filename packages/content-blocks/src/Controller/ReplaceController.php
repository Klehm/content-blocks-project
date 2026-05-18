<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Service\SectionCloner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Endpoints for the "replace this area's content with another area's
 * content" flow:
 *
 *  - GET  /area/{id}/replace-candidates   List filterable candidate areas
 *  - POST /area/{id}/replace-with/{src}   Replace target's content with source's
 *
 * The replace writes to *draft* state on the target: existing sections are
 * soft-deleted (Section::deleted = true) and clones of the source's
 * sections are inserted as never-published drafts. The user can preview the
 * result and either Publish (commits the swap) or Discard (restores the
 * original content). This mirrors how every other structural op behaves.
 */
#[Route('/_content-blocks')]
final class ReplaceController
{
    use CsrfProtectedTrait;

    /** Default page size for the picker. */
    private const PAGE_SIZE = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentAreaProviderInterface $provider,
        private readonly SectionCloner $sectionCloner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    /**
     * Returns a paginated, filtered list of candidate ContentAreas.
     *
     * The target area is excluded so users can't accidentally "replace
     * with itself" (which would soft-delete then re-clone). The host's
     * provider supplies the query + label; this controller only adds the
     * exclusion, sort, and LIMIT/OFFSET.
     */
    #[Route(
        '/area/{id}/replace-candidates',
        name: 'content_blocks_replace_candidates',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function candidates(int $id, Request $request): JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $rawQuery = $request->query->get('q');
        $filter = is_string($rawQuery) ? $rawQuery : null;
        $page = max(0, (int) $request->query->get('page', 0));
        $pageSize = self::PAGE_SIZE;

        $qb = $this->provider->createQueryBuilder($filter);
        $aliases = $qb->getRootAliases();
        $alias = $aliases[0] ?? 'a';

        // Exclude the target — we ask for one extra row to detect hasMore
        // without a separate count query.
        $qb->andWhere(\sprintf('%s.id <> :selfId', $alias))
            ->setParameter('selfId', $id)
            ->orderBy(\sprintf('%s.updatedAt', $alias), 'DESC')
            ->addOrderBy(\sprintf('%s.id', $alias), 'DESC')
            ->setFirstResult($page * $pageSize)
            ->setMaxResults($pageSize + 1);

        /** @var list<ContentArea> $rows */
        $rows = $qb->getQuery()->getResult();
        $hasMore = \count($rows) > $pageSize;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $pageSize);
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => $row->getId(),
                'label' => $this->provider->getLabel($row),
            ];
        }

        return new JsonResponse([
            'items' => $items,
            'hasMore' => $hasMore,
            'page' => $page,
        ]);
    }

    /**
     * Replaces target area's sections with deep-clones of source area's
     * sections, all written to draft state. Existing sections are
     * soft-deleted (committed at next Publish), clones land at
     * previewPosition 0..N preserving the source's order.
     */
    #[Route(
        '/area/{id}/replace-with/{sourceId}',
        name: 'content_blocks_replace_with',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'sourceId' => '\d+'],
    )]
    public function replaceWith(int $id, int $sourceId, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        if ($id === $sourceId) {
            return new JsonResponse(['error' => 'Cannot replace an area with itself'], Response::HTTP_BAD_REQUEST);
        }

        $target = $this->em->find(ContentArea::class, $id);
        if (!$target) {
            return new JsonResponse(['error' => 'Target ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($target)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $source = $this->em->find(ContentArea::class, $sourceId);
        if (!$source) {
            return new JsonResponse(['error' => 'Source ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        // Source must be readable by the current user — otherwise the
        // replace flow becomes an IDOR vector to copy private content
        // out of unauthorized areas.
        if (!$this->accessChecker->canView($source)) {
            throw new ContentBlocksAccessDeniedException();
        }

        // Soft-delete every existing section. The actual em->remove() runs
        // at publish time (see ContentAreaPublisher).
        foreach ($target->getSections() as $existing) {
            $existing->setDeleted(true);
        }

        // Filter the source's sections the same way the rendering code does:
        // skip soft-deleted entries, walk in previewPosition order so the
        // clone preserves the source's draft order rather than its public
        // order (the user's most recent intent is what they want to copy).
        $sourceSections = array_values(array_filter(
            $source->getSections()->toArray(),
            fn ($section) => !$section->isDeleted(),
        ));
        usort(
            $sourceSections,
            fn ($a, $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition(),
        );

        foreach ($sourceSections as $i => $sourceSection) {
            $copy = $this->sectionCloner->cloneSection($sourceSection);
            $copy->setPreviewPosition($i);
            $target->addSection($copy);
            $this->em->persist($copy);
        }

        $this->em->flush();

        return new JsonResponse([
            'replaced' => true,
            'sectionCount' => \count($sourceSections),
            'hasUnpublishedChanges' => $target->hasUnpublishedChanges(),
        ]);
    }
}
