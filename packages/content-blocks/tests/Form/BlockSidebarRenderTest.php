<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\IconChoiceTypeExtension;
use ContentBlocks\Form\Extension\SidebarLayoutTypeExtension;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Icon\CoreUiIcons;
use ContentBlocks\Icon\UiIconRegistry;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Twig\UiIconExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * The block sidebar rendered for real, with and without its Style tab.
 */
final class BlockSidebarRenderTest extends TestCase
{
    private \DOMXPath $dom;

    public function testTheStyleTabFollowsTheBlockFields(): void
    {
        $this->render(includeStyling: true);

        $this->assertSame(
            ['cb.block.tab.general', 'cb.block.tab.style'],
            $this->texts('//*[contains(@class, "cb-sidebar-tabs__tab")]'),
        );
        $this->assertNotEmpty($this->texts('//*[starts-with(@name, "content_block[styling]")]'));
    }

    // `content_blocks.styling.block: false`
    public function testWithoutStylingTheBlockFieldsStandAlone(): void
    {
        $this->render(includeStyling: false);

        $this->assertSame([], $this->texts('//*[contains(@class, "cb-sidebar-tabs__tab")]'), 'no tab bar');
        $this->assertCount(1, $this->texts('//section[contains(@class, "cb-sidebar-tabs__panel")]'));
        $this->assertCount(1, $this->texts('//input[@name="content_block[title]"]'));
        $this->assertSame([], $this->texts('//*[starts-with(@name, "content_block[styling]")]'));
    }

    /**
     * @return list<string>
     */
    private function texts(string $xpath): array
    {
        $out = [];
        foreach ($this->dom->query($xpath) ?: [] as $node) {
            $out[] = trim($node->textContent);
        }

        return $out;
    }

    private function render(bool $includeStyling): void
    {
        $blockType = new class () extends AbstractBlockType {
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
                $builder->add('title', TextType::class, ['required' => false]);
            }

            public function getDefaultData(): array
            {
                return ['title' => ''];
            }
        };

        $form = Forms::createFormFactoryBuilder()
            ->addTypeExtension(new SidebarLayoutTypeExtension())
            ->addTypeExtension(new IconChoiceTypeExtension())
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->addType(new PaletteColorType(new ColorPaletteRegistry([])))
            ->getFormFactory()
            ->create(BlockFormType::class, ['title' => ''], [
                'block_type' => $blockType,
                'block_data' => ['title' => ''],
                'include_styling' => $includeStyling,
            ]);

        // What the Live Component exposes to its template as `this`.
        $component = new class ($blockType) {
            /** @var list<string> */
            public array $refusal = [];
            public string $blockTypeLabel = 'Title';
            public object $block;

            public function __construct(public AbstractBlockType $blockType)
            {
                $this->block = (object) ['type' => $blockType->getType()];
            }
        };

        $root = \dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root . '/templates', 'ContentBlocks');
        $loader->addPath($root . '/vendor/symfony/twig-bridge/Resources/views/Form');

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $twig->addExtension(new UiIconExtension(new UiIconRegistry([new CoreUiIcons()])));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());

        $html = $twig->render('@ContentBlocks/components/Block.html.twig', [
            'attributes' => '',
            'this' => $component,
            'form' => $form->createView(),
        ]);

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $this->dom = new \DOMXPath($document);
    }
}
