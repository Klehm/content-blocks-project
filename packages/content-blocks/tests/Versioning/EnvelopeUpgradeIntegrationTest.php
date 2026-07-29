<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Versioning;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException;
use ContentBlocks\Tests\Fixtures\Versioning\FixtureEnvelopeUpgrader;
use ContentBlocks\Transfer\ContentAreaImporter;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;
use ContentBlocks\Versioning\EnvelopeUpgraderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;

/**
 * The chain is only worth shipping empty if adding one step is genuinely all it
 * takes. These tests hold that claim to account from both ends:
 *
 *  - a payload in a format nobody can read is refused (today's behaviour);
 *  - registering a single step makes that exact payload work, in both restore
 *    flows, with no other change;
 *  - and the step is picked up by autoconfiguration alone, so a future bump
 *    ships a class, not a wiring diagram.
 */
final class EnvelopeUpgradeIntegrationTest extends TestCase
{
    private function legacyTemplatePayload(): array
    {
        return [
            'format' => FixtureEnvelopeUpgrader::LEGACY_FORMAT,
            'layout' => 'full',
            'settings' => null,
            // The old shape: columns live under `body`.
            'body' => [['preset' => 'col-12', 'blocks' => [
                ['type' => 'text', 'data' => ['content' => 'from an older envelope']],
            ]]],
        ];
    }

    public function testWithoutAStepAnOlderEnvelopeIsRefused(): void
    {
        $this->expectException(UnsupportedTemplateFormatException::class);

        $this->instantiator(new EnvelopeUpgradeChain())->instantiate($this->legacyTemplatePayload());
    }

    public function testOneStepMakesTheSamePayloadInsertable(): void
    {
        $chain = new EnvelopeUpgradeChain([new FixtureEnvelopeUpgrader()]);

        $result = $this->instantiator($chain)->instantiate($this->legacyTemplatePayload());

        $block = $result->section->getColumns()->first()->getBlocks()->first();
        $this->assertSame(['content' => 'from an older envelope'], $block->getDraftData());
        $this->assertFalse($result->hasWarnings(), 'block data crosses the bump untouched');
    }

    public function testTheImportFlowGoesThroughTheSameChain(): void
    {
        $step = new class implements EnvelopeUpgraderInterface {
            public function upgradesFrom(): string
            {
                return 'content-blocks/v0';
            }

            public function upgradesTo(): string
            {
                return 'content-blocks/v1';
            }

            public function upgrade(array $payload): array
            {
                $payload['contentArea'] = ['sections' => $payload['areas'] ?? []];
                unset($payload['areas']);

                return $payload;
            }
        };

        $target = new ContentArea();
        $result = $this->importer(new EnvelopeUpgradeChain([$step]))->import($target, [
            'format' => 'content-blocks/v0',
            'areas' => [['layout' => 'full', 'columns' => []]],
            'assets' => [],
        ]);

        $this->assertSame(1, $result->sectionCount);
    }

    public function testAStepIsDiscoveredByAutoconfigurationAlone(): void
    {
        // What "leaving the door open" actually means: a future bump ships a
        // class implementing the interface, and nothing else.
        $container = new ContainerBuilder();
        (new ContentBlocksBundle())->build($container);

        $container->setDefinition(
            EnvelopeUpgradeChain::class,
            (new Definition(EnvelopeUpgradeChain::class))
                ->setArgument(0, new \Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument('content_blocks.envelope_upgrader'))
                ->setPublic(true),
        );
        $container->setDefinition(
            FixtureEnvelopeUpgrader::class,
            (new Definition(FixtureEnvelopeUpgrader::class))->setAutoconfigured(true),
        );
        $container->compile();

        /** @var EnvelopeUpgradeChain $chain */
        $chain = $container->get(EnvelopeUpgradeChain::class);

        $this->assertTrue(
            $chain->supports(FixtureEnvelopeUpgrader::LEGACY_FORMAT, 'content-blocks/section-v1'),
            'the tag comes from ContentBlocksBundle::build(), not from explicit wiring',
        );
    }

    private function registry(): BlockTypeRegistry
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new EnvelopeFixtureTextBlock());

        return $registry;
    }

    private function dataKeys(BlockTypeRegistry $registry): BlockDataKeys
    {
        return new BlockDataKeys(
            $registry,
            Forms::createFormFactoryBuilder()
                ->addType(new BlockFormType(new BlockFormExtensionCollection()))
                ->getFormFactory(),
        );
    }

    private function instantiator(EnvelopeUpgradeChain $chain): SectionTemplateInstantiator
    {
        $registry = $this->registry();

        return new SectionTemplateInstantiator($registry, $this->dataKeys($registry), $chain);
    }

    private function importer(EnvelopeUpgradeChain $chain): ContentAreaImporter
    {
        $registry = $this->registry();

        return new ContentAreaImporter(
            $this->createMock(AssetResolverInterface::class),
            $registry,
            $this->dataKeys($registry),
            $chain,
        );
    }
}

final class EnvelopeFixtureTextBlock extends AbstractBlockType
{
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
        return ['content' => ''];
    }
}
