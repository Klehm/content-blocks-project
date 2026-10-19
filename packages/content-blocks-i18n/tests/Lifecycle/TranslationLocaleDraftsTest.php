<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Lifecycle;

use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Lifecycle\TranslationDrafts;
use ContentBlocks\I18n\Lifecycle\TranslationLocaleDrafts;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Publishing\LocaleDraft;
use PHPUnit\Framework\TestCase;

/**
 * What the builder's Publish and Discard dialog offers: each language holding
 * a pending translation, labelled, in the configured order.
 */
final class TranslationLocaleDraftsTest extends TestCase
{
    public function testOnlyLocalesWithAPendingDraftAreOffered(): void
    {
        $block = Entities::block(1, draft: ['heading' => 'Welcome']);
        $published = (new BlockTranslation($block, 'es'))->setDraftValue('heading', 'Hola', 'd1');
        $published->publish();
        $rows = [
            (new BlockTranslation($block, 'de'))->setDraftValue('heading', 'Willkommen', 'd1'),
            $published,
        ];

        $offered = $this->provider($rows)->pendingLocales(Entities::area(7, $block));

        $this->assertEquals([new LocaleDraft('de', 'Deutsch')], $offered);
    }

    // The order the host configured, not the order the rows were written.
    public function testLocalesFollowTheConfiguredOrder(): void
    {
        $block = Entities::block(1, draft: ['heading' => 'Welcome']);
        $rows = [
            (new BlockTranslation($block, 'de'))->setDraftValue('heading', 'Willkommen', 'd1'),
            (new BlockTranslation($block, 'fr'))->setDraftValue('heading', 'Bienvenue', 'd1'),
        ];

        $offered = $this->provider($rows)->pendingLocales(Entities::area(7, $block));

        $this->assertSame(['fr', 'de'], array_map(static fn (LocaleDraft $d): string => $d->code, $offered));
    }

    // Still offered, so its draft is not stranded; it just comes last.
    public function testALocaleNoLongerConfiguredComesLast(): void
    {
        $block = Entities::block(1, draft: ['heading' => 'Welcome']);
        $rows = [
            (new BlockTranslation($block, 'it'))->setDraftValue('heading', 'Benvenuto', 'd1'),
            (new BlockTranslation($block, 'de'))->setDraftValue('heading', 'Willkommen', 'd1'),
        ];

        $offered = $this->provider($rows)->pendingLocales(Entities::area(7, $block));

        $this->assertSame(['de', 'it'], array_map(static fn (LocaleDraft $d): string => $d->code, $offered));
    }

    /**
     * @param list<BlockTranslation> $rows
     */
    private function provider(array $rows): TranslationLocaleDrafts
    {
        $repository = $this->createStub(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturnCallback(
            static fn ($area, ?string $locale = null): array => array_values(array_filter(
                $rows,
                static fn (BlockTranslation $row): bool => $locale === null || $row->getLocale() === $locale,
            )),
        );

        return new TranslationLocaleDrafts(
            new TranslationDrafts($repository),
            new TranslationLocales('en', ['fr', 'de', 'es'], ['de' => 'Deutsch']),
        );
    }
}
