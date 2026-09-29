<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Rendering\PrefetchingBlockRenderer;
use ContentBlocks\I18n\Rendering\TranslationBlockDataResolver;
use ContentBlocks\I18n\Rendering\TranslationColumnSettingsResolver;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * `fallbacks: { fr_CA: [fr] }`: an untranslated fr_CA field reads fr before
 * the source, per field, and without the config nothing changes.
 */
final class FallbackChainTest extends TestCase
{
    /** @var list<BlockTranslation> */
    private array $rows = [];

    /** @var list<ColumnTranslation> */
    private array $columnRows = [];

    /** @var list<string|null> locales findForArea() was asked for */
    private array $prefetched = [];

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'heading' => 'Welcome',
            'body' => 'We ship worldwide.',
            'items' => [['_id' => 'aa11', 'label' => 'Fast delivery', 'url' => '/d', 'src' => '']],
        ];
    }

    public function testAnUntranslatedFieldReadsTheFallbackBeforeTheSource(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'fr_CA', ['heading' => 'Bienvenue, tabarnak']);
        $this->published($block, 'fr', ['heading' => 'Bienvenue', 'body' => 'Livraison partout.']);

        $data = $this->blockResolver('fr_CA', ['fr_CA' => ['fr']])
            ->resolve($block, RenderContext::forPublic(), $this->source());

        $this->assertSame('Bienvenue, tabarnak', $data['heading']);
        $this->assertSame('Livraison partout.', $data['body']);
        $this->assertSame('Fast delivery', $data['items'][0]['label']);
    }

    public function testWithoutTheConfigAnUntranslatedFieldShowsTheSource(): void
    {
        // The 1.x promise: a host with both fr and fr_CA sees the same page.
        $block = Entities::block(1);
        $this->published($block, 'fr', ['body' => 'Livraison partout.']);

        $data = $this->blockResolver('fr_CA', [])
            ->resolve($block, RenderContext::forPublic(), $this->source());

        $this->assertSame('We ship worldwide.', $data['body']);
    }

    public function testTheChainIsWalkedInOrder(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'es', ['body' => 'Enviamos a todo el mundo.', 'heading' => 'Bienvenido']);
        $this->published($block, 'pt_PT', ['heading' => 'Bem-vindo']);

        $data = $this->blockResolver('pt_BR', ['pt_BR' => ['pt_PT', 'es']])
            ->resolve($block, RenderContext::forPublic(), $this->source());

        $this->assertSame('Bem-vindo', $data['heading']);
        $this->assertSame('Enviamos a todo el mundo.', $data['body']);
    }

    public function testTheChainIsNotFollowedTransitively(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'es', ['body' => 'Enviamos a todo el mundo.']);

        $data = $this->blockResolver('pt_BR', ['pt_BR' => ['pt_PT'], 'pt_PT' => ['es']])
            ->resolve($block, RenderContext::forPublic(), $this->source());

        $this->assertSame('We ship worldwide.', $data['body']);
    }

    public function testThePublicPageReadsOnlyThePublishedFallback(): void
    {
        $block = Entities::block(1);
        $row = new BlockTranslation($block, 'fr');
        $row->setDraftPayload(['body' => 'Brouillon.'], []);
        $this->rows[] = $row;

        $resolver = $this->blockResolver('fr_CA', ['fr_CA' => ['fr']]);

        $this->assertSame(
            'We ship worldwide.',
            $resolver->resolve($block, RenderContext::forPublic(), $this->source())['body'],
        );
        $this->assertSame(
            'Brouillon.',
            $resolver->resolve($block, RenderContext::forPreview(), $this->source())['body'],
        );
    }

    public function testAFallbackThatIsNotATargetIsSkipped(): void
    {
        // A locale provider can drop a language the config still names.
        $block = Entities::block(1);
        $this->published($block, 'it', ['body' => 'Spediamo ovunque.']);

        $data = $this->blockResolver('fr_CA', ['fr_CA' => ['it']])
            ->resolve($block, RenderContext::forPublic(), $this->source());

        $this->assertSame('We ship worldwide.', $data['body']);
    }

    public function testATabTitleFallsBackAlongTheChain(): void
    {
        $column = $this->column(10, 'Details');
        $blank = new ColumnTranslation($column, 'fr_CA');
        $blank->setPublishedPayload(['label' => ' '], []);
        $fr = new ColumnTranslation($column, 'fr');
        $fr->setPublishedPayload(['label' => 'Détails'], []);
        $this->columnRows = [$blank, $fr];

        $this->assertSame(
            ['label' => 'Détails'],
            $this->columnResolver('fr_CA', ['fr_CA' => ['fr']])
                ->resolve($column, RenderContext::forPublic(), ['label' => 'Details']),
        );
        $this->assertSame(
            ['label' => 'Details'],
            $this->columnResolver('fr_CA', [])
                ->resolve($column, RenderContext::forPublic(), ['label' => 'Details']),
        );
    }

    public function testThePrefetchWarmsEveryLocaleOfTheChain(): void
    {
        $inner = $this->createMock(BlockRendererInterface::class);
        $inner->method('render')->willReturn('');

        $renderer = new PrefetchingBlockRenderer(
            $inner,
            $this->store(),
            $this->localeResolver('pt_BR'),
            $this->locales(['pt_BR' => ['pt_PT', 'es']]),
        );
        $renderer->render(Entities::area(1, Entities::block(1)));

        $this->assertSame(['pt_BR', 'pt_PT', 'es'], $this->prefetched);
    }

    // ---------- doubles ----------

    /** @param array<string, string> $values */
    private function published(Block $block, string $locale, array $values): void
    {
        $row = new BlockTranslation($block, $locale);
        $row->setPublishedPayload($values, []);
        $this->rows[] = $row;
    }

    private function column(int $id, string $label): Column
    {
        $column = new Column();
        Entities::id($column, $id);
        $column->setDraftSettings(['label' => $label]);

        return $column;
    }

    /** @param array<string, list<string>> $fallbacks */
    private function locales(array $fallbacks): TranslationLocales
    {
        return new TranslationLocales('en', ['fr', 'fr_CA', 'es', 'pt_PT', 'pt_BR'], [], $fallbacks);
    }

    private function localeResolver(string $locale): RenderLocaleResolverInterface
    {
        return new class ($locale) implements RenderLocaleResolverInterface {
            public function __construct(private readonly string $locale)
            {
            }

            public function resolve(RenderContext $context): ?string
            {
                return $this->locale;
            }
        };
    }

    private function store(): TranslationStore
    {
        $blocks = $this->createMock(BlockTranslationRepository::class);
        $blocks->method('findOneFor')->willReturnCallback(
            fn (Block $block, string $locale) => array_values(array_filter(
                $this->rows,
                static fn (BlockTranslation $r) => $r->getBlock() === $block && $r->getLocale() === $locale,
            ))[0] ?? null,
        );
        $blocks->method('findForArea')->willReturnCallback(function (ContentArea $area, ?string $locale): array {
            $this->prefetched[] = $locale;

            return [];
        });

        $columns = $this->createMock(ColumnTranslationRepository::class);
        $columns->method('findOneFor')->willReturnCallback(
            fn (Column $column, string $locale) => array_values(array_filter(
                $this->columnRows,
                static fn (ColumnTranslation $r) => $r->getColumn() === $column && $r->getLocale() === $locale,
            ))[0] ?? null,
        );
        $columns->method('findForArea')->willReturn([]);

        return new TranslationStore($blocks, $this->createMock(EntityManagerInterface::class), $columns);
    }

    /** @param array<string, list<string>> $fallbacks */
    private function blockResolver(string $locale, array $fallbacks): TranslationBlockDataResolver
    {
        return new TranslationBlockDataResolver(
            $this->store(),
            $this->localeResolver($locale),
            CatalogFactory::translatableFields(),
            $this->locales($fallbacks),
        );
    }

    /** @param array<string, list<string>> $fallbacks */
    private function columnResolver(string $locale, array $fallbacks): TranslationColumnSettingsResolver
    {
        return new TranslationColumnSettingsResolver(
            $this->store(),
            $this->localeResolver($locale),
            $this->locales($fallbacks),
        );
    }
}
