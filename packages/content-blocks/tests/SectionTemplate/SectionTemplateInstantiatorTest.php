<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\SectionTemplate;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\SectionTemplateSerializer;
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

        return new SectionTemplateInstantiator($this->registry(), $factory);
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
        $text = new class extends AbstractBlockType {
            public static function getType(): string
            {
                return 'text';
            }

            public static function getLabel(): string
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

        $title = new class extends AbstractBlockType {
            public static function getType(): string
            {
                return 'title';
            }

            public static function getLabel(): string
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
            'settings' => ['classes' => 'hero'],
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
        $this->assertSame(['classes' => 'hero'], $section->getDraftSettings());
        $this->assertSame([], $result->warnings);

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

    public function testUnknownBlockTypeIsAHardStop(): void
    {
        $payload = $this->payload([
            ['preset' => 'col-12', 'blocks' => [
                ['type' => 'text', 'data' => ['text' => 'A']],
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
            $result->warnings,
        );

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
        $this->assertSame([], $result->warnings);
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

        $this->assertSame([], $result->warnings);
    }

    /**
     * Same false positive for any field a host contributes through a
     * BlockFormExtension: it is in the form and in the stored data, but never
     * in the block type's getDefaultData().
     */
    public function testHostExtensionFieldIsNotReportedAsAnUnknownField(): void
    {
        $anchor = new class implements BlockFormExtensionInterface {
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
            $result->warnings,
        );
    }

    public function testRoundTripsThroughTheSerializer(): void
    {
        $source = new Section();
        $source->setLayout(Section::LAYOUT_FULL);
        $col = (new \ContentBlocks\Entity\Column())->setPreset('col-12')->setPreviewPosition(0);
        $source->addColumn($col);
        $col->addBlock(
            (new \ContentBlocks\Entity\Block())->setType('text')->setDraftData(['text' => 'hello'])->setPreviewPosition(0),
        );

        $serialized = (new SectionTemplateSerializer())->serialize($source);
        $result = $this->instantiator()->instantiate($serialized['payload']);

        $this->assertSame(Section::LAYOUT_FULL, $result->section->getLayout());
        $this->assertSame(
            ['text' => 'hello'],
            $result->section->getColumns()->first()->getBlocks()->first()->getDraftData(),
        );
        $this->assertSame([], $result->warnings);
    }
}
