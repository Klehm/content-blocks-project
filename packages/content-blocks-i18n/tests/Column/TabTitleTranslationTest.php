<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Column;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Field\FieldStatus;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Lifecycle\TranslationCloneObserver;
use ContentBlocks\I18n\Lifecycle\TranslationPublisher;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\MachineTranslator;
use ContentBlocks\I18n\Machine\TranslationJob;
use ContentBlocks\I18n\Machine\TranslationOutcome;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Machine\TranslationRequest;
use ContentBlocks\I18n\Progress\BlockTranslationView;
use ContentBlocks\I18n\Progress\ColumnTranslationView;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Rendering\TranslationColumnSettingsResolver;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\I18n\Transfer\TranslationTransferExtension;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Section\SectionCloner;
use ContentBlocks\Section\SectionStyle;
use ContentBlocks\Section\SectionStyleProviderInterface;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Transfer\AssetRewriter;
use ContentBlocks\Transfer\AssetTokenizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

/**
 * A column's tab title, translated beside it: every place a block's
 * translation goes, the title goes too.
 */
final class TabTitleTranslationTest extends TestCase
{
    /** @var list<ColumnTranslation> what the repository double holds */
    private array $rows = [];

    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    // ---------- render ----------

    public function testThePublicPageReadsThePublishedTitleThePreviewTheDraft(): void
    {
        $column = $this->column(10, 'Détails');
        $row = new ColumnTranslation($column, 'en');
        $row->setPublishedPayload(['label' => 'Details'], []);
        $row->setDraftValue('label', 'Specs', 'x');
        $this->rows = [$row];

        $resolver = $this->resolver('en');

        self::assertSame(
            ['label' => 'Details'],
            $resolver->resolve($column, RenderContext::forPublic(), ['label' => 'Détails']),
        );
        self::assertSame(
            ['label' => 'Specs'],
            $resolver->resolve($column, RenderContext::forPreview(), ['label' => 'Détails']),
        );
    }

    /** An empty tab cannot be clicked: blank falls back like missing. */
    public function testABlankTranslationFallsBackToTheSource(): void
    {
        $column = $this->column(10, 'Détails');
        $row = new ColumnTranslation($column, 'en');
        $row->setPublishedPayload(['label' => '  '], []);
        $this->rows = [$row];

        self::assertSame(
            ['label' => 'Détails'],
            $this->resolver('en')->resolve($column, RenderContext::forPublic(), ['label' => 'Détails']),
        );
    }

    public function testWithNoLocaleOrNoSourceTitleNothingChanges(): void
    {
        $column = $this->column(10, 'Détails');
        $row = new ColumnTranslation($column, 'en');
        $row->setPublishedPayload(['label' => 'Details'], []);
        $this->rows = [$row];

        self::assertSame(['label' => 'Détails'], $this->resolver(null)->resolve($column, RenderContext::forPublic(), ['label' => 'Détails']));
        // An untitled column renders its number; a translation cannot name it.
        self::assertSame([], $this->resolver('en')->resolve($column, RenderContext::forPublic(), []));
    }

    // ---------- write ----------

    public function testTheWriterAcceptsTheLabelOnlyAndStampsTheSource(): void
    {
        $column = $this->column(10, 'Détails');

        $result = $this->writer()->writeColumn($column, 'en', ['label' => ' Details ', 'onclick' => 'x']);

        self::assertSame(['label'], $result->written);
        self::assertSame(['onclick' => 'not_translatable'], $result->rejected);
        $row = $this->persisted[0];
        self::assertInstanceOf(ColumnTranslation::class, $row);
        self::assertSame(['label' => 'Details'], $row->getDraftValues());
        self::assertSame(['label' => SourceDigest::of('Détails')], $row->getDraftDigests());
    }

    public function testTheWriterRefusesAnUnknownLocaleAndAnUntitledColumn(): void
    {
        self::assertSame(
            ['label' => 'unknown_locale'],
            $this->writer()->writeColumn($this->column(10, 'Détails'), 'it', ['label' => 'x'])->rejected,
        );
        self::assertSame(
            ['label' => 'unknown_path'],
            $this->writer()->writeColumn($this->column(11, null), 'en', ['label' => 'x'])->rejected,
        );
        self::assertSame([], $this->persisted);
    }

