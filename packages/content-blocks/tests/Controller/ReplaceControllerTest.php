<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\ReplaceController;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Service\SectionCloner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit tests for the replace-with flow. candidates() is exercised only for
 * its guards: its happy path drives a Doctrine QueryBuilder/Query pair that
 * cannot be meaningfully doubled (Query is final) — that path is covered by
 * the Playwright replace-content suite against the real sandbox.
 */
final class ReplaceControllerTest extends ControllerTestCase
{
    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
    ): ReplaceController {
        return new ReplaceController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $this->createMock(ContentAreaProviderInterface::class),
            new SectionCloner(),
            $this->makeCsrfManager($csrfValid),
        );
    }

    // ---------- replaceWith ----------

    public function testReplaceWithSoftDeletesTargetSectionsAndClonesSource(): void
    {
        $target = $this->makeArea(1);
        $old = $this->makeSection($target, 10);

        $source = $this->makeArea(2);
        $sourceSection = $this->makeSection($source, 20, previewPosition: 0);
        $column = $this->makeColumn($sourceSection, 21);
        $block = $this->makeBlock($column, 22);
        $block->setDraftData(['content' => 'cloned']);

        $controller = $this->makeController($this->makeEm([$target, $source]));

        $response = $controller->replaceWith(1, 2, $this->makeJsonRequest());

        $this->assertTrue($old->isDeleted());
        $this->assertCount(1, $this->persisted);
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['replaced']);
        $this->assertSame(1, $payload['sectionCount']);
        // Source is untouched.
        $this->assertFalse($sourceSection->isDeleted());
    }

    public function testReplaceWithCopiesSourceSectionsInPreviewOrderSkippingDeleted(): void
    {
        $target = $this->makeArea(1);
        $source = $this->makeArea(2);
        $last = $this->makeSection($source, 20, previewPosition: 1);
        $first = $this->makeSection($source, 21, previewPosition: 0);
        $dead = $this->makeSection($source, 22, previewPosition: 2);
        $dead->setDeleted(true);
        $last->setDraftSettings(['marker' => 'last']);
        $first->setDraftSettings(['marker' => 'first']);

        $controller = $this->makeController($this->makeEm([$target, $source]));

        $response = $controller->replaceWith(1, 2, $this->makeJsonRequest());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(2, $payload['sectionCount']);
        // Clones land at dense positions preserving the source's draft order.
        $this->assertSame(['first', 'last'], array_map(
            fn ($s) => $s->getDraftSettings()['marker'],
            $this->persisted,
        ));
        $this->assertSame([0, 1], array_map(
            fn ($s) => $s->getPreviewPosition(),
            $this->persisted,
        ));
    }

    public function testReplaceWithItselfIsRejected(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->replaceWith(1, 1, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReplaceWithReturns404ForUnknownTargetOrSource(): void
    {
        $target = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$target]));

        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $controller->replaceWith(9, 1, $this->makeJsonRequest())->getStatusCode(),
        );
        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $controller->replaceWith(1, 9, $this->makeJsonRequest())->getStatusCode(),
        );
    }

    public function testReplaceWithRejectsInvalidCsrf(): void
    {
        $controller = $this->makeController($this->makeEm(), csrfValid: false);

        $response = $controller->replaceWith(1, 2, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testReplaceWithDeniesAnUnreadableSource(): void
    {
        // IDOR guard: the user can edit the target but must NOT be able to
        // copy content out of a source they cannot view.
        $target = $this->makeArea(1);
        $source = $this->makeArea(2);
        $checker = $this->createMock(AccessCheckerInterface::class);
        $checker->method('canEdit')->willReturn(true);
        $checker->method('canView')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$target, $source]), accessChecker: $checker);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->replaceWith(1, 2, $this->makeJsonRequest());
    }

    // ---------- candidates (guards only) ----------

    public function testCandidatesReturns404ForAnUnknownArea(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->candidates(9, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testCandidatesDeniesAccessWhenCheckerRefuses(): void
    {
        $area = $this->makeArea(1);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->candidates(1, $this->makeJsonRequest());
    }
}
