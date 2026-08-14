<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Command;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Command\ListBlocksCommand;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;

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

        // An empty registry is the "no host config" case: the command falls
        // back to a bare instance of each block, i.e. the kit as shipped.
        return new CommandTester(new ListBlocksCommand($translator, new BlockTypeRegistry()));
    }

    /**
     * The txt output reports the block *as configured*, so a host can check an
     * override took — and says which fields were overridden, rather than
     * leaving them to spot the difference.
     */
    public function testConfiguredChoicesAreShownAndFlagged(): void
    {
        $translator = new class implements TranslatorInterface {
            use TranslatorTrait;
        };
        $registry = new BlockTypeRegistry();
        $registry->register(new ButtonBlock([], ['variant' => ['ghost' => 'Ghost', 'flat' => 'Flat']], []));

        $tester = new CommandTester(new ListBlocksCommand($translator, $registry));
        $tester->execute(['type' => 'button']);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();

        $this->assertStringContainsString('config applied', $output);
        $this->assertStringContainsString('variant', $output);
        $this->assertStringContainsString('ghost', $output);
        $this->assertStringNotContainsString('outline', $output, 'the replaced coded values are gone');
    }

    /**
     * The JSON half feeds the generated reference pages, which describe the kit
     * as shipped — so it must stay on the coded schema even when this app has
     * overridden it.
     */
    public function testJsonIgnoresHostConfigAndReportsTheCodedSet(): void
    {
        $translator = new class implements TranslatorInterface {
            use TranslatorTrait;
        };
        $registry = new BlockTypeRegistry();
        $registry->register(new ButtonBlock([], ['variant' => ['ghost' => 'Ghost']], []));

        $tester = new CommandTester(new ListBlocksCommand($translator, $registry));
        $tester->execute(['type' => 'button', '--format' => 'json']);

        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['primary', 'secondary', 'outline', 'link'],
            $decoded['button']['choices']['variant']['values'],
        );
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

    public function testJsonFormatEmitsEveryBlockWithItsSurface(): void
    {
        $tester = $this->tester();
        $tester->execute(['--format' => 'json']);

        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        // One entry per registered block, keyed by type.
        $this->assertSame(array_keys(ContentBlocksKitBundle::BLOCKS), array_keys($data));

        // A representative block carries label, flags, and the full surface.
        $title = $data['title'];
        $this->assertSame('title', $title['type']);
        $this->assertArrayHasKey('label', $title);
        $this->assertFalse($title['disabledByDefault']);
        // Choice fields are flattened to an ordered value list + explicit default.
        $this->assertSame(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], $title['choices']['size']['values']);
        $this->assertSame('h2', $title['choices']['size']['default']);
        $this->assertArrayHasKey('text', $title['defaults']);

        // The default-disabled block is flagged as such.
        $this->assertTrue($data['html_raw']['disabledByDefault']);
    }

    public function testJsonFormatRespectsSingleTypeArgument(): void
    {
        $tester = $this->tester();
        $tester->execute(['type' => 'button', '--format' => 'json']);

        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(['button'], array_keys($data));
    }

    public function testUnknownFormatFailsWithInvalidStatus(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['--format' => 'yaml']);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('Unknown format', $tester->getDisplay());
    }
}
