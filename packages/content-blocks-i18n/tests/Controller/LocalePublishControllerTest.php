<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Event\ActionRefusedException;
use ContentBlocks\I18n\Controller\LocalePublishController;
use ContentBlocks\I18n\Lifecycle\TranslationDrafts;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\PublishContext;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * One language live from the workbench. The guard is the point: publish and
 * discard act on the page's own draft too, so one pending refuses the call.
 */
final class LocalePublishControllerTest extends TestCase
{
    public function testPublishesTheOneLanguageOnly(): void
    {
        $publisher = new RecordingPublisher();

        $response = $this->controller($this->cleanArea(), $publisher)->publish(7, 'de', new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['pending' => false], json_decode((string) $response->getContent(), true));
        $this->assertSame([['publish', ['de']]], $publisher->calls);
    }

    public function testDiscardsTheOneLanguageOnly(): void
    {
        $publisher = new RecordingPublisher();

        $this->controller($this->cleanArea(), $publisher)->discard(7, 'de', new Request());

        $this->assertSame([['discard', ['de']]], $publisher->calls);
    }

    // It would put an editor's unfinished draft live, or throw it away.
    public function testRefusedWhileThePageHasADraftOfItsOwn(): void
    {
        $publisher = new RecordingPublisher();
        $area = Entities::area(7, Entities::block(1, draft: ['heading' => 'wip']));

        foreach (['publish', 'discard'] as $action) {
            $response = $this->controller($area, $publisher)->{$action}(7, 'de', new Request());

            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('source_unpublished', json_decode((string) $response->getContent(), true)['error']);
        }
        $this->assertSame([], $publisher->calls);
    }

    public function testARefusingListenerIsA409WithItsReason(): void
    {
        $publisher = new RecordingPublisher(refuse: 'Frozen until Monday');

        $response = $this->controller($this->cleanArea(), $publisher)->publish(7, 'de', new Request());

        $this->assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('refused', $body['error']);
        $this->assertSame(['Frozen until Monday'], $body['reasons']);
    }

    public function testTheGuardsBeforeAnything(): void
    {
        $publisher = new RecordingPublisher();

        $this->assertSame(403, $this->controller($this->cleanArea(), $publisher, csrfValid: false)
            ->publish(7, 'de', new Request())->getStatusCode());
        $this->assertSame(404, $this->controller($this->cleanArea(), $publisher)
            ->publish(8, 'de', new Request())->getStatusCode());
        $this->assertSame(404, $this->controller($this->cleanArea(), $publisher)
            ->publish(7, 'it', new Request())->getStatusCode());
        $this->assertSame([], $publisher->calls);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $this->controller($this->cleanArea(), $publisher, new DenyAllAccessChecker())->publish(7, 'de', new Request());
    }

    private function cleanArea(): ContentArea
    {
        $block = Entities::block(1, draft: ['heading' => 'Live']);
        $area = Entities::area(7, $block);
        foreach ($area->getSections() as $section) {
            $section->publish();
            foreach ($section->getColumns() as $column) {
                $column->publish();
            }
        }
        $block->publish();
        \assert(!$area->hasUnpublishedChanges());

        return $area;
    }

    private function controller(
        ContentArea $area,
        ContentAreaPublisherInterface $publisher,
        ?AccessCheckerInterface $accessChecker = null,
        bool $csrfValid = true,
    ): LocalePublishController {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?ContentArea => $id === 7 ? $area : null,
        );
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $repository = $this->createStub(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturn([]);

        return new LocalePublishController(
            $em,
            $accessChecker ?? new AllowAllAccessChecker(),
            $publisher,
            new TranslationDrafts($repository),
            new TranslationLocales('fr', ['en', 'de']),
            $csrf,
        );
    }
}

final class RecordingPublisher implements ContentAreaPublisherInterface
{
    /** @var list<array{0: string, 1: list<string>|null}> */
    public array $calls = [];

    public function __construct(private readonly ?string $refuse = null)
    {
    }

    public function publish(ContentArea $area, ?PublishContext $context = null): void
    {
        $this->record('publish', $context);
    }

    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
    {
        $this->record('discard', $context);
    }

    private function record(string $action, ?PublishContext $context): void
    {
        if ($this->refuse !== null) {
            throw new ActionRefusedException([$this->refuse]);
        }
        $this->calls[] = [$action, $context?->locales];
    }
}
