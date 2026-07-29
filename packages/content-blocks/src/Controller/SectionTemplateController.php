<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Entity\SectionTemplate;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateManagerInterface;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiatorInterface;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Endpoints for the global "section template" library — save a section as a
 * reusable snapshot and re-insert it into any area:
 *
 *  - POST   /section/{sectionId}/save-as-template     Snapshot a section
 *  - GET    /area/{id}/section-templates              Paginated/filtered library
 *  - POST   /area/{id}/insert-template/{templateId}   Insert a snapshot as draft
 *  - PATCH  /section-templates/{templateId}           Rename (management)
 *  - DELETE /section-templates/{templateId}           Delete (management)
 *
 * Save and insert are gated by AccessCheckerInterface::canEdit() on the area
 * at hand (you may only capture/drop content where you can already edit).
 * Rename/delete touch the shared library with no area to key off, so they use
 * the dedicated SectionTemplateManagerInterface::canManage() capability.
 *
 * Insert writes to *draft* state on the target (a new section appended at the
 * end in previewPosition order), mirroring every other structural op: Publish
 * commits it, Discard reverts it.
 */
#[Route('/_content-blocks')]
final class SectionTemplateController
{
    use CsrfProtectedTrait;

    /** Default page size for the library picker. */
    private const PAGE_SIZE = 10;

    private const MAX_NAME_LENGTH = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly SectionTemplateManagerInterface $templateManager,
        private readonly SectionTemplateSerializerInterface $serializer,
        private readonly SectionTemplateInstantiatorInterface $instantiator,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    /**
     * Snapshots a section (layout + settings + columns + blocks + data) into a
     * named library entry. Authorized by canEdit() on the section's own area.
     */
    #[Route(
        '/section/{sectionId}/save-as-template',
        name: 'content_blocks_section_template_save',
        methods: ['POST'],
        requirements: ['sectionId' => '\d+'],
    )]
    public function save(int $sectionId, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $section = $this->em->find(Section::class, $sectionId);
        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $section->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $name = $this->readName($request);
        if ($name === null) {
            return new JsonResponse(['error' => 'A template name is required'], Response::HTTP_BAD_REQUEST);
        }

        $serialized = $this->serializer->serialize($section);

        $template = (new SectionTemplate())
            ->setName($name)
            ->setPayload($serialized['payload'])
            ->setBlockTypes($serialized['blockTypes']);

        $this->em->persist($template);
        $this->em->flush();

        return new JsonResponse(['id' => $template->getId(), 'name' => $template->getName()]);
    }

    /**
     * Paginated, name-filtered list of library templates. Each entry carries a
     * `compatible` flag (and the offending `missingTypes`) computed against the
     * current registry, so the UI can disable incompatible templates up front
     * rather than failing on insert.
     */
    #[Route(
        '/area/{id}/section-templates',
        name: 'content_blocks_section_template_list',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function list(int $id, Request $request): JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $rawQuery = $request->query->get('q');
        $filter = is_string($rawQuery) ? trim($rawQuery) : '';
        $page = max(0, (int) $request->query->get('page', 0));
        $pageSize = self::PAGE_SIZE;

        $qb = $this->em->getRepository(SectionTemplate::class)->createQueryBuilder('t');
        if ($filter !== '') {
            $qb->andWhere('t.name LIKE :q')->setParameter('q', '%' . $filter . '%');
        }
        // One extra row detects hasMore without a separate count query.
        $qb->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($page * $pageSize)
            ->setMaxResults($pageSize + 1);

        /** @var list<SectionTemplate> $rows */
        $rows = $qb->getQuery()->getResult();
        $hasMore = \count($rows) > $pageSize;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $pageSize);
        }

        $items = [];
        foreach ($rows as $template) {
            $missing = $this->missingTypes($template);
            $items[] = [
                'id' => $template->getId(),
                'name' => $template->getName(),
                'compatible' => $missing === [],
                'missingTypes' => $missing,
                'canManage' => $this->templateManager->canManage(),
                'createdAt' => $template->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'items' => $items,
            'hasMore' => $hasMore,
            'page' => $page,
        ]);
    }

    /**
     * Instantiates a template into the target area as a new draft section
     * appended at the end. Unknown block types abort with 422; unknown data
     * fields only warn (returned in `warnings`, never blocking the insert).
     */
    #[Route(
        '/area/{id}/insert-template/{templateId}',
        name: 'content_blocks_section_template_insert',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'templateId' => '\d+'],
    )]
    public function insert(int $id, int $templateId, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $template = $this->em->find(SectionTemplate::class, $templateId);
        if (!$template) {
            return new JsonResponse(['error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->instantiator->instantiate($template->getPayload());
        } catch (IncompatibleTemplateException $e) {
            return new JsonResponse([
                'error' => 'incompatible_template',
                'missingTypes' => $e->getMissingTypes(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $section = $result->section;
        $section->setPreviewPosition($this->nextPreviewPosition($area));
        $area->addSection($section);
        $this->em->persist($section);
        $this->em->flush();

        return new JsonResponse([
            'sectionId' => $section->getId(),
            'warnings' => $result->warnings,
        ]);
    }

    #[Route(
        '/section-templates/{templateId}',
        name: 'content_blocks_section_template_rename',
        methods: ['PATCH'],
        requirements: ['templateId' => '\d+'],
    )]
    public function rename(int $templateId, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }
        if (!$this->templateManager->canManage()) {
            throw new ContentBlocksAccessDeniedException();
        }

        $template = $this->em->find(SectionTemplate::class, $templateId);
        if (!$template) {
            return new JsonResponse(['error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $name = $this->readName($request);
        if ($name === null) {
            return new JsonResponse(['error' => 'A template name is required'], Response::HTTP_BAD_REQUEST);
        }

        $template->setName($name);
        $this->em->flush();

        return new JsonResponse(['id' => $template->getId(), 'name' => $template->getName()]);
    }

    #[Route(
        '/section-templates/{templateId}',
        name: 'content_blocks_section_template_delete',
        methods: ['DELETE'],
        requirements: ['templateId' => '\d+'],
    )]
    public function delete(int $templateId, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }
        if (!$this->templateManager->canManage()) {
            throw new ContentBlocksAccessDeniedException();
        }

        $template = $this->em->find(SectionTemplate::class, $templateId);
        if (!$template) {
            return new JsonResponse(['error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($template);
        $this->em->flush();

        return new JsonResponse(['deleted' => true]);
    }

    /**
     * Block-type identifiers used by a template that are no longer registered.
     *
     * @return list<string>
     */
    private function missingTypes(SectionTemplate $template): array
    {
        return array_values(array_filter(
            $template->getBlockTypes(),
            fn (string $type) => !$this->blockTypeRegistry->has($type),
        ));
    }

    private function readName(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);
        $raw = is_array($payload) ? ($payload['name'] ?? null) : null;
        if (!is_string($raw)) {
            return null;
        }
        $name = trim($raw);
        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    private function nextPreviewPosition(ContentArea $area): int
    {
        $max = -1;
        foreach ($area->getSections() as $section) {
            $max = max($max, $section->getPreviewPosition());
        }

        return $max + 1;
    }
}
