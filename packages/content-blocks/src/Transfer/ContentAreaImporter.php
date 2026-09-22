<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\BlockRestoreTally;
use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Section\ColumnSettings;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;
use Symfony\Component\Mime\MimeTypes;

/**
 * Default {@see ContentAreaImporterInterface} — see it for the contract.
 * Asset binaries are re-stored through {@see AssetResolverInterface}.
 */
final class ContentAreaImporter implements ContentAreaImporterInterface
{
    /**
     * @param iterable<ContentAreaTransferExtensionInterface> $extensions
     * @param list<string> $uploadAllowedMimeTypes
     */
    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataKeys $dataKeys,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
        private readonly iterable $extensions = [],
        private readonly ?CollectionIdBackfiller $collectionIds = null,
        private readonly int $uploadMaxSize = 10 * 1024 * 1024,
        private readonly array $uploadAllowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
        ],
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
            [...$this->unreadablePaths($payload), ...$assets->unresolved()],
        );
    }

    /**
     * Stored paths the payload carries as-is (an export without its media, or
     * a file missing at export) that this installation cannot read.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function unreadablePaths(array $payload): array
    {
        $missing = [];
        $collector = new AssetReferenceCollector($this->assetResolver);
        $collector->map(
            [$payload['contentArea'], $payload['extensions'] ?? null],
            function (string $path) use (&$missing): string {
                if (!isset($missing[$path]) && $this->assetResolver->read($path) === null) {
                    $missing[$path] = true;
                }

                return $path;
            },
        );

        return array_keys($missing);
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

        // Every entry is checked before any is stored: a refused payload
        // leaves no file behind. Decoded twice so only one blob is held.
        $extensions = [];
        foreach ($assetsRaw as $hash => $asset) {
            if (!is_string($hash) || !is_array($asset)) {
                throw new \InvalidArgumentException('Malformed asset entry.');
            }
            $extensions[$hash] = $this->checkAsset($hash, $this->decodeAsset($hash, $asset), $asset['extension']);
        }

        $map = [];
        foreach ($extensions as $hash => $extension) {
            $map[$hash] = $this->assetResolver->store($this->decodeAsset($hash, $assetsRaw[$hash]), $extension);
        }

        return $map;
    }

    /**
     * @param array<mixed> $asset
     */
    private function decodeAsset(string $hash, array $asset): string
    {
        $data = $asset['data'] ?? null;
        if (!is_string($data) || !is_string($asset['extension'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('Malformed asset entry for %s.', $hash));
        }
        $binary = base64_decode($data, true);
        if ($binary === false) {
            throw new \InvalidArgumentException(sprintf('Invalid base64 data for asset %s.', $hash));
        }

        return $binary;
    }

    /**
     * The upload endpoint's policy, applied to the bytes: the payload's own
     * `mimeType` and `extension` are claims, never trusted.
     *
     * @see docs/internals/transfer.md#an-imported-file-is-an-upload
     */
    private function checkAsset(string $hash, string $binary, string $claimed): string
    {
        if (\strlen($binary) > $this->uploadMaxSize) {
            throw new \InvalidArgumentException(sprintf('Asset %s is too large (max %d MB).', $hash, intdiv($this->uploadMaxSize, 1024 * 1024), ));
        }

        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($binary);
        if (!is_string($mime) || !\in_array($mime, $this->uploadAllowedMimeTypes, true)) {
            throw new \InvalidArgumentException(sprintf('Asset %s: file type "%s" is not allowed.', $hash, is_string($mime) ? $mime : 'unknown', ));
        }

        $known = MimeTypes::getDefault()->getExtensions($mime);
        $claimed = strtolower(ltrim($claimed, '.'));
        if (\in_array($claimed, $known, true)) {
            return $claimed;
        }
        if ($known === []) {
            throw new \InvalidArgumentException(sprintf('Asset %s: no extension for "%s".', $hash, $mime));
        }

        return $known[0];
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
        $settings = ColumnSettings::sanitize($raw['settings'] ?? null);
        if ($settings !== []) {
            $col->setDraftSettings($settings);
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
            // Kept verbatim, unknown keys included (they warn, never drop). Ids
            // the export carried stay: translations are keyed on them.
            $block->setDraftData(is_string($type) && $this->collectionIds !== null
                ? $this->collectionIds->backfill($type, $rewritten)
                : $rewritten);
        }

        $tally->keep();

        return $block;
    }
}
