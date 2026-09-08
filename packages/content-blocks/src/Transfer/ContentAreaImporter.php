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
    /** Token prefix produced by the exporter for embedded assets. */
    private const ASSET_TOKEN_PREFIX = 'asset://';

    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataKeys $dataKeys,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function import(ContentArea $target, array $payload): ImportResult
    {
        $payload = $this->normalizeEnvelope($payload);

        $assetMap = $this->materializeAssets($payload['assets'] ?? []);

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
        foreach (array_values($sectionsRaw) as $i => $sectionRaw) {
            if (!is_array($sectionRaw)) {
                continue;
            }
            $section = $this->buildSection($sectionRaw, $assetMap, $tally);
            $section->setPreviewPosition($i);
            $target->addSection($section);
            ++$count;
        }

        return new ImportResult(
            $count,
            $tally->skippedCount(),
            $tally->skippedTypes(),
            $tally->unknownFields(),
        );
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
     * @param array<string, mixed>  $raw
     * @param array<string, string> $assetMap
     */
    private function buildSection(array $raw, array $assetMap, BlockRestoreTally $tally): Section
    {
        $section = new Section();
        if (isset($raw['layout']) && is_string($raw['layout'])) {
            $section->setLayout($raw['layout']);
        }

        $settings = $raw['settings'] ?? null;
        if (is_array($settings) && $settings !== []) {
            $section->setDraftSettings($this->rewriteAssets($settings, $assetMap));
        }

        $cols = $raw['columns'] ?? null;
        if (is_array($cols)) {
            foreach (array_values($cols) as $i => $colRaw) {
                if (!is_array($colRaw)) {
                    continue;
                }
                $col = $this->buildColumn($colRaw, $assetMap, $tally);
                $col->setPreviewPosition($i);
                $section->addColumn($col);
            }
        }

        return $section;
    }

    /**
     * @param array<string, mixed>  $raw
     * @param array<string, string> $assetMap
     */
    private function buildColumn(array $raw, array $assetMap, BlockRestoreTally $tally): Column
    {
        $col = new Column();
        if (isset($raw['preset']) && is_string($raw['preset'])) {
            $col->setPreset($raw['preset']);
        }

        $blocks = $raw['blocks'] ?? null;
        if (is_array($blocks)) {
            // Positions come from the *kept* blocks so a skipped one doesn't
            // leave a hole in the sequence.
            $position = 0;
            foreach (array_values($blocks) as $blockRaw) {
                if (!is_array($blockRaw)) {
                    continue;
                }
                $block = $this->buildBlock($blockRaw, $assetMap, $tally);
                if ($block === null) {
                    continue;
                }
                $block->setPreviewPosition($position++);
                $col->addBlock($block);
            }
        }

        return $col;
    }

    /**
     * @param array<string, mixed>  $raw
     * @param array<string, string> $assetMap
     *
     * @return Block|null null when the block's type is not registered here
     */
    private function buildBlock(array $raw, array $assetMap, BlockRestoreTally $tally): ?Block
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
            // Kept verbatim, unknown keys included — those warn, never drop.
            $block->setDraftData($this->rewriteAssets($data, $assetMap));
        }

        $tally->keep();

        return $block;
    }

    /**
     * Rewrites every `asset://{hash}` token to its new public path, whether it
     * is the whole value or sits inside markup. Unknown hashes are left as-is.
     *
     * @see docs/internals/transfer.md#assets-travel-as-bytes-not-paths
     *
     * @param array<string, string> $assetMap
     */
    private function rewriteAssets(mixed $value, array $assetMap): mixed
    {
        if (is_string($value) && str_starts_with($value, self::ASSET_TOKEN_PREFIX)) {
            $hash = substr($value, \strlen(self::ASSET_TOKEN_PREFIX));

            return $assetMap[$hash] ?? $value;
        }

        if (is_string($value) && str_contains($value, self::ASSET_TOKEN_PREFIX)) {
            return preg_replace_callback(
                '#' . preg_quote(self::ASSET_TOKEN_PREFIX, '#') . '([A-Za-z0-9_-]+)#',
                static fn (array $m) => $assetMap[$m[1]] ?? $m[0],
                $value,
            ) ?? $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->rewriteAssets($v, $assetMap);
            }

            return $out;
        }

        return $value;
    }
}