    public function testApprovingRestampsAnOutdatedTitle(): void
    {
        $column = $this->column(10, 'Détails');
        $row = new ColumnTranslation($column, 'en');
        $row->setDraftPayload(['label' => 'Details'], ['label' => SourceDigest::of('Ancien')]);
        $this->rows = [$row];

        $result = $this->writer()->markColumnUpToDate($column, 'en', ['label']);

        self::assertSame(['label'], $result->written);
        self::assertSame(SourceDigest::of('Détails'), $row->getDraftDigests()['label']);
    }

    // ---------- inspect ----------

    /** A title only a tabs section shows is work; a grid's is not. */
    public function testTheInspectorListsTitlesOfTabsSectionsBeforeTheirBlocks(): void
    {
        $area = new ContentArea();
        Entities::id($area, 1);
        $tabs = $this->section($area, 100, 0, ['display' => 'tabs']);
        $named = $this->column(10, 'Détails', $tabs, 0);
        $named->addBlock(Entities::block(1, draft: ['heading' => 'Bonjour', 'body' => '', 'align' => 'a', 'items' => []]));
        $this->column(11, null, $tabs, 1);
        $grid = $this->section($area, 200, 1, []);
        $this->column(20, 'Invisible', $grid, 0);

        $row = new ColumnTranslation($named, 'en');
        $row->setDraftPayload(['label' => 'Details'], ['label' => SourceDigest::of('Autre')]);
        $this->rows = [$row];

        $views = $this->inspector()->inspectArea($area, 'en');

        self::assertCount(2, $views);
        self::assertInstanceOf(ColumnTranslationView::class, $views[0]);
        self::assertInstanceOf(BlockTranslationView::class, $views[1]);
        self::assertSame('column-10', $views[0]->toArray()['key']);
        self::assertSame(FieldStatus::OUTDATED, $views[0]->fields[0]->status);
        self::assertSame('Détails', $views[0]->fields[0]->source);
        self::assertSame(1, $views[0]->columnNumber);
        self::assertSame(1, $this->inspector()->progressForArea($area, 'en')->outdated);
        self::assertNull($this->inspector()->inspectColumn($grid->getColumns()->first(), 'en'));
    }

    /** An accordion shows its titles as headers: they are work too. */
    public function testTheInspectorListsAccordionPanelTitles(): void
    {
        $area = new ContentArea();
        Entities::id($area, 1);
        $section = $this->section($area, 100, 0, ['display' => 'accordion']);
        $column = $this->column(10, 'Livraison', $section, 0);

        $views = $this->inspector()->inspectArea($area, 'en');

        self::assertCount(1, $views);
        self::assertInstanceOf(ColumnTranslationView::class, $views[0]);
        self::assertSame('cb_i18n.workbench.panel_title', $views[0]->fields[0]->label);
        self::assertSame('Livraison', $views[0]->fields[0]->source);
        self::assertStringContainsString('accordion', $views[0]->toArray()['groupLabel']);
        self::assertNotNull($this->inspector()->inspectColumn($column, 'en'));
    }

    public function testATabsDisplayInheritedFromAStylePresetCounts(): void
    {
        $area = new ContentArea();
        Entities::id($area, 1);
        $section = $this->section($area, 100, 0, ['styleName' => 'tabbed']);
        $column = $this->column(10, 'Détails', $section, 0);
        $styles = new SectionStyleRegistry([new class () implements SectionStyleProviderInterface {
            public function getStyles(): array
            {
                return [new SectionStyle('tabbed', 'Tabbed', '', ['display' => 'tabs'])];
            }
        }]);

        self::assertNotNull($this->inspector($styles)->inspectColumn($column, 'en'));
        self::assertNull($this->inspector()->inspectColumn($column, 'en'));
    }

    // ---------- lifecycle ----------

    public function testPublishPromotesRowsAndDropsThoseOfDeletedColumns(): void
    {
        $kept = $this->column(10, 'Détails');
        $gone = $this->column(11, 'Supprimée');
        $gone->setDeleted(true);
        $this->rows = [$this->draftRow($kept, 'Details'), $this->draftRow($gone, 'Deleted')];

        $this->publisher()->publish(new ContentArea());

        self::assertSame(['label' => 'Details'], $this->rows[0]->getPublishedValues());
        self::assertSame([$this->rows[1]], $this->removed);
    }

