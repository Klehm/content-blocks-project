<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Rendering\FallbackLanguageDecorator;
use ContentBlocks\I18n\Rendering\FallbackLanguageMarker;
use ContentBlocks\I18n\Rendering\TranslationBlockDataResolver;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Rendering\RenderContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * `fallback_lang`: text shown in another language than the page's carries
 * that language, per field or once for the whole block.
 */
final class FallbackLanguageTest extends TestCase
{
    /** @var list<BlockTranslation> */
    private array $rows = [];

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'heading' => 'Welcome',
            'body' => 'We ship worldwide.',
            'items' => [['_id' => 'aa11', 'label' => 'Fast delivery', 'url' => '/d', 'src' => '']],
        ];
    }

    public function testAnUntranslatedBlockIsMarkedOnceWithTheSource(): void
    {
        $data = $this->resolve('fr');

        $this->assertSame('en', $data['_block_lang']);
        $this->assertArrayNotHasKey('_lang', $data);
        $this->assertArrayNotHasKey('_lang', $data['items'][0]);
    }

    public function testAPartlyTranslatedBlockMarksEachFieldThatFellBack(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'fr', ['heading' => 'Bienvenue']);

        $data = $this->resolve('fr', $block);

        $this->assertArrayNotHasKey('_block_lang', $data);
        $this->assertSame(['body' => 'en'], $data['_lang']);
        // The entry carries its own mark; a URL is not prose, so it has none.
        $this->assertSame(['label' => 'en'], $data['items'][0]['_lang']);
    }

    public function testAFullyTranslatedBlockIsLeftAlone(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'fr', [
            'heading' => 'Bienvenue',
            'body' => 'Livraison partout.',
            'items[aa11].label' => 'Livraison rapide',
        ]);

        $data = $this->resolve('fr', $block);

        $this->assertArrayNotHasKey('_block_lang', $data);
        $this->assertArrayNotHasKey('_lang', $data);
        $this->assertArrayNotHasKey('_lang', $data['items'][0]);
    }

    public function testAFieldReadFromAFallbackLocaleCarriesThatLocale(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'fr_CA', ['heading' => 'Bienvenue, tabarnak']);
        $this->published($block, 'fr', ['body' => 'Livraison partout.']);

        $data = $this->resolve('fr_CA', $block, ['fr_CA' => ['fr']]);

        $this->assertSame(['body' => 'fr'], $data['_lang']);
        $this->assertSame(['label' => 'en'], $data['items'][0]['_lang']);
    }

    public function testABlockReadWhollyFromOneFallbackIsMarkedOnceWithIt(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'fr', [
            'heading' => 'Bienvenue',
            'body' => 'Livraison partout.',
            'items[aa11].label' => 'Livraison rapide',
        ]);

        $data = $this->resolve('fr_CA', $block, ['fr_CA' => ['fr']]);

        $this->assertSame('fr', $data['_block_lang']);
    }

    public function testALocaleIdBecomesALanguageTag(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'pt_PT', [
            'heading' => 'Bem-vindo',
            'body' => 'Enviamos para todo o mundo.',
            'items[aa11].label' => 'Entrega rápida',
        ]);

        $data = $this->resolve('pt_BR', $block, ['pt_BR' => ['pt_PT']]);

        $this->assertSame('pt-PT', $data['_block_lang']);
    }

    public function testABlankSourceFieldIsNotCounted(): void
    {
        $block = Entities::block(1);
        $this->published($block, 'fr', ['items[aa11].label' => 'Livraison rapide']);
        $source = ['body' => ''] + $this->source();

        $data = $this->resolve('fr', $block, [], $source);

        $this->assertSame(['heading' => 'en'], $data['_lang']);
    }

    public function testWithoutTheOptionNothingIsMarked(): void
    {
        $data = $this->resolver('fr', [], marker: false)
            ->resolve(Entities::block(1), RenderContext::forPublic(), $this->source());

        $this->assertSame($this->source(), $data);
    }

    public function testTheDecoratorPutsTheBlockLanguageOnTheWrapper(): void
    {
        $decorator = new FallbackLanguageDecorator();
        $block = Entities::block(1);

        $this->assertSame(['lang' => 'pt-PT'], $decorator->decorate(['_block_lang' => 'pt-PT'], $block)->attributes);
        $this->assertSame([], $decorator->decorate(['_block_lang' => '"><script>'], $block)->attributes);
        $this->assertSame([], $decorator->decorate(['heading' => 'Welcome'], $block)->attributes);
    }

    /**
     * @param array<string, list<string>> $fallbacks
     * @param array<string, mixed>|null   $source
     *
     * @return array<string, mixed>
     */
    private function resolve(string $locale, ?Block $block = null, array $fallbacks = [], ?array $source = null): array
    {
        return $this->resolver($locale, $fallbacks)
            ->resolve($block ?? Entities::block(1), RenderContext::forPublic(), $source ?? $this->source());
    }

    /** @param array<string, list<string>> $fallbacks */
    private function resolver(string $locale, array $fallbacks, bool $marker = true): TranslationBlockDataResolver
    {
        $locales = new TranslationLocales('en', ['fr', 'fr_CA', 'pt_PT', 'pt_BR'], [], $fallbacks);

        return new TranslationBlockDataResolver(
            $this->store(),
            new class ($locale) implements RenderLocaleResolverInterface {
                public function __construct(private readonly string $locale)
                {
                }

                public function resolve(RenderContext $context): ?string
                {
                    return $this->locale;
                }
            },
            CatalogFactory::translatableFields(),
            $locales,
            $marker
                ? new FallbackLanguageMarker(CatalogFactory::translatableFields(), CatalogFactory::metadata(), $locales)
                : null,
        );
    }

    /** @param array<string, string> $values */
    private function published(Block $block, string $locale, array $values): void
    {
        $row = new BlockTranslation($block, $locale);
        $row->setPublishedPayload($values, []);
        $this->rows[] = $row;
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
        $blocks->method('findForArea')->willReturn([]);

        $columns = $this->createMock(ColumnTranslationRepository::class);
        $columns->method('findOneFor')->willReturnCallback(static fn (Column $c, string $l) => null);
        $columns->method('findForArea')->willReturn([]);

        return new TranslationStore($blocks, $this->createMock(EntityManagerInterface::class), $columns);
    }
}
