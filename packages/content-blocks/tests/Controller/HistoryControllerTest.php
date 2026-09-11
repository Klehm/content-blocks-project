<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\HistoryController;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\BuilderSession;
use ContentBlocks\History\HistoryResult;
use ContentBlocks\History\JournalScope;
use ContentBlocks\History\SidebarOutcome;
use ContentBlocks\History\StateApplier;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\Tests\History\InMemoryActionLogStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class HistoryControllerTest extends ControllerTestCase
{
    private InMemoryActionLogStore $store;

    private function makeController(
        EntityManagerInterface $em,
        ContentArea $area,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
    ): HistoryController {
        $this->store = new InMemoryActionLogStore();

        return new HistoryController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $this->makeSessionJournal($em, $area),
            new SidebarOutcome($em),
            $this->makeCsrfManager($csrfValid),
        );
    }

    /** A journal over a real session, so the endpoints have a stack to walk. */
    private function makeSessionJournal(EntityManagerInterface $em, ContentArea $area): ActionJournal
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $journal = new ActionJournal($this->store, new StateApplier($em), new BuilderSession($stack));
        $this->journal = $journal;
        $this->area = $area;

        return $journal;
    }

    private ActionJournal $journal;
    private ContentArea $area;

    // ---------- undo ----------

    public function testUndoAppliesTheInverseOfTheLastAction(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$area, $section]), $area);

        $this->journal->record($area, 'section.delete', JournalScope::structure(), fn () => $section->setDeleted(true));

        $response = $controller->undo(1, $this->makeJsonRequest());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(HistoryResult::STATUS_OK, $payload['status']);
        $this->assertSame('section.delete', $payload['label']);
        $this->assertFalse($payload['canUndo']);
        $this->assertTrue($payload['canRedo']);
        $this->assertFalse($section->isDeleted());
    }

    /**
     * Not an error: the save-error banner is for a request that failed, and
     * "there is nothing left" is an answer.
     */
    public function testAnEmptyStackAnswers200WithAStatus(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), $area);

        $response = $controller->undo(1, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(HistoryResult::STATUS_NOTHING, $payload['status']);
    }

    public function testTheResponseCarriesTheDraftStateTheTopbarReads(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$area, $section]), $area);

        $payload = json_decode((string) $controller->undo(1, $this->makeJsonRequest())->getContent(), true);

        $this->assertArrayHasKey('hasUnpublishedChanges', $payload);
    }

    // ---------- what the open sidebar is told to do ----------

    public function testTheResponseRulesOnTheSidebarTheCallerDeclared(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$area, $section]), $area);

        $this->journal->record($area, 'section.delete', JournalScope::structure(), fn () => $section->setDeleted(true));

        $request = $this->makeJsonRequest(['open' => ['type' => 'section', 'id' => 2]]);
        $payload = json_decode((string) $controller->undo(1, $request)->getContent(), true);

        // The undo un-deleted it, so the form over it is alive again.
        $this->assertSame(SidebarOutcome::KEEP, $payload['sidebar']);
    }

    public function testAnUndoneCreationTellsTheSidebarToClose(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$area, $section]), $area);

        // Restoring it is what the undo will take back, leaving it deleted.
        $section->setDeleted(true);
        $this->journal->record($area, 'section.restore', JournalScope::structure(), fn () => $section->setDeleted(false));

        $request = $this->makeJsonRequest(['open' => ['type' => 'section', 'id' => 2]]);
        $payload = json_decode((string) $controller->undo(1, $request)->getContent(), true);

        $this->assertTrue($section->isDeleted(), 'guard: the undo put the delete back');
        $this->assertSame(SidebarOutcome::CLOSE, $payload['sidebar']);
    }

    /** A caller that declares nothing keeps whatever it has. */
    public function testAnUndeclaredSidebarIsLeftAlone(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$area, $section]), $area);

        $this->journal->record($area, 'section.delete', JournalScope::structure(), fn () => $section->setDeleted(true));

        $payload = json_decode((string) $controller->undo(1, $this->makeJsonRequest())->getContent(), true);

        $this->assertSame(SidebarOutcome::KEEP, $payload['sidebar']);
    }

    // ---------- redo ----------

    public function testRedoReappliesWhatUndoTookBack(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$area, $section]), $area);

        $this->journal->record($area, 'section.delete', JournalScope::structure(), fn () => $section->setDeleted(true));
        $controller->undo(1, $this->makeJsonRequest());

        $payload = json_decode((string) $controller->redo(1, $this->makeJsonRequest())->getContent(), true);

        $this->assertSame(HistoryResult::STATUS_OK, $payload['status']);
        $this->assertTrue($section->isDeleted());
    }

    // ---------- guards ----------

    public function testUndoRejectsAnInvalidCsrfToken(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), $area, csrfValid: false);

        $response = $controller->undo(1, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testUndoDeniesWhenTheAreaIsNotEditable(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), $area, accessChecker: new DenyAllAccessChecker());

        $this->expectException(ContentBlocksAccessDeniedException::class);

        $controller->undo(1, $this->makeJsonRequest());
    }

    public function testUndoIs404ForAMissingArea(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), $area);

        $response = $controller->undo(999, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRedoRejectsAnInvalidCsrfToken(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), $area, csrfValid: false);

        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->redo(1, $this->makeJsonRequest())->getStatusCode());
    }
}
