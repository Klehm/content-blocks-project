<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\BlockType\BlockPurposeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\CollectionFoldTypeExtension;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Kit\Block\AbstractKitBlock;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use ContentBlocks\Kit\Form\Type\RichTextEditorType;
use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use ContentBlocks\Kit\RichText\RichTextFieldSchema;
use ContentBlocks\Kit\RichText\TinyMceEditor;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Schema\ContentSchemaBuilder;
use ContentBlocks\Schema\FormSchemaMapper;
use ContentBlocks\Schema\PaletteColorFieldSchema;
use ContentBlocks\Schema\UploadFieldSchema;
use ContentBlocks\Section\SectionStyleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;

/**
 * Every kit block reaches the content schema: with a purpose a model can
 * choose it by, and a data schema its own defaults satisfy.
 */
final class ContentSchemaTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $defs = null;

    /**
     * @return iterable<string, array{string, class-string<AbstractKitBlock>}>
     */
    public static function blocks(): iterable
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            yield $type => [$type, $class];
        }
    }

    /** @param class-string<AbstractKitBlock> $class */
    #[DataProvider('blocks')]
    public function testEveryBlockSaysWhatItIsFor(string $type, string $class): void
    {
        $block = new $class();

        $this->assertInstanceOf(BlockPurposeInterface::class, $block);
        $purpose = $block->getPurpose();
        $this->assertNotSame('', trim($purpose));
        // Read by a model on every request: a sentence or two, not a page.
        $this->assertLessThanOrEqual(200, mb_strlen($purpose), $type . ': keep the purpose short');
    }

    /** @param class-string<AbstractKitBlock> $class */
    #[DataProvider('blocks')]
    public function testEveryDefaultValueFitsItsOwnSchema(string $type, string $class): void
    {
        $data = $this->defs()['block.' . $type]['properties']['data'];
        $this->assertSame('object', $data['type']);

        foreach ((new $class())->getDefaultData() as $key => $value) {
            $this->assertArrayHasKey($key, $data['properties'], sprintf('%s: "%s" is not in the schema', $type, $key));
            $enum = $data['properties'][$key]['enum'] ?? null;
            if (\is_array($enum) && \is_scalar($value)) {
                $this->assertContains($value, $enum, sprintf('%s: default "%s" is not offered', $type, $key));
            }
        }
    }

    public function testRichTextIsAnnouncedAsHtml(): void
    {
        $content = $this->defs()['block.rich_text']['properties']['data']['properties']['content'];

        $this->assertSame('string', $content['type']);
        $this->assertSame('text/html', $content['contentMediaType']);
    }

    public function testCardEntriesAreDescribedWithTheirImage(): void
    {
        $item = $this->defs()['block.card']['properties']['data']['properties']['items']['items'];

        $this->assertSame(['src', 'title', 'content', 'url', 'buttonText'], array_keys($item['properties']));
        $this->assertStringContainsString('never invent', $item['properties']['src']['description']);
    }

    /**
     * @return array<string, mixed>
     */
    private function defs(): array
    {
        if (self::$defs !== null) {
            return self::$defs;
        }

        $registry = new BlockTypeRegistry();
        foreach (ContentBlocksKitBundle::BLOCKS as $class) {
            $registry->register(new $class());
        }

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_content-blocks/upload');
        $palette = new ColorPaletteRegistry([]);
        $editors = new RichTextEditorRegistry([new TinyMceEditor($palette, $urls)]);

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new PreloadedExtension([
                new RichTextEditorType($editors),
                new BlockFormType(new BlockFormExtensionCollection()),
                new SectionSettingsType(new SectionStyleRegistry()),
                new PaletteColorType($palette),
            ], []))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->addTypeExtension(new CollectionFoldTypeExtension())
            ->getFormFactory();

        $mapper = new FormSchemaMapper([
            new RichTextFieldSchema(),
            new PaletteColorFieldSchema($palette),
            new UploadFieldSchema(),
        ]);

        return self::$defs = (new ContentSchemaBuilder($registry, $factory, $mapper))->build()->schema['$defs'];
    }
}
