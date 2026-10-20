<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Twig\LanguageExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class LanguageExtensionTest extends TestCase
{
    public function testAMarkedFieldGetsItsLangAttribute(): void
    {
        $html = $this->render('<p{{ cb_lang(item, "label") }}>x</p>', [
            'item' => ['label' => 'Hello', '_lang' => ['label' => 'en-GB']],
        ]);

        $this->assertSame('<p lang="en-GB">x</p>', $html);
    }

    public function testAnUnmarkedFieldPrintsNothing(): void
    {
        $this->assertSame('<p>x</p>', $this->render('<p{{ cb_lang(item, "label") }}>x</p>', [
            'item' => ['label' => 'Hello', '_lang' => ['other' => 'en']],
        ]));
        $this->assertSame('<p>x</p>', $this->render('<p{{ cb_lang(item, "label") }}>x</p>', ['item' => null]));
    }

    /** `_lang` is data: a value that is not a language tag never lands. */
    public function testAValueThatIsNotALanguageTagIsDropped(): void
    {
        $html = $this->render('<p{{ cb_lang(item, "label") }}>x</p>', [
            'item' => ['_lang' => ['label' => 'en" onclick="alert(1)']],
        ]);

        $this->assertSame('<p>x</p>', $html);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): string
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['strict_variables' => true]);
        $twig->addExtension(new LanguageExtension());

        return $twig->render('t', $context);
    }
}
