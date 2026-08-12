<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Rendering\TranslationBlockDataResolver;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The package's entire render-time footprint. The invariant that matters most
 * is the first test: installed but unused, it must change nothing.
 */
final class TranslationBlockDataResolverTest extends TestCase
{
    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'heading' => 'Welcome',
            'body' => 'We ship worldwide.',
            'align' => 'center',
            'items' => [['_id' => 'aa11', 'label' => 'Fast delivery', 'url' => '/d', 'src' => '']],
        ];
    }

    private function resolver(?BlockTranslation $row, ?string $locale): TranslationBlockDataResolver
    {
        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findOneFor')->willReturn($row);

        $localeResolver = new class($locale) implements RenderLocaleResolverInterface {
            public function __construct(private readonly ?string $locale)
            {
            }

            public function resolve(RenderContext $context): ?string
            {
                return $this->locale;
            }
        };

        return new TranslationBlockDataResolver(
            new TranslationStore($repository, $this->createMock(EntityManagerInterface::class)),
            $localeResolver,
            CatalogFactory::translatableFields(),
        );
    }

    private function row(Block $block, array $values, string $locale = 'fr'): BlockTranslation
    {
        $row = new BlockTranslation($block, $locale);
        $row->setDraftPayload($values, []);

        return $row;
    }

    public function testWithNoLocaleResolvedThePayloadIsUntouched(): void
    {
        // An installation that has translated nothing renders byte-for-byte
        // what it did before the package existed.
        $block = Entities::block(1);
        $data = $this->source();

        $this->assertSame($data, $this->resolver(null, null)->resolve($block, RenderContext::forPublic(), $data));
    }

    public function testWithNoStoredRowThePayloadIsUntouched(): void
    {
        $block = Entities::block(1);
        $data = $this->source();

        $this->assertSame($data, $this->resolver(null, 'fr')->resolve($block, RenderContext::forPublic('fr'), $data));
    }

    public function testTranslatedFieldsAreMergedOverTheSource(): void
    {
        $block = Entities::block(1);
        $row = $this->row($block, ['heading' => 'Bienvenue', 'items[aa11].label' => 'Livraison rapide']);

        $data = $this->resolver($row, 'fr')->resolve($block, RenderContext::forPreview('fr'), $this->source());

        $this->assertSame('Bienvenue', $data['heading']);
        $this->assertSame('Livraison rapide', $data['items'][0]['label']);
    }

    public function testFallbackIsPerFieldNotPerBlock(): void
    {
        // A half-translated page must look incomplete, not broken — and
        // incremental translation has to show something before it is finished.
        $block = Entities::block(1);
        $row = $this->row($block, ['heading' => 'Bienvenue']);

        $data = $this->resolver($row, 'fr')->resolve($block, RenderContext::forPreview('fr'), $this->source());

        $this->assertSame('Bienvenue', $data['heading']);
        $this->assertSame('We ship worldwide.', $data['body']);
    }

    public function testADeliberateBlankRendersEmptyRatherThanFallingBack(): void
    {
        $block = Entities::block(1);
        $row = $this->row($block, ['body' => '']);

        $data = $this->resolver($row, 'fr')->resolve($block, RenderContext::forPreview('fr'), $this->source());

        $this->assertSame('', $data['body']);
    }

    public function testAStoredValueForAFieldNoLongerTaggedIsIgnored(): void
    {
        // Tags are code and rows are data; code changes. The current tags win,
        // so a field that stopped being translatable stops being overridden.
        $block = Entities::block(1);
        $row = $this->row($block, ['align' => 'start']);

        $data = $this->resolver($row, 'fr')->resolve($block, RenderContext::forPreview('fr'), $this->source());

        $this->assertSame('center', $data['align']);
    }

    public function testAStoredValueForADeletedCollectionEntryIsANoOp(): void
    {
        $block = Entities::block(1);
        $row = $this->row($block, ['items[gone].label' => 'Fantôme']);

        $data = $this->resolver($row, 'fr')->resolve($block, RenderContext::forPreview('fr'), $this->source());

        $this->assertCount(1, $data['items']);
        $this->assertSame('Fast delivery', $data['items'][0]['label']);
    }

    public function testPublicModeIgnoresAnUnpublishedTranslation(): void
    {
        // The rule that stops a French heading going live against an English
        // source that is still an unpublished draft.
        $block = Entities::block(1);
        $row = $this->row($block, ['heading' => 'Bienvenue']);

        $data = $this->resolver($row, 'fr')->resolve($block, new RenderContext(RenderMode::PUBLIC, 'fr'), $this->source());

        $this->assertSame('Welcome', $data['heading']);
    }

    public function testPublicModeUsesThePublishedTranslation(): void
    {
        $block = Entities::block(1);
        $row = new BlockTranslation($block, 'fr');
        $row->setPublishedPayload(['heading' => 'Bienvenue'], []);

        $data = $this->resolver($row, 'fr')->resolve($block, new RenderContext(RenderMode::PUBLIC, 'fr'), $this->source());

        $this->assertSame('Bienvenue', $data['heading']);
    }
}
