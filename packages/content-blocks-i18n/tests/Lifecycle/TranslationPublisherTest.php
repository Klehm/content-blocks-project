<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Lifecycle;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Lifecycle\TranslationPublisher;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\PublishContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class TranslationPublisherTest extends TestCase
{
    public function testPublishPromotesEveryLocaleByDefault(): void
    {
        [$publisher, $rows] = $this->publisher(
            $block = Entities::block(1, published: ['title' => 'Hello']),
            ['fr' => 'Bonjour', 'de' => 'Hallo'],
        );

        $publisher->publish(Entities::area(1, $block));

        self::assertSame(['title' => 'Bonjour'], $rows['fr']->getPublishedValues());
        self::assertSame(['title' => 'Hallo'], $rows['de']->getPublishedValues());
        self::assertFalse($rows['fr']->hasUnpublishedChanges());
        self::assertFalse($rows['de']->hasUnpublishedChanges());
    }

    public function testPublishWithLocaleScopeLeavesOtherLocalesOnTheirPublishedValues(): void
    {
        [$publisher, $rows] = $this->publisher(
            $block = Entities::block(1, published: ['title' => 'Hello']),
            ['fr' => 'Bonjour', 'de' => 'Hallo'],
            ['de' => ['title' => 'Guten Tag']],
        );

        $publisher->publish(Entities::area(1, $block), PublishContext::withLocales('fr'));

        self::assertSame(['title' => 'Bonjour'], $rows['fr']->getPublishedValues());

        // German stays exactly where it was, and keeps its draft for later.
        self::assertSame(['title' => 'Guten Tag'], $rows['de']->getPublishedValues());
        self::assertTrue($rows['de']->hasUnpublishedChanges());
        self::assertSame(['title' => 'Hallo'], $rows['de']->getDraftValues());
    }

    public function testSourceOnlyHoldsEveryTranslationBack(): void
    {
        [$publisher, $rows] = $this->publisher(
            $block = Entities::block(1, published: ['title' => 'Hello']),
            ['fr' => 'Bonjour'],
        );

        $publisher->publish(Entities::area(1, $block), PublishContext::sourceOnly());

        self::assertNull($rows['fr']->getPublishedValues());
        self::assertTrue($rows['fr']->hasUnpublishedChanges());
    }

    public function testTheContextIsHandedToTheInnerPublisher(): void
    {
        $context = PublishContext::withLocales('fr');
        $inner = $this->createMock(ContentAreaPublisherInterface::class);
        $inner->expects(self::once())->method('publish')
            ->with(self::isInstanceOf(ContentArea::class), self::identicalTo($context));

        [$publisher] = $this->publisher($block = Entities::block(1, published: []), ['fr' => 'Bonjour'], inner: $inner);

        $publisher->publish(Entities::area(1, $block), $context);
    }

    public function testDiscardRevertsEveryLocaleByDefault(): void
    {
        [$publisher, $rows] = $this->publisher(
            $block = Entities::block(1, published: ['title' => 'Hello']),
            ['fr' => 'Bonjour', 'de' => 'Hallo'],
            ['fr' => ['title' => 'Salut'], 'de' => ['title' => 'Guten Tag']],
        );

        $publisher->discardDraft(Entities::area(1, $block));

        self::assertFalse($rows['fr']->hasUnpublishedChanges());
        self::assertFalse($rows['de']->hasUnpublishedChanges());
        self::assertSame(['title' => 'Salut'], $rows['fr']->getPublishedValues());
    }

    public function testDiscardWithLocaleScopeKeepsTheOtherLocalesDrafts(): void
    {
        [$publisher, $rows] = $this->publisher(
            $block = Entities::block(1, published: ['title' => 'Hello']),
            ['fr' => 'Bonjour', 'de' => 'Hallo'],
            ['fr' => ['title' => 'Salut'], 'de' => ['title' => 'Guten Tag']],
        );

        $publisher->discardDraft(Entities::area(1, $block), PublishContext::withLocales('de'));

        self::assertFalse($rows['de']->hasUnpublishedChanges());
        self::assertTrue($rows['fr']->hasUnpublishedChanges());
        self::assertSame(['title' => 'Bonjour'], $rows['fr']->getDraftValues());
    }

    public function testARowWhoseBlockIsGoneIsRemovedWhateverTheScope(): void
    {
        $block = Entities::block(1, published: ['title' => 'Hello']);
        $block->setDeleted(true);

        $removed = [];
        [$publisher, $rows] = $this->publisher($block, ['de' => 'Hallo'], removed: $removed);

        // 'de' is out of scope, yet the row still goes: the block it belonged
        // to is being deleted, so there is no locale left to hold back.
        $publisher->publish(Entities::area(1, $block), PublishContext::withLocales('fr'));

        self::assertSame([$rows['de']], $removed);
    }

    /**
     * @param array<string, string>              $drafts    locale => draft title
     * @param array<string, array<string, mixed>> $published locale => published values
     * @param list<object>                       $removed   collects em->remove() arguments
     *
     * @return array{0: TranslationPublisher, 1: array<string, BlockTranslation>}
     */
    private function publisher(
        Block $block,
        array $drafts,
        array $published = [],
        ?ContentAreaPublisherInterface $inner = null,
        array &$removed = [],
    ): array {
        $rows = [];
        foreach ($drafts as $locale => $title) {
            $row = new BlockTranslation($block, $locale);
            if (isset($published[$locale])) {
                $row->setPublishedPayload($published[$locale], ['title' => 'digest']);
            }
            $row->setDraftPayload(['title' => $title], ['title' => 'digest']);
            $rows[$locale] = $row;
        }

        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturn(array_values($rows));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        // TranslationStore is final; it is cheap to build for real, and its
        // reset() is the only method this collaboration touches.
        $store = new TranslationStore($repository, $em);

        return [
            new TranslationPublisher(
                $inner ?? $this->createMock(ContentAreaPublisherInterface::class),
                $repository,
                $store,
                $em,
            ),
            $rows,
        ];
    }
}
