<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Publishing;

use ContentBlocks\Publishing\PublishContext;
use PHPUnit\Framework\TestCase;

final class PublishContextTest extends TestCase
{
    public function testEverythingCoversAnyLocale(): void
    {
        $context = PublishContext::everything();

        self::assertNull($context->locales);
        self::assertTrue($context->coversLocale('fr'));
        self::assertTrue($context->coversLocale('kl-GL'));
        self::assertTrue($context->coversAnyLocale());
    }

    public function testWithLocalesCoversOnlyTheNamedOnes(): void
    {
        $context = PublishContext::withLocales('fr', 'de');

        self::assertSame(['fr', 'de'], $context->locales);
        self::assertTrue($context->coversLocale('fr'));
        self::assertTrue($context->coversLocale('de'));
        self::assertFalse($context->coversLocale('es'));
        self::assertTrue($context->coversAnyLocale());
    }

    public function testWithLocalesIsCaseSensitive(): void
    {
        // Locales are matched against BlockTranslation::getLocale() verbatim;
        // normalizing here would silently disagree with the stored rows.
        self::assertFalse(PublishContext::withLocales('fr')->coversLocale('FR'));
        self::assertFalse(PublishContext::withLocales('pt-BR')->coversLocale('pt-br'));
    }

    public function testWithLocalesDeduplicatesAndStaysAList(): void
    {
        $context = PublishContext::withLocales('fr', 'de', 'fr');

        self::assertSame(['fr', 'de'], $context->locales);
    }

    public function testSourceOnlyCoversNoLocale(): void
    {
        $context = PublishContext::sourceOnly();

        self::assertSame([], $context->locales);
        self::assertFalse($context->coversLocale('fr'));
        self::assertFalse($context->coversAnyLocale());
    }

    public function testWithNoLocalesNamedIsSourceOnly(): void
    {
        // withLocales() with an empty argument list means "no locale", which is
        // sourceOnly() — never "all of them". The all-locales case is null, and
        // null is only reachable through everything() or by passing no context.
        self::assertSame([], PublishContext::withLocales()->locales);
        self::assertFalse(PublishContext::withLocales()->coversAnyLocale());
    }
}
