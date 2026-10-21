<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Command;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Ai\GeneratedContentWriter;
use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Clipboard\BlockDataReplayer;
use ContentBlocks\Clipboard\ClipboardPaster;
use ContentBlocks\Command\ApplyGeneratedCommand;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\Tests\Controller\AiFixtureBlock;
use ContentBlocks\Tests\Fixtures\FixedBuilderStructureResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class ApplyGeneratedCommandTest extends TestCase
{
    private string $file;
    private ContentArea $area;
    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'cb-gen') ?: '';
        $this->area = new ContentArea();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testADocumentIsWrittenToTheDraft(): void
    {
        $this->document(['sections' => [[
            'layout' => 'full',
            'columns' => [['preset' => 'col-12', 'blocks' => [
                ['type' => 'ai_fixture', 'data' => ['headline' => 'Hello']],
                ['type' => 'gone', 'data' => []],
            ]]],
        ]]]);

        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['area' => '1', 'file' => $this->file]));
        $this->assertCount(1, DraftOrder::sections($this->area));
        $this->assertSame(1, $this->flushes);
        $this->assertStringContainsString('Skipped unknown block types: gone', $tester->getDisplay());
    }

    public function testItIsSwitchedOffWithItsAction(): void
    {
        $this->document(['sections' => []]);
        $tester = $this->tester([AiAction::APPLY => false]);

        $this->assertSame(Command::FAILURE, $tester->execute(['area' => '1', 'file' => $this->file]));
        $this->assertStringContainsString('switched off', $tester->getDisplay());
        $this->assertSame(0, $this->flushes);
    }

    public function testARefusalWritesNothing(): void
    {
        $this->document(['sections' => []]);
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['area' => '1', 'file' => $this->file]));
        $this->assertStringContainsString('Refused: empty', $tester->getDisplay());
        $this->assertSame(0, $this->flushes);
    }

    public function testNotJsonIsInvalid(): void
    {
        file_put_contents($this->file, 'sections: yes');

        $this->assertSame(Command::INVALID, $this->tester()->execute(['area' => '1', 'file' => $this->file]));
    }

    public function testAnUnknownAreaFails(): void
    {
        $this->document(['sections' => []]);

        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute(['area' => '9', 'file' => $this->file]));
        $this->assertStringContainsString('No content area with id "9"', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $document
     */
    private function document(array $document): void
    {
        file_put_contents($this->file, json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, bool> $actions
     */
    private function tester(array $actions = []): CommandTester
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new AiFixtureBlock());
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->addType(new SectionSettingsType(new SectionStyleRegistry()))
            ->addType(new PaletteColorType())
            ->getFormFactory();
        $content = new ContentManipulator(null, $registry);
        $paster = new ClipboardPaster(
            new SectionTemplateInstantiator($registry, new BlockDataKeys($registry, $factory)),
            $registry,
            new BlockDataReplayer($registry, $factory, new BlockDataDefaults(), new CollectionItemIds()),
            $content,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            fn (string $class, int $id): ?object => $class === ContentArea::class && $id === 1 ? $this->area : null,
        );
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $writer = new GeneratedContentWriter(
            $paster,
            $content,
            new FixedBuilderStructureResolver(new BuilderStructure()),
            $factory,
        );

        return new CommandTester(new ApplyGeneratedCommand($em, $writer, new AiFeatures([], $actions)));
    }
}
