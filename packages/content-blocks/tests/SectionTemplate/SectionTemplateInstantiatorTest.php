<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\SectionTemplate;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\SectionTemplateSerializer;
use ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;

final class SectionTemplateInstantiatorTest extends TestCase
{
    /**
     * The instantiator reads the *built* block form to know which keys a block
     * can legitimately hold, so it needs a real factory. A bare one resolves
     * BlockFormType's children (StylingType & co) through their no-arg
     * constructors — same resolution BlockFormTypeTest relies on.
     */
    private function instantiator(?BlockFormExtensionCollection $extensions = null): SectionTemplateInstantiator
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType($extensions ?? new BlockFormExtensionCollection()))
            ->getFormFactory();
        $registry = $this->registry();

        return new SectionTemplateInstantiator($registry, new BlockDataKeys($registry, $factory));
    }

    public function testMissingCollectionIdsAreAdded(): void
    {
        $registry = \ContentBlocks\Tests\Block\CollectionIdBackfillerTest::registry();
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->getFormFactory();
        $instantiator = new SectionTemplateInstantiator(
            $registry,
            new BlockDataKeys($registry, $factory),
            collectionIds: \ContentBlocks\Tests\Block\CollectionIdBackfillerTest::backfillerFor($registry),
        );

        $section = $instantiator->instantiate($this->payload([
            ['preset' => 'col-12', 'blocks' => [['type' => 'grid', 'data' => ['rows' => [['cells' => [['content' => 'A']]]]]]]],
        ]))->section;

        $rows = $section->getColumns()[0]->getBlocks()[0]->getDraftData()['rows'];
        $this->assertIsString($rows[0]['_id'] ?? null);
        $this->assertIsString($rows[0]['cells'][0]['_id'] ?? null);
    }

    /** Wraps a global (`*`) extension the way the compiler pass would. */
    private function extensions(BlockFormExtensionInterface $extension): BlockFormExtensionCollection
    {
        return new BlockFormExtensionCollection([[$extension, ['*']]]);
    }

    private function registry(): BlockTypeRegistry
    {
        // The registry keys by the *static* getType(), so each fake type needs
        // its own class rather than a constructor-parameterized factory.
        $text = new class () extends AbstractBlockType {
            public function getType(): string
            {
                return 'text';
            }

            public function getLabel(): string
            {
                return 'Text';
            }

            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
            }

            public function getDefaultData(): array
            {
                return ['text' => ''];
            }
        };

        $title = new class () extends AbstractBlockType {
            public function getType(): string
            {
                return 'title';
            }

            public function getLabel(): string
            {
                return 'Title';
            }

            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
            }

            public function getDefaultData(): array
            {
                return ['text' => '', 'level' => 2];
            }
        };

        $registry = new BlockTypeRegistry();
        $registry->register($text);
        $registry->register($title);

        return $registry;
    }

    private function payload(array $columns): array
    {
        return [
            'format' => SectionTemplateSerializer::FORMAT,
            'layout' => Section::LAYOUT_TWO_COLS,
            'settings' => ['classes' => 'hero', 'anchorId' => 'intro'],
            'columns' => $columns,
        ];
    }

    public function testBuildsDetachedDraftSectionWithPositions(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-6', 'blocks' => [
                ['type' => 'text', 'data' => ['text' => 'A']],
                ['type' => 'title', 'data' => ['text' => 'B', 'level' => 3]],
            ]],
            ['preset' => 'col-6', 'blocks' => [
                ['type' => 'text', 'data' => ['text' => 'C']],
            ]],
        ]);

        $result = $this->instantiator()->instantiate($payload);
        $section = $result->section;

        $this->assertNull($section->getContentArea());
        $this->assertNull($section->getId());
        $this->assertSame(Section::LAYOUT_TWO_COLS, $section->getLayout());
        // A host key (`anchorId`) comes through untouched.
        $this->assertSame(['classes' => 'hero', 'anchorId' => 'intro'], $section->getDraftSettings());
        $this->assertFalse($result->hasWarnings());

        $columns = array_values($section->getColumns()->toArray());
        $this->assertCount(2, $columns);
        $this->assertSame('col-6', $columns[0]->getPreset());
        $this->assertSame(0, $columns[0]->getPreviewPosition());
        $this->assertSame(1, $columns[1]->getPreviewPosition());

        $blocks = array_values($columns[0]->getBlocks()->toArray());
        $this->assertSame('text', $blocks[0]->getType());
        $this->assertSame(['text' => 'A'], $blocks[0]->getDraftData());
        $this->assertSame(0, $blocks[0]->getPreviewPosition());
        $this->assertSame(1, $blocks[1]->getPreviewPosition());
        // Data lands in draft slots, never published.
        $this->assertNull($blocks[0]->getPublishedData());
    }

    public function testAnUnreadableEnvelopeIsAHardStop(): void
    {
        // The format versions the payload *structure* (which the core owns),
        // not the shape of the block data inside it. A snapshot written under
        // an older structure is refused rather than replayed blind.
        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [['type' => 'text', 'data' => ['text' => 'A']]]],
        ]);
        $payload['format'] = 'content-blocks/section-v99';

        try {
            $this->instantiator()->instantiate($payload);
            $this->fail('Expected UnsupportedTemplateFormatException.');
        } catch (UnsupportedTemplateFormatException $e) {
            $this->assertSame('content-blocks/section-v99', $e->getFound());
            $this->assertSame(SectionTemplateSerializer::FORMAT, $e->getExpected());
        }
    }

    public function testAMissingFormatIsAHardStopToo(): void
    {
        $payload = $this->payload([['preset' => 'col-12', 'blocks' => []]]);
        unset($payload['format']);

        try {
            $this->instantiator()->instantiate($payload);
            $this->fail('Expected UnsupportedTemplateFormatException.');
        } catch (UnsupportedTemplateFormatException $e) {
            $this->assertNull($e->getFound());
        }
    }

    public function testBlocksWhoseTypeIsGoneAreSkippedNotRefused(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'text', 'data' => ['text' => 'A']],
                ['type' => 'gallery', 'data' => []],
                ['type' => 'countdown', 'data' => []],
                ['type' => 'text', 'data' => ['text' => 'B']],
            ]],
        ]);

        $result = $this->instantiator()->instantiate($payload);

        $this->assertSame(2, $result->skippedBlockCount);
        $this->assertSame(['gallery', 'countdown'], $result->skippedBlockTypes);

        // The usable blocks came in, densely positioned — a skipped block must
        // not leave a hole in the sequence.
        $blocks = array_values($result->section->getColumns()->first()->getBlocks()->toArray());
        $this->assertCount(2, $blocks);
        $this->assertSame(['A', 'B'], array_map(fn ($b) => $b->getDraftData()['text'], $blocks));
        $this->assertSame([0, 1], array_map(fn ($b) => $b->getPreviewPosition(), $blocks));
    }

    public function testATemplateWhoseBlocksAreAllGoneIsRefused(): void
    {
        // Nothing left to insert: dropping an empty section into the area
        // would only puzzle the editor.
        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'gallery', 'data' => []],
                ['type' => 'countdown', 'data' => []],
            ]],
        ]);

        try {
            $this->instantiator()->instantiate($payload);
            $this->fail('Expected IncompatibleTemplateException.');
        } catch (IncompatibleTemplateException $e) {
            $this->assertSame(['gallery', 'countdown'], $e->getMissingTypes());
        }
    }

    public function testATemplateThatNeverHadBlocksStillInserts(): void
    {
        // A spacer section: no blocks to lose, so nothing to refuse.
        $result = $this->instantiator()->instantiate($this->payload([
            ['preset' => 'col-12', 'blocks' => []],
        ]));

        $this->assertFalse($result->hasWarnings());
        $this->assertCount(1, $result->section->getColumns());
    }

    public function testUnknownFieldWarnsButKeepsDataAndInserts(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [
                // `subtitle` no longer exists on the `title` block type.
                ['type' => 'title', 'data' => ['text' => 'Hi', 'level' => 2, 'subtitle' => 'legacy']],
            ]],
        ]);

        $result = $this->instantiator()->instantiate($payload);

        $this->assertSame(
            [['blockType' => 'title', 'unknownKeys' => ['subtitle']]],
            $result->unknownFields,
        );
        $this->assertSame(0, $result->skippedBlockCount);

        // Non-blocking: the block is still built and the legacy value kept.
        $block = $result->section->getColumns()->first()->getBlocks()->first();
        $this->assertSame(['text' => 'Hi', 'level' => 2, 'subtitle' => 'legacy'], $block->getDraftData());
    }

    public function testNoWarningsWhenDataMatchesCurrentShape(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'title', 'data' => ['text' => 'Hi', 'level' => 2]],
            ]],
        ]);

        $result = $this->instantiator()->instantiate($payload);
        $this->assertFalse($result->hasWarnings());
    }

    /**
     * `styling` is added to every block form by BlockFormType and deliberately
     * absent from getDefaultData(), so reading defaults alone flagged it on
     * every styled block — a warning toast on virtually every template insert.
     */
    public function testStylingIsNotReportedAsAnUnknownField(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'title', 'data' => [
                    'text' => 'Hi',
                    'level' => 2,
                    'styling' => ['backgroundColor' => '#eb0540'],
                ]],
            ]],
        ]);

        $result = $this->instantiator()->instantiate($payload);

        $this->assertFalse($result->hasWarnings());
    }

    /**
     * Same false positive for any field a host contributes through a
     * BlockFormExtension: it is in the form and in the stored data, but never
     * in the block type's getDefaultData().
     */
    public function testHostExtensionFieldIsNotReportedAsAnUnknownField(): void
    {
        $anchor = new class () implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $builder->add('anchorId', TextType::class, [
                    'required' => false,
                    'data' => $data['anchorId'] ?? '',
                ]);
            }
        };

        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'title', 'data' => ['text' => 'Hi', 'level' => 2, 'anchorId' => 'cta', 'ghost' => 1]],
            ]],
        ]);

        $result = $this->instantiator($this->extensions($anchor))->instantiate($payload);

        // `anchorId` is legitimate now; `ghost` is still nobody's field.
        $this->assertSame(
            [['blockType' => 'title', 'unknownKeys' => ['ghost']]],
            $result->unknownFields,
        );
    }

    /** A template or a clipboard entry is untrusted: settings are filtered. */
    public function testColumnSettingsArriveSanitizedInTheDraft(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-6', 'settings' => ['label' => ' Specs ', 'onclick' => 'x'], 'blocks' => []],
            ['preset' => 'col-6', 'settings' => 'not-an-array', 'blocks' => []],
        ]);

        $columns = array_values($this->instantiator()->instantiate($payload)->section->getColumns()->toArray());

        $this->assertSame(['label' => 'Specs'], $columns[0]->getDraftSettings());
        $this->assertNull($columns[1]->getDraftSettings());
        $this->assertNull($columns[0]->getPublishedSettings());
    }

    // A pasted section is a template payload from localStorage.
    public function testALayoutOrPresetThisInstallCannotRenderIsNotKept(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-4', 'blocks' => []],
            ['preset' => 'col-99', 'blocks' => []],
        ]);
        $payload['layout'] = 'made-up';

        $section = $this->instantiator()->instantiate($payload)->section;

        $this->assertSame(Section::LAYOUT_FULL, $section->getLayout());
        $this->assertSame(['col-4', 'col-12'], array_map(
            static fn ($c) => $c->getPreset(),
            $section->getColumns()->toArray(),
        ));
    }

    public function testABlockWithoutATypeIsDropped(): void
    {
        $section = $this->instantiator()->instantiate($this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'text', 'data' => ['content' => 'kept']],
                ['data' => ['content' => 'orphan']],
            ]],
        ]))->section;

        $this->assertCount(1, $section->getColumns()->first()->getBlocks());
    }

    public function testRoundTripsThroughTheSerializer(): void
    {
        $source = new Section();
        $source->setLayout(Section::LAYOUT_FULL);
        $col = (new \ContentBlocks\Entity\Column())->setPreset('col-12')->setPreviewPosition(0);
        $col->setDraftSettings(['label' => 'Tab']);
        $source->addColumn($col);
        $col->addBlock(
            (new \ContentBlocks\Entity\Block())->setType('text')->setDraftData(['text' => 'hello'])->setPreviewPosition(0),
        );

        $snapshot = (new SectionTemplateSerializer())->serialize($source);
        $result = $this->instantiator()->instantiate($snapshot->payload);

        $this->assertSame(Section::LAYOUT_FULL, $result->section->getLayout());
        $this->assertSame(
            ['text' => 'hello'],
            $result->section->getColumns()->first()->getBlocks()->first()->getDraftData(),
        );
        $this->assertSame(['label' => 'Tab'], $result->section->getColumns()->first()->getDraftSettings());
        $this->assertFalse($result->hasWarnings());
    }
}
