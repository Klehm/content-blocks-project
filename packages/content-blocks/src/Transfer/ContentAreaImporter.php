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
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function import(ContentArea $target, array $payload): ImportResult
    {
        $this->assertFormat($payload);

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
     * @param array<string, mixed> $payload
     */
    private function assertFormat(array $payload): void
    {
        // Validated against the *interface* constant: a host that swaps the
        // exporter must not leave the importer checking the shipped class.
        $format = $payload['format'] ?? null;
        if ($format !== ContentAreaExporterInterface::FORMAT) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported format: %s (expected %s).',
                is_scalar($format) ? (string) $format : '(invalid)',
                ContentAreaExporterInterface::FORMAT,
            ));
        }
    }

    /**
     * Decodes every asset blob, stores it via the resolver, and returns a
     * map of hash → new public path that the rewriter uses to patch
     * `asset://` tokens.
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
     * @param array<string, mixed>                                      $raw
     * @param array<string, string>                                     $assetMap
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
     * @param array<string, mixed>                                      $raw
     * @param array<string, string>                                     $assetMap
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
     * @param array<string, mixed>                                      $raw
     * @param array<string, string>                                     $assetMap
     *
     * @return Block|null null when the block's type is not registered here
     */
    private function buildBlock(array $raw, array $assetMap, BlockRestoreTally $tally): ?Block
    {
        $type = $raw['type'] ?? null;
        if (is_string($type) && !$this->registry->has($type)) {
            // Skipped: not refused (the payload comes from another install, so
            // a type this app lacks is expected) and not imported either (it
            // would leave an inert block). See ImportResult.
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
     * Recursively rewrites every `asset://{hash}` string to its
     * newly-uploaded public path. Unknown hashes are left as-is so the
     * problem surfaces in the UI rather than vanishing silently.
     *
     * @param array<string, string> $assetMap
     */
    private function rewriteAssets(mixed $value, array $assetMap): mixed
    {
        if (is_string($value) && str_starts_with($value, self::ASSET_TOKEN_PREFIX)) {
            $hash = substr($value, \strlen(self::ASSET_TOKEN_PREFIX));

            return $assetMap[$hash] ?? $value;
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