    public function testDiscardRevertsPublishedColumnsAndDropsNewOnes(): void
    {
        $published = $this->column(10, 'Détails');
        $published->publish();
        $fresh = $this->column(11, 'Nouvelle');
        $row = $this->draftRow($published, 'Details');
        $row->setPublishedPayload(['label' => 'Live'], []);
        $this->rows = [$row, $this->draftRow($fresh, 'New')];

        $this->publisher()->discardDraft(new ContentArea());

        self::assertFalse($row->hasUnpublishedChanges());
        self::assertSame([$this->rows[1]], $this->removed);
    }

    public function testADuplicatedSectionCarriesItsTitlesIntoTheCopysDraft(): void
    {
        $section = new Section();
        $column = $this->column(10, 'Détails', $section, 0);
        $row = new ColumnTranslation($column, 'en');
        $row->setPublishedPayload(['label' => 'Details'], ['label' => 'd']);
        $this->rows = [$row];

        $observer = new TranslationCloneObserver(
            $this->createMock(BlockTranslationRepository::class),
            $this->em(),
            $this->columnRepository(),
        );
        $copy = (new SectionCloner(null, new \ContentBlocks\Section\ColumnCloneObserverCollection([$observer])))
            ->cloneSection($section);

        $translation = $this->persisted[0];
        self::assertInstanceOf(ColumnTranslation::class, $translation);
        self::assertSame($copy->getColumns()->first(), $translation->getColumn());
        self::assertSame(['label' => 'Details'], $translation->getDraftValues());
        self::assertNull($translation->getPublishedValues());
    }

    // ---------- transfer ----------

    public function testExportAndImportCarryTitlesByPosition(): void
    {
        $area = new ContentArea();
        Entities::id($area, 1);
        $section = $this->section($area, 100, 0, ['display' => 'tabs']);
        $this->column(10, 'A', $section, 0);
        $second = $this->column(11, 'B', $section, 1);
        $this->rows = [$this->draftRow($second, 'Bee')];

        $fragment = $this->transfer()->export($area, [], $this->tokenizer());

        self::assertSame(['s0.c1' => ['en' => ['values' => ['label' => 'Bee'], 'digests' => ['label' => 'x']]]], $fragment['columns']);

        // The importer places the n-th column at preview position n.
        $target = new ContentArea();
        $imported = $this->section($target, 0, 0, []);
        $this->column(0, 'A', $imported, 0);
        $landing = $this->column(0, 'B', $imported, 1);
        $fragment['columns']['s0.c1']['en']['values']['script'] = '<x>';
        $fragment['columns']['s9.c9'] = ['en' => ['values' => ['label' => 'Nowhere']]];

        $this->transfer()->import($target, [], $fragment, new AssetRewriter([]));

        self::assertCount(1, $this->persisted);
        self::assertSame($landing, $this->persisted[0]->getColumn());
        self::assertSame(['label' => 'Bee'], $this->persisted[0]->getDraftValues());
    }

    // ---------- machine translation ----------

    public function testTheMachineRunTranslatesTitlesUnderTheirOwnRefs(): void
    {
        $area = new ContentArea();
        Entities::id($area, 1);
        $section = $this->section($area, 100, 0, ['display' => 'tabs']);
        $this->column(10, 'Détails', $section, 0);

        $provider = new class () implements TranslationProviderInterface {
            /** @var list<TranslationRequest> */
            public array $received = [];

            public static function getName(): string
            {
                return 'recording';
            }

            public function getLabel(): string
            {
                return 'Recording';
            }

            public function supports(string $sourceLocale, string $targetLocale): bool
            {
                return true;
            }

            public function translate(array $requests, TranslationJob $job): array
            {
                $this->received = $requests;

                return array_map(
                    static fn (TranslationRequest $r) => TranslationOutcome::success($r->path, '[' . $job->targetLocale . '] ' . $r->text),
                    $requests,
                );
            }
        };
        $result = $this->machine($provider)->translateArea($area, 'en');

        self::assertSame(['column-10#label'], array_map(static fn ($r) => $r->path, $provider->received));
        self::assertNull($provider->received[0]->blockType);
        self::assertSame(['column-10#label'], $result->translated);
        self::assertSame(['label' => '[en] Détails'], $this->persisted[0]->getDraftValues());
    }

