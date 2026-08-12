<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Machine;

use ContentBlocks\I18n\Machine\DeepLTranslationProvider;
use ContentBlocks\I18n\Machine\NullTranslationProvider;
use ContentBlocks\I18n\Machine\TranslationJob;
use ContentBlocks\I18n\Machine\TranslationOutcome;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Machine\TranslationRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeepLTranslationProviderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private function client(array $responses): MockHttpClient
    {
        $this->sent = [];

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = [
                'url' => $url,
                'body' => json_decode($options['body'] ?? '{}', true),
            ];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });
    }

    private function ok(array $texts): MockResponse
    {
        return new MockResponse(
            json_encode(['translations' => array_map(static fn (string $t): array => ['text' => $t], $texts)]),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /** @param list<TranslationOutcome> $outcomes */
    private function byPath(array $outcomes): array
    {
        $out = [];

        foreach ($outcomes as $outcome) {
            $out[$outcome->path] = $outcome;
        }

        return $out;
    }

    public function testTranslatesABatchAndMatchesResultsBackToTheirPaths(): void
    {
        $provider = new DeepLTranslationProvider($this->client([$this->ok(['Bonjour', 'Au revoir'])]), 'key');

        $outcomes = $this->byPath($provider->translate([
            new TranslationRequest('a', 'Hello'),
            new TranslationRequest('b', 'Goodbye'),
        ], new TranslationJob('en', 'fr')));

        $this->assertSame('Bonjour', $outcomes['a']->text);
        $this->assertSame('Au revoir', $outcomes['b']->text);
        $this->assertCount(1, $this->sent);
    }

    public function testHtmlAndPlainTextGoInSeparateCalls(): void
    {
        // `tag_handling` is per-request, not per-string: mixing them would
        // either escape the markup or translate the tag names.
        $provider = new DeepLTranslationProvider($this->client([
            $this->ok(['Bonjour']),
            $this->ok(['<p>Salut</p>']),
        ]), 'key');

        $outcomes = $this->byPath($provider->translate([
            new TranslationRequest('a', 'Hello'),
            new TranslationRequest('b', '<p>Hi</p>', TranslationRequest::FORMAT_HTML),
        ], new TranslationJob('en', 'fr')));

        $this->assertCount(2, $this->sent);
        $this->assertArrayNotHasKey('tag_handling', $this->sent[0]['body']);
        $this->assertSame('html', $this->sent[1]['body']['tag_handling']);
        $this->assertSame('<p>Salut</p>', $outcomes['b']->text);
    }

    public function testLocaleCodesAreMappedToDeepLsSpelling(): void
    {
        $provider = new DeepLTranslationProvider($this->client([$this->ok(['Olá'])]), 'key');

        $provider->translate([new TranslationRequest('a', 'Hello')], new TranslationJob('en', 'pt_BR'));

        $this->assertSame('PT-BR', $this->sent[0]['body']['target_lang']);
        // English as a *source* must be the bare code; DeepL rejects EN-GB there.
        $this->assertSame('EN', $this->sent[0]['body']['source_lang']);
    }

    public function testAConfiguredLocaleMapOverridesTheMechanicalMapping(): void
    {
        $provider = new DeepLTranslationProvider(
            $this->client([$this->ok(['Hallo'])]),
            'key',
            localeMap: ['de_AT' => 'DE'],
        );

        $provider->translate([new TranslationRequest('a', 'Hello')], new TranslationJob('en', 'de_AT'));

        $this->assertSame('DE', $this->sent[0]['body']['target_lang']);
    }

    public function testAFreeTierKeyRoutesToTheFreeHost(): void
    {
        // Getting this wrong returns a 403 that reads like an authentication
        // problem, so it is derived rather than asked for.
        $provider = new DeepLTranslationProvider($this->client([$this->ok(['Bonjour'])]), 'abc:fx');
        $provider->translate([new TranslationRequest('a', 'Hello')], new TranslationJob('en', 'fr'));

        $this->assertStringContainsString('api-free.deepl.com', $this->sent[0]['url']);
    }

    public function testAnApiErrorBecomesPerRequestFailuresRatherThanAnException(): void
    {
        $provider = new DeepLTranslationProvider($this->client([
            new MockResponse(json_encode(['message' => 'Quota exceeded']), [
                'http_code' => 456,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ]), 'key');

        $outcomes = $this->byPath($provider->translate([
            new TranslationRequest('a', 'Hello'),
            new TranslationRequest('b', 'Goodbye'),
        ], new TranslationJob('en', 'fr')));

        $this->assertFalse($outcomes['a']->isSuccess());
        $this->assertSame('Quota exceeded', $outcomes['a']->error);
        $this->assertFalse($outcomes['b']->isSuccess());
    }

    public function testAShortResponseFailsOnlyTheUnansweredRequests(): void
    {
        $provider = new DeepLTranslationProvider($this->client([$this->ok(['Bonjour'])]), 'key');

        $outcomes = $this->byPath($provider->translate([
            new TranslationRequest('a', 'Hello'),
            new TranslationRequest('b', 'Goodbye'),
        ], new TranslationJob('en', 'fr')));

        $this->assertTrue($outcomes['a']->isSuccess());
        $this->assertSame('missing_translation', $outcomes['b']->error);
    }

    public function testTheNullProviderExplainsItselfInsteadOfThrowing(): void
    {
        // An unconfigured installation must look unconfigured, not broken.
        $outcomes = (new NullTranslationProvider())->translate(
            [new TranslationRequest('a', 'Hello')],
            new TranslationJob('en', 'fr'),
        );

        $this->assertFalse($outcomes[0]->isSuccess());
        $this->assertSame('no_provider_configured', $outcomes[0]->error);
    }

    public function testTheRegistryFallsBackToTheNullProviderWhenNothingIsWired(): void
    {
        $registry = new TranslationProviderRegistry([]);

        $this->assertInstanceOf(NullTranslationProvider::class, $registry->getDefault());
    }

    public function testTheRegistryUsesTheOnlyProviderWhenNoDefaultIsNamed(): void
    {
        $deepl = new DeepLTranslationProvider(new MockHttpClient(), 'key');
        $registry = new TranslationProviderRegistry([$deepl]);

        $this->assertSame($deepl, $registry->getDefault());
        $this->assertSame(['deepl'], $registry->names());
    }

    public function testAskingForAnUnknownProviderNamesTheOnesThatExist(): void
    {
        $registry = new TranslationProviderRegistry([new NullTranslationProvider()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Registered: null/');

        $registry->get('deepl');
    }
}
