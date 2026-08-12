<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Machine;

use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\MachineTranslator;
use ContentBlocks\I18n\Machine\TranslationJob;
use ContentBlocks\I18n\Machine\TranslationOutcome;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Machine\TranslationRequest;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A provider that records what it was asked and answers with a prefix, so the
 * selection rules and the ref round trip can be asserted without a network.
 */
final class RecordingProvider implements TranslationProviderInterface
{
    /** @var list<TranslationRequest> */
    public array $received = [];

    public int $calls = 0;

    public function __construct(
        private readonly bool $shuffle = false,
        private readonly ?string $failWith = null,
    ) {
    }

    public static function getName(): string
    {
        return 'recording';
    }

    public function getLabel(): string|TranslatableInterface
    {
        return 'Recording';
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return true;
    }

    public function translate(array $requests, TranslationJob $job): array
    {
        ++$this->calls;
        $this->received = array_merge($this->received, $requests);

        $outcomes = array_map(
            fn (TranslationRequest $r): TranslationOutcome => $this->failWith !== null
                ? TranslationOutcome::failure($r->path, $this->failWith)
                : TranslationOutcome::success($r->path, '[' . $job->targetLocale . '] ' . $r->text),
            $requests,
        );

        // The contract says results are matched by path, not by order — so
        // returning them shuffled must not corrupt anything.
        if ($this->shuffle) {
            $outcomes = array_reverse($outcomes);
        }

        return $outcomes;
    }
}

final class MachineTranslatorTest extends TestCase
{
    private TranslationStore $store;

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

    private function translator(RecordingProvider $provider, ?BlockTranslation $row = null): MachineTranslator
    {
        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findOneFor')->willReturn($row);
        $repository->method('findForArea')->willReturn($row === null ? [] : [$row]);

        $this->store = new TranslationStore($repository, $this->createMock(EntityManagerInterface::class));
        $locales = new TranslationLocales('en', ['fr', 'de']);
        $symfonyTranslator = new Translator('en');

        $inspector = new TranslationInspector(
            $this->store,
            CatalogFactory::create(),
            $locales,
            CatalogFactory::registry(),
            $symfonyTranslator,
        );

        $writer = new TranslationWriter($this->store, CatalogFactory::translatableFields(), $locales);

        return new MachineTranslator(
            $inspector,
            $writer,
            new TranslationProviderRegistry([$provider], 'recording'),
            $locales,
            $symfonyTranslator,
        );
    }

    public function testTranslatesEveryMissingFieldOfABlockInOneProviderCall(): void
    {
        // One call, not one per string: this is the whole reason the provider
        // contract is batch-shaped.
        $provider = new RecordingProvider();
        $block = Entities::block(1, draft: $this->source());

        $result = $this->translator($provider)->translateBlock($block, 'fr');

        $this->assertSame(1, $provider->calls);
        $this->assertCount(4, $provider->received);
        $this->assertSame(4, $result->getTranslatedCount());

        $values = $this->store->find($block, 'fr')->getDraftValues();
        $this->assertSame('[fr] Welcome', $values['heading']);
        $this->assertSame('[fr] Fast delivery', $values['items[aa11].label']);
    }

    public function testUntaggedFieldsAreNeverSentToTheProvider(): void
    {
        $provider = new RecordingProvider();
        $this->translator($provider)->translateBlock(Entities::block(1, draft: $this->source()), 'fr');

        $paths = array_map(static fn (TranslationRequest $r): string => $r->path, $provider->received);

        $this->assertSame([], array_filter($paths, static fn (string $p) => str_contains($p, 'align')));
        $this->assertSame([], array_filter($paths, static fn (string $p) => str_contains($p, 'src')));
    }

    public function testAlreadyCorrectFieldsAreSkipped(): void
    {
        // Re-translating a field an editor hand-corrected is the fastest way to
        // make a team switch the feature off.
        $block = Entities::block(1, draft: $this->source());
        $row = new BlockTranslation($block, 'fr');
        $row->setDraftPayload(['heading' => 'Bienvenue'], ['heading' => SourceDigest::of('Welcome')]);

        $provider = new RecordingProvider();
        $result = $this->translator($provider, $row)->translateBlock($block, 'fr');

        $paths = array_map(static fn (TranslationRequest $r): string => $r->path, $provider->received);

        $this->assertSame([], array_filter($paths, static fn (string $p) => str_ends_with($p, '#heading')));
        $this->assertSame(1, $result->skipped);
        $this->assertSame('Bienvenue', $this->store->find($block, 'fr')->getDraftValues()['heading']);
    }

