<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Command;

use ContentBlocks\Kit\Command\ListBlocksCommand;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ListBlocksCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id; // echo the message id — enough for output assertions
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        return new CommandTester(new ListBlocksCommand($translator));
    }

    public function testListsEveryBlockWithItsConfigurableSurface(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();

        // Every registered block type must appear as a section.
        foreach (array_keys(ContentBlocksKitBundle::BLOCKS) as $type) {
            $this->assertStringContainsString($type, $output, "Command output should mention block '$type'");
        }

        // The three configurable levers are documented, and a known choice value shows up.
        $this->assertStringContainsString('choices', $output);
        $this->assertStringContainsString('defaults', $output);
        $this->assertStringContainsString('primary', $output);
    }

    public function testSingleTypeArgumentNarrowsOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['type' => 'divider']);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();

        $this->assertStringContainsString('divider', $output);
        // A different block's unique choice value must not leak in.
        $this->assertStringNotContainsString('lightbulb', $output);
    }

    public function testUnknownTypeFailsWithInvalidStatus(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['type' => 'does-not-exist']);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('Unknown block type', $tester->getDisplay());
    }
}
