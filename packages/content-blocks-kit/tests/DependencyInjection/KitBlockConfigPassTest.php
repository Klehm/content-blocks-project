<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\DependencyInjection;

use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\Block\TitleBlock;
use ContentBlocks\Kit\DependencyInjection\KitBlockConfigPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * `content_blocks_kit.blocks.<type>` reaching a **subclassed** block.
 *
 * The bundle wires its config as constructor arguments while registering its
 * own block services. Subclassing one requires turning that service off — two
 * services cannot claim the same type id — which took the type out of the
 * registration loop, so the host's subclass came up autowired with the
 * constructor defaults and its whole configuration was dropped in silence. It
 * cost ybc two debugging sessions: an editor going back to the CDN despite
 * `cdn: false`, then a title variant added through `choices` never appearing.
 */
final class KitBlockConfigPassTest extends TestCase
{
    public function testASubclassOfADisabledBlockStillGetsItsConfig(): void
    {
        // Exactly the documented setup: `title: { enabled: false }` plus a host
        // subclass keeping getType() — so the bundle registers no `title`.
        $container = $this->containerWith([HostTitleBlock::class => 'app.title']);

        $this->process($container, [
            'title' => ['enabled' => false, 'choices' => ['size' => ['h1' => 'Huge']]],
        ]);

        $this->assertSame(
            ['size' => ['h1' => 'Huge']],
            $container->getDefinition('app.title')->getArgument('$choiceOverrides'),
        );
    }

    public function testOptionsAreMergedOverTheSubclassCodedDefaults(): void
    {
        $container = $this->containerWith([HostRichTextBlock::class => 'app.rich_text']);

        $this->process($container, ['rich_text' => ['options' => ['cdn' => false]]]);

        $options = $container->getDefinition('app.rich_text')->getArgument('$options');

        // The host's value wins…
        $this->assertFalse($options['cdn']);
        // …over the subclass's own default…
        $this->assertSame('ckeditor', $options['editor']);
        // …itself over the kit's.
        $this->assertTrue($options['uploads']);
    }

    public function testDefaultOverridesLandToo(): void
    {
        $container = $this->containerWith([HostTitleBlock::class => 'app.title']);

        $this->process($container, ['title' => ['defaults' => ['size' => 'h3']]]);

        $this->assertSame(['size' => 'h3'], $container->getDefinition('app.title')->getArgument('$defaultOverrides'));
    }

    public function testAnArgumentSomethingElseSetIsLeftAlone(): void
    {
        // The bundle's own registrations go through here as well; whoever was
        // explicit keeps the last word.
        $container = $this->containerWith([ButtonBlock::class => 'kit.button']);
        $container->getDefinition('kit.button')->setArgument('$choiceOverrides', ['variant' => ['ghost' => 'Ghost']]);

        $this->process($container, ['button' => ['choices' => ['variant' => ['primary']]]]);

        $this->assertSame(
            ['variant' => ['ghost' => 'Ghost']],
            $container->getDefinition('kit.button')->getArgument('$choiceOverrides'),
        );
    }

    public function testABlockWithNoConfigIsNotTouched(): void
    {
        $container = $this->containerWith([HostTitleBlock::class => 'app.title']);

        $this->process($container, ['button' => ['options' => ['x' => 1]]]);

        $this->assertSame([], $container->getDefinition('app.title')->getArguments());
    }

    public function testABlockThatIsNotAKitBlockIsIgnored(): void
    {
        $container = $this->containerWith([PlainHostBlock::class => 'app.plain']);

        $this->process($container, ['plain' => ['options' => ['x' => 1]]]);

        $this->assertSame([], $container->getDefinition('app.plain')->getArguments());
    }

    public function testASubclassCarryingItsOwnTypeIdIsConfiguredUnderThatId(): void
    {
        // Falls out of keying on getType(): a host extending a kit block into a
        // new type configures it under its own name.
        $container = $this->containerWith([RenamedBlock::class => 'app.renamed']);

        $this->process($container, ['fancy_title' => ['defaults' => ['size' => 'h4']]]);

        $this->assertSame(['size' => 'h4'], $container->getDefinition('app.renamed')->getArgument('$defaultOverrides'));
    }

    /** @param array<class-string, string> $services class => service id */
    private function containerWith(array $services): ContainerBuilder
    {
        $container = new ContainerBuilder();

        foreach ($services as $class => $id) {
            $definition = new Definition($class);
            // The tag #[AsContentBlock] puts on anything that is a block type;
            // the pass keys off it rather than adding one of its own.
            $definition->addTag('content_blocks.block_type');
            $container->setDefinition($id, $definition);
        }

        return $container;
    }

    /** @param array<string, array<string, mixed>> $blocksConfig */
    private function process(ContainerBuilder $container, array $blocksConfig): void
    {
        (new KitBlockConfigPass(static fn (): array => $blocksConfig))->process($container);
    }
}

/** A host subclass as the docs prescribe: same type id, kit service disabled. */
final class HostTitleBlock extends TitleBlock
{
}

final class HostRichTextBlock extends RichTextBlock
{
    public static function defaultOptions(): array
    {
        return array_replace(parent::defaultOptions(), ['editor' => 'ckeditor']);
    }
}

final class RenamedBlock extends TitleBlock
{
    public static function getType(): string
    {
        return 'fancy_title';
    }
}

/** Not a kit block at all — a host block type of its own. */
final class PlainHostBlock extends \ContentBlocks\BlockType\AbstractBlockType
{
    public static function getType(): string
    {
        return 'plain';
    }

    public static function getLabel(): string
    {
        return 'Plain';
    }

    public function buildForm(\Symfony\Component\Form\FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}