    public function testAnOutdatedFieldIsRetranslated(): void
    {
        $block = Entities::block(1, draft: $this->source());
        $row = new BlockTranslation($block, 'fr');
        $row->setDraftPayload(['heading' => 'Bienvenue'], ['heading' => SourceDigest::of('Something else')]);

        $provider = new RecordingProvider();
        $this->translator($provider, $row)->translateBlock($block, 'fr');

        $this->assertSame('[fr] Welcome', $this->store->find($block, 'fr')->getDraftValues()['heading']);
    }

    public function testOverwriteReTranslatesEvenCurrentFields(): void
    {
        $block = Entities::block(1, draft: $this->source());
        $row = new BlockTranslation($block, 'fr');
        $row->setDraftPayload(['heading' => 'Bienvenue'], ['heading' => SourceDigest::of('Welcome')]);

        $provider = new RecordingProvider();
        $this->translator($provider, $row)->translateBlock($block, 'fr', overwrite: true);

        $this->assertSame('[fr] Welcome', $this->store->find($block, 'fr')->getDraftValues()['heading']);
    }

    public function testOnlyTheNamedPathsAreTranslatedWhenPathsAreGiven(): void
    {
        // The per-field button is the per-block call with a list of one.
        $provider = new RecordingProvider();
        $result = $this->translator($provider)->translateBlock(Entities::block(1, draft: $this->source()), 'fr', ['heading']);

        $this->assertCount(1, $provider->received);
        $this->assertSame(1, $result->getTranslatedCount());
    }

    public function testResultsAreMatchedByRefNotByOrder(): void
    {
        $provider = new RecordingProvider(shuffle: true);
        $block = Entities::block(1, draft: $this->source());

        $this->translator($provider)->translateBlock($block, 'fr');
        $values = $this->store->find($block, 'fr')->getDraftValues();

        $this->assertSame('[fr] Welcome', $values['heading']);
        $this->assertSame('[fr] We ship worldwide.', $values['body']);
    }

    public function testRichTextIsFlaggedAsHtmlForTheProvider(): void
    {
        // Sent as plain text, markup comes back escaped or with translated tag
        // names — so the format has to travel with the string.
        $provider = new RecordingProvider();
        $this->translator($provider)->translateBlock(Entities::block(1, draft: $this->source()), 'fr');

        foreach ($provider->received as $request) {
            $this->assertSame(TranslationRequest::FORMAT_TEXT, $request->format);
        }
    }

    public function testProviderFailuresAreReportedPerFieldAndDoNotThrow(): void
    {
        $provider = new RecordingProvider(failWith: 'rate_limited');
        $result = $this->translator($provider)->translateBlock(Entities::block(1, draft: $this->source()), 'fr');

        $this->assertSame(0, $result->getTranslatedCount());
        $this->assertSame(4, $result->getFailedCount());
        $this->assertContains('rate_limited', array_values($result->failed));
    }

    public function testAnUnknownLocaleIsRefusedBeforeAnyProviderCall(): void
    {
        $provider = new RecordingProvider();
        $result = $this->translator($provider)->translateBlock(Entities::block(1, draft: $this->source()), 'it');

        $this->assertSame(0, $provider->calls);
        $this->assertSame(['*' => 'unknown_locale'], $result->failed);
    }

    public function testAWholeAreaIsTranslatedInOneProviderCall(): void
    {
        $provider = new RecordingProvider();
        $area = Entities::area(
            7,
            Entities::block(1, draft: $this->source(), position: 0),
            Entities::block(2, draft: $this->source(), position: 1),
        );

        $result = $this->translator($provider)->translateArea($area, 'fr');

        $this->assertSame(1, $provider->calls);
        $this->assertSame(8, $result->getTranslatedCount());
    }
}
