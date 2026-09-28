<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\AreaController;
use ContentBlocks\Event\BeforeContentAreaDiscardEvent;
use ContentBlocks\Event\BeforeContentAreaPublishEvent;
use ContentBlocks\Event\RefusableEvent;
use ContentBlocks\Publishing\ContentAreaPublisher;
use ContentBlocks\Publishing\EventDispatchingPublisher;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response;

final class AreaControllerTest extends ControllerTestCase
{
    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
        ?EventDispatcher $events = null,
    ): AreaController {
        $publisher = new ContentAreaPublisher($em);

        return new AreaController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $events === null ? $publisher : new EventDispatchingPublisher($publisher, $events),
            $this->makeCsrfManager($csrfValid),
        );
    }

    public function testPublishPromotesDraftsAndReportsCleanState(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $column = $this->makeColumn($section, 3);
        $block = $this->makeBlock($column, 4);
        $block->setDraftData(['content' => 'new']);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->publish(1, $this->makeJsonRequest());

        $this->assertSame(['content' => 'new'], $block->getPublishedData());
        $this->assertNull($block->getDraftData());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertFalse($payload['hasUnpublishedChanges']);
    }

    public function testARefusedPublishAnswers409WithTheReason(): void
    {
        $area = $this->makeArea(1);
        $block = $this->makeBlock($this->makeColumn($this->makeSection($area, 2), 3), 4);
        $block->setDraftData(['content' => 'new']);
        $controller = $this->makeController(
            $this->makeEm([$area]),
            events: $this->refusing(BeforeContentAreaPublishEvent::class),
        );

        $response = $controller->publish(1, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'refused', 'message' => 'Not today.', 'reasons' => ['Not today.']],
            json_decode((string) $response->getContent(), true),
        );
        $this->assertNull($block->getPublishedData());
        $this->assertSame(0, $this->flushCount);
    }

    public function testARefusedDiscardAnswers409AndKeepsTheDraft(): void
    {
        $area = $this->makeArea(1);
        $block = $this->makeBlock($this->makeColumn($this->makeSection($area, 2), 3), 4);
        $block->setDraftData(['content' => 'wip']);
        $controller = $this->makeController(
            $this->makeEm([$area]),
            events: $this->refusing(BeforeContentAreaDiscardEvent::class),
        );

        $response = $controller->discard(1, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame(['content' => 'wip'], $block->getDraftData());
    }

    /** @param class-string<RefusableEvent> $event */
    private function refusing(string $event): EventDispatcher
    {
        $events = new EventDispatcher();
        $events->addListener($event, fn (RefusableEvent $e) => $e->refuse('Not today.'));

        return $events;
    }

    public function testDiscardRevertsTheDraft(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $column = $this->makeColumn($section, 3);
        $block = $this->makeBlock($column, 4);
        $block->setDraftData(['content' => 'live']);
        $controller = $this->makeController($this->makeEm([$area]));

        // Publish first: discard drops never-published structures entirely;
        // reverting requires a published baseline to fall back to.
        $controller->publish(1, $this->makeJsonRequest());
        $block->setDraftData(['content' => 'wip']);

        $response = $controller->discard(1, $this->makeJsonRequest());

        $this->assertNull($block->getDraftData());
        $this->assertSame(['content' => 'live'], $block->getPublishedData());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertFalse($payload['hasUnpublishedChanges']);
    }

    public function testStateReportsPendingDraftChanges(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $column = $this->makeColumn($section, 3);
        $block = $this->makeBlock($column, 4);
        $block->setPublishedData(['content' => 'live']);
        $block->setDraftData(['content' => 'wip']);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->state(1);

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['hasUnpublishedChanges']);
    }

    public function testStateHandsOutTheSessionsCurrentCsrfToken(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $payload = json_decode((string) $controller->state(1)->getContent(), true);

        $this->assertSame('fresh-token', $payload['csrfToken']);
    }

    public function testPublishRejectsInvalidCsrf(): void
    {
        $controller = $this->makeController($this->makeEm(), csrfValid: false);

        $response = $controller->publish(1, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testPublishReturns404ForAnUnknownArea(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->publish(9, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testPublishDeniesWriteWhenAccessCheckerRefuses(): void
    {
        $area = $this->makeArea(1);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->publish(1, $this->makeJsonRequest());
    }

    public function testStateDeniesAccessWhenCheckerRefuses(): void
    {
        $area = $this->makeArea(1);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->state(1);
    }
}
