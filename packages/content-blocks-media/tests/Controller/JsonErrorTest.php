<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Controller;

use ContentBlocks\Media\Controller\JsonError;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class JsonErrorTest extends TestCase
{
    // The code is for a script, in every language; the message is for people.
    public function testTheCodeStaysAndTheMessageIsTranslated(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')
            ->with('cb_media.error.not_in_library', [], 'content_blocks_media')
            ->willReturn("Ce fichier n'est plus dans la bibliothèque.");

        $response = JsonError::response($translator, 'not_in_library', 404);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'not_in_library', 'message' => "Ce fichier n'est plus dans la bibliothèque."],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testEveryCodeTheControllersUseHasAMessage(): void
    {
        $codes = [];
        foreach (glob(\dirname(__DIR__, 2) . '/src/Controller/*Controller.php') ?: [] as $file) {
            preg_match_all("/->error\\('([a-z_]+)'/", (string) file_get_contents($file), $found);
            $codes = [...$codes, ...$found[1]];
        }
        $codes = array_values(array_unique($codes));
        $this->assertNotEmpty($codes);

        foreach (['en', 'fr'] as $locale) {
            $yaml = (string) file_get_contents(\dirname(__DIR__, 2) . "/translations/content_blocks_media.{$locale}.yaml");
            foreach ($codes as $code) {
                $this->assertMatchesRegularExpression("/^        {$code}: /m", $yaml, "{$locale} lacks cb_media.error.{$code}");
            }
        }
    }
}
