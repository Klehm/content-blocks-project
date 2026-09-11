<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\BlockRestoreTally;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;

/**
 * Default {@see ContentAreaImporterInterface} — see it for the contract.
 * Asset binaries are re-stored through {@see AssetResolverInterface}.
 */
final class ContentAreaImporter implements ContentAreaImporterInterface
{
    /**
     * @param iterable<ContentAreaTransferExtensionInterface> $extensions
     */
    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataKeys $dataKeys,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
        private readonly iterable $extensions = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function import(ContentArea $target, array $payload): ImportResult
    {
        $payload = $this->normalizeEnvelope($payload);

        $assets = new AssetRewriter($this->materializeAssets($payload['assets'] ?? []));

        $sectionsRaw = $payload['contentArea']['sections'] ?? null;
        if (!is_array($sectionsRaw)) {
            throw new \InvalidArgumentException('Missing or invalid "contentArea.sections" in payload.');
        }

        // Replace mode: soft-delete every existing section. The actual
        // em->remove() runs at publish time (see ContentAreaPublisher).
        foreach ($target->getSections() as $existing) {
            $existing->setDeleted(true);
        }

        $count = 0;
        $tally = new BlockRestoreTally();
        /** @var array<string, Block> $blocks */
        $blocks = [];
        foreach (array_values($sectionsRaw) as $i => $sectionRaw) {
            if (!is_array($sectionRaw)) {
                continue;
            }
            $section = $this->buildSection($sectionRaw, 's' . $i, $assets, $tally, $blocks);
            $section->setPreviewPosition($i);
            $target->addSection($section);
            ++$count;
        }

        $this->importExtensions($target, $blocks, $payload['extensions'] ?? null, $assets);

        return new ImportResult(
            $count,
            $tally->skippedCount(),
            $tally->skippedTypes(),
            $tally->unknownFields(),
        );
    }

    /**
     * @param array<string, Block> $blocks
     */
    private function importExtensions(ContentArea $target, array $blocks, mixed $fragments, AssetRewriter $assets): void
    {
        if (!is_array($fragments)) {
            return;
        }

        foreach ($this->extensions as $extension) {
            $fragment = $fragments[$extension->key()] ?? null;

            if (is_array($fragment) && $fragment !== []) {
                $extension->import($target, $blocks, $fragment, $assets);
            }
        }
    }

    /**
     * Migrated forward when this package ships a step for the structure, and
     * refused otherwise. The target comes from the *interface* constant.
     *
     * @see docs/internals/transfer.md#the-payload-shape-is-the-contract
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeEnvelope(array $payload): array
    {
        $target = ContentAreaExporterInterface::FORMAT;
        $format = $payload['format'] ?? null;

        if (!is_string($format) || !$this->envelopes->supports($format, $target)) {
            throw new \InvalidArgumentException(sprintf('Unsupported format: %s (expected %s).', is_scalar($format) ? (string) $format : '(invalid)', $target, ));
        }

        return $this->envelopes->upgrade($payload, $format, $target);
    }

    /**
     * Decodes and stores every asset blob, returning the hash → new public
     * path map the rewriter patches `asset://` tokens with.
     *
     * @return array<string, string>
     */
    private function materializeAssets(mixed $assetsRaw): array
    {
        if ($assetsRaw === null || $assetsRaw === []) {
            return [];
        }
        if (!is_array($assetsRaw)) {
            throw new \InvalidArgumentException('Invalid "assets" section (expected object).');
        }

        $map = [];
        foreach ($assetsRaw as $hash => $asset) {
            if (!is_string($hash) || !is_array($asset)) {
                throw new \InvalidArgumentException('Malformed asset entry.');
            }
            $data = $asset['data'] ?? null;
            $extension = $asset['extension'] ?? null;
            if (!is_string($data) || !is_string($extension)) {
                throw new \InvalidArgumentException(sprintf('Malformed asset entry for %s.', $hash));
            }
            $binary = base64_decode($data, true);
            if ($binary === false) {
                throw new \InvalidArgumentException(sprintf('Invalid base64 data for asset %s.', $hash));
            }
            $map[$hash] = $this->assetResolver->store($binary, $extension);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, Block> $blocks
     */
    private function buildSection(array $raw, string $ref, AssetRewriter $assets, BlockRestoreTally $tally, array &$blocks): Section
    {
        $section = new Section();
        if (isset($raw['layout']) && is_string($raw['layout'])) {
            $section->setLayout($raw['layout']);
        }

        $settings = $raw['settings'] ?? null;
        if (is_array($settings) && $settings !== []) {
            /** @var array<string, mixed> $rewritten */
            $rewritten = $assets->rewrite($settings);
            $section->setDraftSettings($rewritten);
        }

        $cols = $raw['columns'] ?? null;
        if (is_array($cols)) {
            foreach (array_values($cols) as $i => $colRaw) {
                if (!is_array($colRaw)) {
                    continue;
                }
                $col = $this->buildColumn($colRaw, $ref . '.c' . $i, $assets, $tally, $blocks);
                $col->setPreviewPosition($i);
                $section->addColumn($col);
            }
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, Block> $blocks
     */
    private function buildColumn(array $raw, string $ref, AssetRewriter $assets, BlockRestoreTally $tally, array &$blocks): Column
    {
        $col = new Column();
        if (isset($raw['preset']) && is_string($raw['preset'])) {
            $col->setPreset($raw['preset']);
        }

        $blocksRaw = $raw['blocks'] ?? null;
        if (is_array($blocksRaw)) {
            // Positions come from the *kept* blocks so a skipped one doesn't
            // leave a hole in the sequence; refs count payload entries.
            $position = 0;
            foreach (array_values($blocksRaw) as $i => $blockRaw) {
                if (!is_array($blockRaw)) {
                    continue;
                }
                $block = $this->buildBlock($blockRaw, $assets, $tally);
                if ($block === null) {
                    continue;
                }
                $block->setPreviewPosition($position++);
                $col->addBlock($block);
                $blocks[$this->refOf($blockRaw, $ref . '.b' . $i)] = $block;
            }
        }

        return $col;
    }

    /**
     * The payload's own ref wins; the positional fallback is what an export
     * predating the key would have been given.
     *
     * @param array<string, mixed> $raw
     */
    private function refOf(array $raw, string $fallback): string
    {
        $ref = $raw['ref'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : $fallback;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return Block|null null when the block's type is not registered here
     */
    private function buildBlock(array $raw, AssetRewriter $assets, BlockRestoreTally $tally): ?Block
    {
        $type = $raw['type'] ?? null;
        if (is_string($type) && !$this->registry->has($type)) {
            // Neither refused (a type this app lacks is expected from another
            // install) nor imported (it would leave an inert block).
            $tally->skip($type);

            return null;
        }

        $block = new Block();
        if (is_string($type)) {
            $block->setType($type);
        }

        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            if (is_string($type)) {
                $tally->noteUnknownKeys($type, $this->dataKeys->unknownIn($type, $data));
            }
            /** @var array<string, mixed> $rewritten */
            $rewritten = $assets->rewrite($data);
            // Kept verbatim, unknown keys included — those warn, never drop.
            $block->setDraftData($rewritten);
        }

        $tally->keep();

        return $block;
    }
}
