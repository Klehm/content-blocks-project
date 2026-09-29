<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\BlockRestoreTally;
use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Section\RestoredSectionBuilder;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;

/**
 * Default {@see SectionTemplateInstantiatorInterface} — see it for the
 * contract. Known data keys are decided by {@see BlockDataKeys}.
 */
final class SectionTemplateInstantiator implements SectionTemplateInstantiatorInterface
{
    private readonly RestoredSectionBuilder $builder;

    public function __construct(
        BlockTypeRegistry $registry,
        BlockDataKeys $dataKeys,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
        ?CollectionIdBackfiller $collectionIds = null,
        SectionLayoutRegistry $layouts = new SectionLayoutRegistry(),
    ) {
        $this->builder = new RestoredSectionBuilder($registry, $dataKeys, $collectionIds, $layouts);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws UnsupportedTemplateFormatException on an unreadable envelope
     * @throws IncompatibleTemplateException      when no block survives
     */
    public function instantiate(array $payload): InstantiationResult
    {
        $payload = $this->normalizeEnvelope($payload);

        $tally = new BlockRestoreTally();
        $section = $this->builder->build($payload, $tally);

        // Had blocks, kept none. One that never had any inserts fine.
        if ($tally->keptCount() === 0 && $tally->skippedCount() > 0) {
            throw new IncompatibleTemplateException($tally->skippedTypes());
        }

        return new InstantiationResult(
            $section,
            $tally->skippedCount(),
            $tally->skippedTypes(),
            $tally->unknownFields(),
        );
    }

    /**
     * Migrated forward by {@see EnvelopeUpgradeChain} when a step exists for
     * the structure, and refused otherwise.
     *
     * @see docs/internals/section-templates.md#versioning-the-envelope
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeEnvelope(array $payload): array
    {
        $target = SectionTemplateSerializerInterface::FORMAT;
        $format = $payload['format'] ?? null;

        if (!is_string($format) || !$this->envelopes->supports($format, $target)) {
            throw new UnsupportedTemplateFormatException(is_string($format) ? $format : null, $target, );
        }

        return $this->envelopes->upgrade($payload, $format, $target);
    }
}