    // ---------- plumbing ----------

    private function column(int $id, ?string $label, ?Section $section = null, int $position = 0): Column
    {
        $column = new Column();
        if ($id !== 0) {
            Entities::id($column, $id);
        }
        $column->setPreviewPosition($position);
        if ($label !== null) {
            $column->setDraftSettings(['label' => $label]);
        }
        $section?->addColumn($column);

        return $column;
    }

    /** @param array<string, mixed> $settings */
    private function section(ContentArea $area, int $id, int $position, array $settings): Section
    {
        $section = new Section();
        if ($id !== 0) {
            Entities::id($section, $id);
        }
        $section->setPreviewPosition($position);
        $section->setDraftSettings($settings === [] ? null : $settings);
        $area->addSection($section);

        return $section;
    }

    private function draftRow(Column $column, string $label): ColumnTranslation
    {
        $row = new ColumnTranslation($column, 'en');
        $row->setDraftPayload(['label' => $label], ['label' => 'x']);

        return $row;
    }

    private function columnRepository(): ColumnTranslationRepository
    {
        $repository = $this->createMock(ColumnTranslationRepository::class);
        $repository->method('findForArea')->willReturnCallback(fn () => $this->rows);
        $repository->method('findForColumnIds')->willReturnCallback(fn () => $this->rows);
        $repository->method('findOneFor')->willReturnCallback(
            fn (Column $column, string $locale) => array_values(array_filter(
                $this->rows,
                static fn (ColumnTranslation $r) => $r->getColumn() === $column && $r->getLocale() === $locale,
            ))[0] ?? null,
        );

        return $repository;
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            $this->persisted[] = $e;
        });
        $em->method('remove')->willReturnCallback(function (object $e): void {
            $this->removed[] = $e;
        });

        return $em;
    }

    private function store(): TranslationStore
    {
        $blocks = $this->createMock(BlockTranslationRepository::class);
        $blocks->method('findForArea')->willReturn([]);

        return new TranslationStore($blocks, $this->em(), $this->columnRepository());
    }

    private function resolver(?string $locale): TranslationColumnSettingsResolver
    {
        return new TranslationColumnSettingsResolver($this->store(), new class ($locale) implements RenderLocaleResolverInterface {
            public function __construct(private readonly ?string $locale)
            {
            }

            public function resolve(RenderContext $context): ?string
            {
                return $this->locale;
            }
        });
    }

    private function writer(?TranslationStore $store = null): TranslationWriter
    {
        return new TranslationWriter(
            $store ?? $this->store(),
            CatalogFactory::translatableFields(),
            new TranslationLocales('fr', ['en']),
        );
    }

    private function inspector(?SectionStyleRegistry $styles = null, ?TranslationStore $store = null): TranslationInspector
    {
        return new TranslationInspector(
            $store ?? $this->store(),
            CatalogFactory::create(),
            new TranslationLocales('fr', ['en']),
            CatalogFactory::registry(),
            new Translator('fr'),
            $styles,
        );
    }

    private function publisher(): TranslationPublisher
    {
        $blocks = $this->createMock(BlockTranslationRepository::class);
        $blocks->method('findForArea')->willReturn([]);

        return new TranslationPublisher(
            $this->createMock(ContentAreaPublisherInterface::class),
            $blocks,
            $this->store(),
            $this->em(),
            $this->columnRepository(),
        );
    }

    private function transfer(): TranslationTransferExtension
    {
        return new TranslationTransferExtension(
            $this->createMock(BlockTranslationRepository::class),
            $this->em(),
            $this->columnRepository(),
        );
    }

    private function machine(TranslationProviderInterface $provider): MachineTranslator
    {
        $store = $this->store();
        $locales = new TranslationLocales('fr', ['en']);

        return new MachineTranslator(
            $this->inspector(null, $store),
            $this->writer($store),
            new TranslationProviderRegistry([$provider], 'recording'),
            $locales,
            new Translator('fr'),
        );
    }

    private function tokenizer(): AssetTokenizer
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturn(false);

        return new AssetTokenizer(new AssetReferenceCollector($resolver), $resolver);
    }
}
