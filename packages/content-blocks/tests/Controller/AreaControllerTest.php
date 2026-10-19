<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\AreaController;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Event\BeforeContentAreaDiscardEvent;
use ContentBlocks\Event\BeforeContentAreaPublishEvent;
use ContentBlocks\Event\RefusableEvent;
use ContentBlocks\Publishing\ContentAreaPublisher;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\EventDispatchingPublisher;
use ContentBlocks\Publishing\LocaleDraft;
use ContentBlocks\Publishing\LocaleDrafts;
use ContentBlocks\Publishing\LocaleDraftsProviderInterface;
use ContentBlocks\Publishing\PublishContext;
use ContentBlocks\Publishing\UnpublishedChanges;
use ContentBlocks\Publishing\UnpublishedChangesProviderInterface;
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
        ?UnpublishedChanges $changes = null,
        ?ContentAreaPublisherInterface $publisher = null,
        ?LocaleDrafts $localeDrafts = null,
    ): AreaController {
        $publisher ??= new ContentAreaPublisher($em);

        return new AreaController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $events === null ? $publisher : new EventDispatchingPublisher($publisher, $events),
            $this->makeCsrfManager($csrfValid),
            $changes ?? new UnpublishedChanges(),
            $localeDrafts ?? new LocaleDrafts(),
        );
    }

    // ---------- locale scope ----------

    // A body without the key is what every caller sent before it existed.
    public function testPublishWithoutLocalesCoversEverything(): void
    {
        $publisher = $this->recordingPublisher();
        $controller = $this->makeController($this->makeEm([$this->makeArea(1)]), publisher: $publisher);

        $controller->publish(1, $this->makeJsonRequest());

        $this->assertSame([['publish', null]], $publisher->calls);
    }

    public function testPublishCoversOnlyTheLocalesNamed(): void
    {
        $publisher = $this->recordingPublisher();
        $controller = $this->makeController($this->makeEm([$this->makeArea(1)]), publisher: $publisher);

        $controller->publish(1, $this->makeJsonRequest(['locales' => ['de', 'en']]));

        $this->assertSame([['publish', ['de', 'en']]], $publisher->calls);
    }

    // Every language unticked: the page goes live, its translations wait.
    public function testAnEmptyListPublishesTheSourceAlone(): void
    {
        $publisher = $this->recordingPublisher();
        $controller = $this->makeController($this->makeEm([$this->makeArea(1)]), publisher: $publisher);

        $controller->publish(1, $this->makeJsonRequest(['locales' => []]));

        $this->assertSame([['publish', []]], $publisher->calls);
    }

    public function testDiscardCoversOnlyTheLocalesNamed(): void
    {
        $publisher = $this->recordingPublisher();
        $controller = $this->makeController($this->makeEm([$this->makeArea(1)]), publisher: $publisher);

        $controller->discard(1, $this->makeJsonRequest(['locales' => ['fr']]));

        $this->assertSame([['discard', ['fr']]], $publisher->calls);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidLocales(): iterable
    {
        yield 'a string' => ['en'];
        yield 'a map' => [['en' => true]];
        yield 'a number inside' => [['en', 3]];
        yield 'a blank code' => [['']];
        yield 'null' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLocales')]
    public function testAMalformedLocaleListIsRefusedBeforeAnyWrite(mixed $locales): void
    {
        $publisher = $this->recordingPublisher();
        $controller = $this->makeController($this->makeEm([$this->makeArea(1)]), publisher: $publisher);

        $response = $controller->publish(1, $this->makeJsonRequest(['locales' => $locales]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(['error' => 'invalid_locales'], json_decode((string) $response->getContent(), true));
        $this->assertSame([], $publisher->calls);
    }

    public function testThePublishScopeListsEveryProvidersLocalesOnce(): void
    {
        $area = $this->makeArea(1);
        $drafts = new LocaleDrafts([
            $this->localeProvider(new LocaleDraft('fr', 'Français'), new LocaleDraft('de', 'Deutsch')),
            $this->localeProvider(new LocaleDraft('de', 'German'), new LocaleDraft('es', 'Español')),
        ]);
        $controller = $this->makeController($this->makeEm([$area]), localeDrafts: $drafts);

        $payload = json_decode((string) $controller->publishScope(1)->getContent(), true);

        $this->assertSame([
            'hasOwnDraft' => false,
            'locales' => [
                ['code' => 'fr', 'label' => 'Français'],
                ['code' => 'de', 'label' => 'Deutsch'],
                ['code' => 'es', 'label' => 'Español'],
            ],
        ], $payload);
    }

    public function testThePublishScopeSaysWhetherThePageItselfHasADraft(): void
    {
        $area = $this->makeArea(1);
        $block = $this->makeBlock($this->makeColumn($this->makeSection($area, 2), 3), 4);
        $block->setDraftData(['content' => 'wip']);
        $controller = $this->makeController($this->makeEm([$area]));

        $payload = json_decode((string) $controller->publishScope(1)->getContent(), true);

        $this->assertSame(['hasOwnDraft' => true, 'locales' => []], $payload);
    }

    public function testThePublishScopeDeniesAccessWhenCheckerRefuses(): void
    {
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$this->makeArea(1)]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->publishScope(1);
    }

    public function testThePublishScopeOfAnUnknownAreaIs404(): void
    {
        $controller = $this->makeController($this->makeEm());

        $this->assertSame(Response::HTTP_NOT_FOUND, $controller->publishScope(9)->getStatusCode());
    }

    private function localeProvider(LocaleDraft ...$drafts): LocaleDraftsProviderInterface
    {
        return new class (array_values($drafts)) implements LocaleDraftsProviderInterface {
            /** @param list<LocaleDraft> $drafts */
            public function __construct(private readonly array $drafts)
            {
            }

            public function pendingLocales(ContentArea $area): array
            {
                return $this->drafts;
            }
        };
    }

    private function recordingPublisher(): object
    {
        return new class () implements ContentAreaPublisherInterface {
            /** @var list<array{string, list<string>|null}> */
            public array $calls = [];

            public function publish(ContentArea $area, ?PublishContext $context = null): void
            {
                $this->calls[] = ['publish', $context?->locales];
            }

            public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
            {
                $this->calls[] = ['discard', $context?->locales];
            }
        };
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

    // A translation typed on a published page: the area itself is clean.
    public function testStateCountsADraftABundleKeepsBesideTheArea(): void
    {
        $area = $this->makeArea(1);
        $provider = new class () implements UnpublishedChangesProviderInterface {
            public function hasUnpublishedChanges(ContentArea $area): bool
            {
                return true;
            }
        };
        $controller = $this->makeController($this->makeEm([$area]), changes: new UnpublishedChanges([$provider]));

        $payload = json_decode((string) $controller->state(1)->getContent(), true);

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
