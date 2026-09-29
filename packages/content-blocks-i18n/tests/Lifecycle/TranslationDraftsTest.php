<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Lifecycle;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Lifecycle\TranslationDrafts;
use ContentBlocks\I18n\Lifecycle\TranslationUnpublishedChanges;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use PHPUnit\Framework\TestCase;

/**
 * What lights the builder's Publish button and the workbench's per-language
 * one when nothing but a translation changed.
 */
final class TranslationDraftsTest extends TestCase
{
    public function testADraftTranslationIsAPendingChange(): void
    {
        $block = Entities::block(1, draft: ['heading' => 'Welcome']);
        $drafts = $this->drafts([
            (new BlockTranslation($block, 'fr'))->setDraftValue('heading', 'Bienvenue', 'd1'),
        ]);
        $area = Entities::area(7, $block);

        $this->assertSame(['fr'], $drafts->pendingLocales($area));
        $this->assertTrue($drafts->hasPending($area, 'fr'));
        $this->assertTrue((new TranslationUnpublishedChanges($drafts))->hasUnpublishedChanges($area));
    }

    public function testAPublishedTranslationIsNot(): void
    {
        $block = Entities::block(1, draft: ['heading' => 'Welcome']);
        $row = (new BlockTranslation($block, 'fr'))->setDraftValue('heading', 'Bienvenue', 'd1');
        $row->publish();

        $this->assertSame([], $this->drafts([$row])->pendingLocales(Entities::area(7, $block)));
    }

    // Typed, then typed back: the button would light up for nothing.
    public function testADraftEqualToWhatIsLiveIsNot(): void
    {
        $block = Entities::block(1, draft: ['heading' => 'Welcome']);
        $row = (new BlockTranslation($block, 'fr'))->setPublishedPayload(['heading' => 'Bienvenue'], ['heading' => 'd1']);
        $row->setDraftValue('heading', 'Bienvenue', 'd1');

        $this->assertTrue($row->hasUnpublishedChanges());
        $this->assertFalse($this->drafts([$row])->any(Entities::area(7, $block)));
    }

    public function testAnUnsavedAreaHasNothingPending(): void
    {
        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->expects($this->never())->method('findForArea');

        $this->assertFalse((new TranslationDrafts($repository))->any(new ContentArea()));
    }

    /**
     * @param list<BlockTranslation> $rows
     */
    private function drafts(array $rows): TranslationDrafts
    {
        $repository = $this->createStub(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturnCallback(
            static fn ($area, ?string $locale = null): array => array_values(array_filter(
                $rows,
                static fn (BlockTranslation $row): bool => $locale === null || $row->getLocale() === $locale,
            )),
        );

        return new TranslationDrafts($repository);
    }
}
