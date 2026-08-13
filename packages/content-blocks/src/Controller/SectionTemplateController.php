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
use ContentBlocks\SectionTemplate\SectionPosterBuilder;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiatorInterface;
use ContentBlocks\SectionTemplate\SectionTemplateManagerInterface;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException;
use ContentBlocks\Versioning\ContentVersionUpgraderInterface;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;
use ContentBlocks\Versioning\IncompatibleContentVersionException;
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
 *
 * @internal The routes are the contract, not this class. See FREEZE-AUDIT.md.
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
        private readonly SectionPosterBuilder $posterBuilder,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ContentVersionUpgraderInterface $versionUpgrader,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
        private readonly int $contentVersion = 1,
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

        $snapshot = $this->serializer->serialize($section);

        $template = (new SectionTemplate())
            ->setName($name)
            ->setPayload($snapshot->payload)
            ->setBlockTypes($snapshot->blockTypes)
            // A snapshot is frozen, so unlike an area's, this stamp keeps
            // describing its payload for as long as the row lives.
            ->setContentVersion($this->contentVersion);

        $this->em->persist($template);
        $this->em->flush();

        return new JsonResponse(['id' => $template->getId(), 'name' => $template->getName()]);
    }

    /**
     * Paginated, name-filtered list of library templates. Each entry is scored
     * against the current registry so the picker can warn (or refuse) up front
     * rather than surprising the editor on insert:
     *  - `skippedTypes` — block types this build no longer has. The template is
     *    still insertable, minus those blocks; the UI says so before the click.
     *  - `insertable` — false only when nothing would come in: an unreadable
     *    payload envelope, every one of its block types gone, or a schema
     *    generation the ContentVersionUpgrader will not accept.
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
            $readable = $this->hasReadableFormat($template);
            $declared = $template->getBlockTypes();
            $allGone = $declared !== [] && \count($missing) === \count($declared);
            $versionOk = $this->versionUpgrader->supports($template->getContentVersion(), $this->contentVersion);
            $items[] = [
                'id' => $template->getId(),
                'name' => $template->getName(),
                'insertable' => $readable && !$allGone && $versionOk,
                'skippedTypes' => $missing,
                'unreadableFormat' => !$readable,
                'staleVersion' => !$versionOk,
                'canManage' => $this->templateManager->canManage(),
                'createdAt' => $template->getCreatedAt()->format(\DateTimeInterface::ATOM),
                // Thumbnail spec, derived from the payload on every read rather
                // than stored: no column to migrate, and rows saved before this
                // existed get one too. Null when the payload holds no drawable
                // structure — the card then renders without a thumbnail.
                'poster' => $this->posterBuilder->build($template->getPayload()),
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
     * appended at the end. Blocks whose type is gone are skipped and reported;
     * the insert only aborts with 422 when nothing survives, or when the
     * payload envelope is unreadable.
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
            // Upgrading is transient: what comes back is instantiated, never
            // written back to the row. A permanent rewrite is a migration.
            $payload = $this->versionUpgrader->upgrade(
                $template->getPayload(),
                $template->getContentVersion(),
                $this->contentVersion,
            );
            $result = $this->instantiator->instantiate($payload);
        } catch (IncompatibleContentVersionException $e) {
            // Backstop: list() already rules these out, so reaching here means
            // a stale picker or a hand-crafted request.
            return new JsonResponse([
                'error' => 'incompatible_content_version',
                'storedVersion' => $e->getStoredVersion(),
                'currentVersion' => $e->getCurrentVersion(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (IncompatibleTemplateException $e) {
            return new JsonResponse([
                'error' => 'incompatible_template',
                'missingTypes' => $e->getMissingTypes(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnsupportedTemplateFormatException $e) {
            // Backstop: list() already greys these out, so reaching here means a
            // stale picker or a hand-crafted request.
            return new JsonResponse([
                'error' => 'unsupported_template_format',
                'found' => $e->getFound(),
                'expected' => $e->getExpected(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $section = $result->section;
        $section->setPreviewPosition($this->nextPreviewPosition($area));
        $area->addSection($section);
        $this->em->persist($section);
        $this->em->flush();

        return new JsonResponse([
            'sectionId' => $section->getId(),
            'skippedBlockCount' => $result->skippedBlockCount,
            'skippedBlockTypes' => $result->skippedBlockTypes,
            'unknownFields' => $result->unknownFields,
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

    /**
     * Whether the stored payload's envelope is one this build can read. Checked
     * here as well as in the instantiator so the picker greys the row out up
     * front instead of letting the editor click into a 422 — same treatment as
     * a missing block type.
     */
    private function hasReadableFormat(SectionTemplate $template): bool
    {
        $format = $template->getPayload()['format'] ?? null;

        // "Readable" includes formats the envelope chain can migrate forward,
        // not just today's — otherwise a format bump would grey out the whole
        // library even where a step exists to bridge it.
        return is_string($format)
            && $this->envelopes->supports($format, SectionTemplateSerializerInterface::FORMAT);
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
