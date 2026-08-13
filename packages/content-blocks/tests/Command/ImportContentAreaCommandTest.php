<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Command;

use ContentBlocks\Command\ImportContentAreaCommand;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Test\ContentAreaBuilder;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use ContentBlocks\Transfer\ImportResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportContentAreaCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testImportsAndFlushes(): void
    {
        $area = ContentAreaBuilder::create()->withId(7)->build();
        $em = $this->makeEm($area);
        $em->expects($this->once())->method('flush');

        $importer = $this->createMock(ContentAreaImporterInterface::class);
        $importer->expects($this->once())
            ->method('import')
            ->with($area, ['format' => 'x', 'contentArea' => ['sections' => []]])
            ->willReturn(new ImportResult(3));

        $publisher = $this->createMock(ContentAreaPublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $tester = $this->runCommand($em, $importer, $publisher, [
            'area-id' => '7',
            'file' => $this->makeFile(['format' => 'x', 'contentArea' => ['sections' => []]]),
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Imported 3 section(s) into area #7', $tester->getDisplay());
    }

    public function testWithoutPublishTheOperatorIsToldTheContentIsDraftOnly(): void
    {
        // The whole trap this command exists to defuse: an imported area shows
        // up in the builder and leaves the public page empty.
        $tester = $this->runHappyPath();

        $this->assertStringContainsString('Written as draft', $tester->getDisplay());
        $this->assertStringContainsString('--publish', $tester->getDisplay());
    }

    public function testPublishOptionPublishesTheArea(): void
    {
        $area = ContentAreaBuilder::create()->withId(7)->build();
        $em = $this->makeEm($area);

        $publisher = $this->createMock(ContentAreaPublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->with($area);

        $tester = $this->runCommand($em, $this->makeImporter(new ImportResult(1)), $publisher, [
            'area-id' => '7',
            'file' => $this->makeFile(['contentArea' => ['sections' => []]]),
            '--publish' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('and published', $tester->getDisplay());
        $this->assertStringNotContainsString('Written as draft', $tester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $area = ContentAreaBuilder::create()->withId(7)->build();
        $em = $this->makeEm($area);
        $em->expects($this->never())->method('flush');
        $em->expects($this->once())->method('clear');

        $tester = $this->runCommand($em, $this->makeImporter(new ImportResult(2)), null, [
            'area-id' => '7',
            'file' => $this->makeFile(['contentArea' => ['sections' => []]]),
            '--dry-run' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('2 section(s) would be imported', $tester->getDisplay());
    }

    public function testDryRunAndPublishAreRefusedTogether(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('find');

        $tester = $this->runCommand($em, $this->createMock(ContentAreaImporterInterface::class), null, [
            'area-id' => '7',
            'file' => $this->makeFile([]),
            '--dry-run' => true,
            '--publish' => true,
        ]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('mutually exclusive', $tester->getDisplay());
    }

    public function testUnknownAreaFails(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $tester = $this->runCommand($em, $this->createMock(ContentAreaImporterInterface::class), null, [
            'area-id' => '404',
            'file' => $this->makeFile([]),
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No ContentArea with id 404', $tester->getDisplay());
    }

    public function testUnreadableFileFails(): void
    {
        $em = $this->makeEm(ContentAreaBuilder::create()->withId(7)->build());

        $tester = $this->runCommand($em, $this->createMock(ContentAreaImporterInterface::class), null, [
            'area-id' => '7',
            'file' => '/nonexistent/nowhere.json',
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Cannot read', $tester->getDisplay());
    }

    public function testMalformedJsonFails(): void
    {
        $em = $this->makeEm(ContentAreaBuilder::create()->withId(7)->build());
        $file = tempnam(sys_get_temp_dir(), 'cb-import-');
        $this->tempFiles[] = $file;
        file_put_contents($file, '{not json');

        $tester = $this->runCommand($em, $this->createMock(ContentAreaImporterInterface::class), null, [
            'area-id' => '7',
            'file' => $file,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid JSON', $tester->getDisplay());
    }

    public function testNonObjectPayloadFails(): void
    {
        $em = $this->makeEm(ContentAreaBuilder::create()->withId(7)->build());

        $tester = $this->runCommand($em, $this->createMock(ContentAreaImporterInterface::class), null, [
            'area-id' => '7',
            'file' => $this->makeFile('a bare string'),
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('expected a JSON object', $tester->getDisplay());
    }

    public function testARefusedEnvelopeIsReportedNotThrown(): void
    {
        // What a foreign format or an unbridgeable content version looks like.
        $em = $this->makeEm(ContentAreaBuilder::create()->withId(7)->build());
        $em->expects($this->never())->method('flush');

        $importer = $this->createMock(ContentAreaImporterInterface::class);
        $importer->method('import')->willThrowException(
            new \InvalidArgumentException('Unsupported payload format "nope".'),
        );

        $tester = $this->runCommand($em, $importer, null, [
            'area-id' => '7',
            'file' => $this->makeFile(['format' => 'nope']),
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unsupported payload format', $tester->getDisplay());
    }

    public function testSkippedBlocksAndKeptKeysAreReported(): void
    {
        $result = new ImportResult(
            1,
            2,
            ['exotic', 'legacy'],
            [['blockType' => 'text', 'unknownKeys' => ['subtitle', 'kicker']]],
        );

        $tester = $this->runCommand(
            $this->makeEm(ContentAreaBuilder::create()->withId(7)->build()),
            $this->makeImporter($result),
            null,
            ['area-id' => '7', 'file' => $this->makeFile(['contentArea' => ['sections' => []]])],
        );

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), 'warnings must not fail the import');
        $this->assertStringContainsString('Skipped 2 block(s)', $display);
        $this->assertStringContainsString('exotic, legacy', $display);
        $this->assertStringContainsString('text: subtitle, kicker', $display);
    }

    // ---------- helpers ----------

    private function runHappyPath(): CommandTester
    {
        return $this->runCommand(
            $this->makeEm(ContentAreaBuilder::create()->withId(7)->build()),
            $this->makeImporter(new ImportResult(1)),
            null,
            ['area-id' => '7', 'file' => $this->makeFile(['contentArea' => ['sections' => []]])],
        );
    }

    /** @param array<string, mixed> $input */
    private function runCommand(
        EntityManagerInterface $em,
        ContentAreaImporterInterface $importer,
        ?ContentAreaPublisherInterface $publisher,
        array $input,
    ): CommandTester {
        $command = new ImportContentAreaCommand(
            $em,
            $importer,
            $publisher ?? $this->createMock(ContentAreaPublisherInterface::class),
        );
        (new Application())->add($command);

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function makeEm(ContentArea $area): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($area);

        return $em;
    }

    private function makeImporter(ImportResult $result): ContentAreaImporterInterface
    {
        $importer = $this->createMock(ContentAreaImporterInterface::class);
        $importer->method('import')->willReturn($result);

        return $importer;
    }

    private function makeFile(mixed $payload): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cb-import-');
        $this->tempFiles[] = $file;
        file_put_contents($file, json_encode($payload, \JSON_THROW_ON_ERROR));

        return $file;
    }
}
