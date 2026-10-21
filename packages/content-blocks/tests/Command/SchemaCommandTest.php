<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Command;

use ContentBlocks\Command\SchemaCommand;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Schema\ContentSchema;
use ContentBlocks\Tests\Fixtures\MinimalSchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaCommandTest extends TestCase
{
    public function testItPrintsTheWholeDocumentAsJson(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $document = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(ContentSchema::FORMAT, $document['format']);
        $this->assertStringContainsString("\n    ", $tester->getDisplay(), 'pretty by default');
    }

    public function testOnlyPrintsOneKey(): void
    {
        $tester = $this->tester();

        $tester->execute(['--only' => 'structure', '--compact' => true]);

        $this->assertSame('{"sections":"editable","columns":true}', trim($tester->getDisplay()));
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute(['--only' => 'nope']));
        $this->assertStringContainsString('Unknown key "nope"', $tester->getDisplay());
    }

    public function testExamplesTakesANumber(): void
    {
        $this->assertSame(Command::INVALID, $this->tester()->execute(['--examples' => 'many']));
    }

    public function testAnUnknownAreaFails(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $tester = $this->tester($em);

        $this->assertSame(Command::FAILURE, $tester->execute(['--area' => '42']));
        $this->assertStringContainsString('No content area with id "42"', $tester->getDisplay());
    }

    public function testAKnownAreaIsLookedUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('find')->with(ContentArea::class, 7)->willReturn(new ContentArea());

        $this->assertSame(Command::SUCCESS, $this->tester($em)->execute(['--area' => '7']));
    }

    private function tester(?EntityManagerInterface $em = null): CommandTester
    {
        return new CommandTester(new SchemaCommand(MinimalSchemaBuilder::make(), $em));
    }
}
