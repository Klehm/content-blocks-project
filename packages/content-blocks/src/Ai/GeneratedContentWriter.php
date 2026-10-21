<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

use ContentBlocks\Builder\AppendTarget;
use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Clipboard\BlockSnapshotSerializerInterface;
use ContentBlocks\Clipboard\ClipboardPaster;
use ContentBlocks\Clipboard\UnreadableClipboardException;
use ContentBlocks\Content\ContentManipulatorInterface;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Section\RestoredStructure;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * The way back in: a generated `{"sections": [...]}` document written to an
 * area's draft through the paste replay. It never flushes.
 *
 * @see docs/internals/ai.md#the-way-back-in
 */
final class GeneratedContentWriter
{
    public function __construct(
        private readonly ClipboardPaster $paster,
        private readonly ContentManipulatorInterface $content,
        private readonly BuilderStructureResolverInterface $structure,
        private readonly FormFactoryInterface $formFactory,
        private readonly SectionSettingsDefaults $settingsDefaults = new SectionSettingsDefaults(),
    ) {
    }

    /**
     * @param array<mixed> $document    untrusted, as a model wrote it
     * @param Section|null $after       where the first section goes; null
     *                                  appends
     * @param bool         $replace     whether the area's sections go first
     *                                  (a draft delete, undone by Discard)
     * @param Section|null $replacing   a section the document is a revision
     *                                  of: it goes, the answer takes its place
     *
     * @throws GenerationRefusedException
     */
    public function write(
        ContentArea $area,
        array $document,
        ?Section $after = null,
        int $maxSections = RestoredStructure::MAX_SECTIONS,
        bool $replace = false,
        ?Section $replacing = null,
    ): GenerationResult {
        $raw = $document['sections'] ?? null;
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new GenerationRefusedException(GenerationRefusedException::UNREADABLE);
        }

        $sections = \array_slice(array_values(array_filter($raw, 'is_array')), 0, max(1, $maxSections));
        if ($sections === []) {
            throw new GenerationRefusedException(GenerationRefusedException::EMPTY);
        }
        if (RestoredStructure::tooLarge($sections)) {
            throw new GenerationRefusedException(GenerationRefusedException::TOO_LARGE);
        }

        $structure = $this->structure->forArea($area);
        if ($structure->sections === BuilderStructure::SECTIONS_FIXED) {
            throw new GenerationRefusedException(GenerationRefusedException::SECTIONS_LOCKED);
        }

        if (!$structure->showsSections()) {
            // One implicit section: revising it is revising the page.
            $result = $this->writeBlocks($area, $sections, $structure, $replace || $replacing !== null);
        } elseif ($replacing !== null) {
            $result = $this->writeSections($area, $sections, $replacing, false);
        } else {
            $result = $this->writeSections($area, $sections, $replace ? null : $after, $replace);
        }

        if ($result->sections === [] && $result->blocks === []) {
            throw new GenerationRefusedException(GenerationRefusedException::EMPTY);
        }
        // Only once its revision is in: a refused answer leaves it standing.
        if ($replacing !== null && $structure->showsSections()) {
            $this->content->deleteSection($replacing);
        }

        return $result;
    }

    /**
     * @param list<array<mixed>> $sections
     */
    private function writeSections(ContentArea $area, array $sections, ?Section $after, bool $replace): GenerationResult
    {
        if ($replace) {
            foreach (DraftOrder::sections($area) as $section) {
                $this->content->deleteSection($section);
            }
        }

        $allowed = $this->settingKeys();
        $written = [];
        $skipped = [];
        $dropped = [];
        foreach ($sections as $payload) {
            try {
                $pasted = $this->paster->pasteSection($this->sectionPayload($payload, $allowed), $area, $after);
            } catch (IncompatibleTemplateException $e) {
                $skipped = [...$skipped, ...$e->getMissingTypes()];
                continue;
            } catch (UnsupportedTemplateFormatException) {
                continue;
            }

            \assert($pasted->entity instanceof Section);
            $written[] = $after = $pasted->entity;
            $skipped = [...$skipped, ...$pasted->skippedBlockTypes];
            $dropped = [...$dropped, ...$pasted->droppedFields];
        }

        return new GenerationResult($written, [], array_values(array_unique($skipped)), $dropped);
    }

    /**
     * Blocks only: every block of every section, in order, at the end of the
     * area's one column.
     *
     * @param list<array<mixed>> $sections
     */
    private function writeBlocks(ContentArea $area, array $sections, BuilderStructure $structure, bool $replace): GenerationResult
    {
        if ($replace) {
            foreach (DraftOrder::sections($area) as $section) {
                foreach (DraftOrder::columns($section) as $column) {
                    foreach (DraftOrder::blocks($column) as $block) {
                        $this->content->deleteBlock($block);
                    }
                }
            }
        }

        $column = AppendTarget::column($this->content, $area, $structure);
        if ($column === null) {
            throw new GenerationRefusedException(GenerationRefusedException::NO_TARGET);
        }

        $blocks = DraftOrder::blocks($column);
        $after = $blocks === [] ? null : $blocks[array_key_last($blocks)];
        $written = [];
        $skipped = [];
        $dropped = [];
        foreach (self::blocksIn($sections) as $raw) {
            try {
                $pasted = $this->paster->pasteBlock([
                    'format' => BlockSnapshotSerializerInterface::FORMAT,
                    'type' => $raw['type'] ?? null,
                    'data' => \is_array($raw['data'] ?? null) ? $raw['data'] : [],
                ], $column, $after);
            } catch (IncompatibleTemplateException $e) {
                $skipped = [...$skipped, ...$e->getMissingTypes()];
                continue;
            } catch (UnreadableClipboardException) {
                continue;
            }

            \assert($pasted->entity instanceof Block);
            $written[] = $after = $pasted->entity;
            $dropped = [...$dropped, ...$pasted->droppedFields];
        }

        return new GenerationResult([], $written, array_values(array_unique($skipped)), $dropped);
    }

    /**
     * The payload the paste path reads. Settings keep only the keys the
     * section form declares; their values get the render-time guards.
     *
     * @param array<mixed> $payload
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    private function sectionPayload(array $payload, array $allowed): array
    {
        $settings = $payload['settings'] ?? null;

        return [
            // Models drop a constant now and then; it carries nothing.
            'format' => SectionTemplateSerializerInterface::FORMAT,
            'layout' => $payload['layout'] ?? null,
            'settings' => \is_array($settings) ? array_intersect_key($settings, array_flip($allowed)) : null,
            'columns' => \is_array($payload['columns'] ?? null) ? $payload['columns'] : [],
        ];
    }

    /**
     * @param list<array<mixed>> $sections
     *
     * @return iterable<array<mixed>>
     */
    private static function blocksIn(array $sections): iterable
    {
        foreach ($sections as $section) {
            foreach (\is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                $blocks = \is_array($column) ? ($column['blocks'] ?? null) : null;
                foreach (\is_array($blocks) ? $blocks : [] as $block) {
                    if (\is_array($block)) {
                        yield $block;
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function settingKeys(): array
    {
        $form = $this->formFactory->create(SectionSettingsType::class, $this->settingsDefaults->get(), [
            'column_count' => 2,
        ]);

        return array_map('strval', array_keys($form->all()));
    }
}
