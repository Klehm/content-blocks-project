<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Schema;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\SectionTemplate;
use ContentBlocks\Schema\SectionTemplateExamples;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * An example teaches a model the shape to write, so one the way in would
 * refuse must never be offered.
 */
final class SectionTemplateExamplesTest extends TestCase
{
    public function testTemplatesComeLatestFirstUpToTheLimit(): void
    {
        $examples = $this->examples([
            $this->template('One'),
            $this->template('Two'),
            $this->template('Three'),
        ]);

        $this->assertSame(['One', 'Two'], array_column($examples->latest(2), 'name'));
    }

    public function testAStaleContentVersionIsLeftOut(): void
    {
        $examples = $this->examples([$this->template('Old', version: 1), $this->template('New')]);

        $this->assertSame(['New'], array_column($examples->latest(3), 'name'));
    }

    public function testATemplateSavedBeforeVersioningIsLeftOut(): void
    {
        $examples = $this->examples([$this->template('Legacy', version: null)]);

        $this->assertSame([], $examples->latest(3));
    }

    public function testAnUnknownBlockTypeOrFormatIsLeftOut(): void
    {
        $examples = $this->examples([
            $this->template('Gone', types: ['carousel']),
            $this->template('Foreign', format: 'someone/else-v9'),
            $this->template('Fine'),
        ]);

        $this->assertSame(['Fine'], array_column($examples->latest(3), 'name'));
    }

    public function testReservedKeysAreStripped(): void
    {
        $examples = $this->examples([$this->template('Hero', data: [
            'text' => 'Hi',
            '_order' => ['t' => 1],
            'items' => [['_id' => 'abc', 'label' => 'A']],
        ])]);

        $section = $examples->latest(1)[0]['section'];

        $this->assertSame(
            ['text' => 'Hi', 'items' => [['label' => 'A']]],
            $section['columns'][0]['blocks'][0]['data'],
            'the schema offers no `_` key, so an example must not show one',
        );
    }

    public function testAnUnstyledSectionHasNoSettingsRatherThanNull(): void
    {
        $template = $this->template('Plain');
        $template->setPayload(['settings' => null] + $template->getPayload());

        $section = $this->examples([$template])->latest(1)[0]['section'];

        $this->assertArrayNotHasKey('settings', $section, 'the schema wants an object or nothing');
    }

    public function testNoLimitReadsNothing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $this->assertSame([], (new SectionTemplateExamples($em, new BlockTypeRegistry()))->latest(0));
    }

    /**
     * @param list<SectionTemplate> $templates
     */
    private function examples(array $templates): SectionTemplateExamples
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($templates);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(SectionTemplate::class)->willReturn($repository);

        $registry = new BlockTypeRegistry();
        $registry->register(new NoteFixtureBlock());

        return new SectionTemplateExamples($em, $registry, contentVersion: 2);
    }

    /**
     * @param list<string>         $types
     * @param array<string, mixed> $data
     */
    private function template(
        string $name,
        ?int $version = 2,
        array $types = ['note'],
        string $format = SectionTemplateSerializerInterface::FORMAT,
        array $data = ['text' => 'Hi'],
    ): SectionTemplate {
        return (new SectionTemplate())
            ->setName($name)
            ->setPayload([
                'format' => $format,
                'layout' => 'full',
                'columns' => [['preset' => 'col-12', 'blocks' => [['type' => $types[0], 'data' => $data]]]],
            ])
            ->setBlockTypes($types)
            ->setContentVersion($version);
    }
}
