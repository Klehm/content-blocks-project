<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\BlockRestoreTally;
use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Content\ContentManipulatorInterface;
use ContentBlocks\Controller\ColumnsController;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Section\RestoredSectionBuilder;
use ContentBlocks\Section\RestoredStructure;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;

/**
 * Default {@see ContentAreaImporterInterface} — see it for the contract.
 * Asset binaries are re-stored through {@see AssetResolverInterface}.
 */
final class ContentAreaImporter implements ContentAreaImporterInterface
{
    private readonly RestoredSectionBuilder $builder;

    private readonly ContentManipulatorInterface $content;

    /**
     * @param iterable<ContentAreaTransferExtensionInterface> $extensions
     */
    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
        BlockTypeRegistry $registry,
        BlockDataKeys $dataKeys,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
        private readonly iterable $extensions = [],
        ?CollectionIdBackfiller $collectionIds = null,
        private readonly AssetPolicy $policy = new AssetPolicy(),
        SectionLayoutRegistry $layouts = new SectionLayoutRegistry(),
        ?ContentManipulatorInterface $content = null,
    ) {
        $this->builder = new RestoredSectionBuilder($registry, $dataKeys, $collectionIds, $layouts);
        $this->content = $content ?? new ContentManipulator(null, $registry, $layouts);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $storedAssets
     */
    public function import(ContentArea $target, array $payload, array $storedAssets = []): ImportResult
    {
        $payload = $this->normalizeEnvelope($payload);

        $sectionsRaw = $payload['contentArea']['sections'] ?? null;
        if (!is_array($sectionsRaw)) {
            throw new ImportRefusedException('Missing or invalid "contentArea.sections" in payload.');
        }
        if (RestoredStructure::tooLarge($sectionsRaw)) {
            throw new ImportRefusedException(sprintf('The import holds more than a page can: at most %d sections, %d columns per section and %d blocks.', RestoredStructure::MAX_SECTIONS, ColumnsController::MAX_COLUMNS, RestoredStructure::MAX_BLOCKS, ));
        }

        // After the structure checks: a refused import must store no file.
        $assets = new AssetRewriter(...$this->materializeAssets($payload['assets'] ?? [], $storedAssets));

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
            $section = $this->builder->build($sectionRaw, $tally, $assets->rewrite(...), 's' . $i, $blocks);
            $this->content->insertSection($target, $section, $i);
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
            throw new ImportRefusedException(sprintf('Unsupported format: %s (expected %s).', is_scalar($format) ? (string) $format : '(invalid)', $target, ));
        }

        return $this->envelopes->upgrade($payload, $format, $target);
    }

    /**
     * Where each listed file is on this site: bytes carried inline are checked
     * and stored, the rest come from $storedAssets or fall back to their path.
     *
     * @param array<string, string> $storedAssets
     *
     * @return array{
     *     array<string, string>,
     *     array<string, string>,
     * } found, fallbacks
     */
    private function materializeAssets(mixed $assetsRaw, array $storedAssets): array
    {
        if ($assetsRaw === null || $assetsRaw === []) {
            return [[], []];
        }
        if (!is_array($assetsRaw)) {
            throw new ImportRefusedException('Invalid "assets" section (expected object).');
        }

        // Every inline file is checked before any is stored: a refused payload
        // leaves no file behind. Decoded twice so only one blob is held.
        $inline = [];
        $found = [];
        $fallbacks = [];
        foreach ($assetsRaw as $hash => $asset) {
            if (!is_string($hash) || !is_array($asset)) {
                throw new ImportRefusedException('Malformed asset entry.');
            }
            if (array_key_exists('data', $asset)) {
                $inline[$hash] = $this->policy->check($hash, $this->decodeAsset($hash, $asset), $asset['extension']);
            } elseif (isset($storedAssets[$hash])) {
                $found[$hash] = $storedAssets[$hash];
            } elseif (is_string($asset['path'] ?? null)) {
                $path = $asset['path'];
                if ($this->holds($path, $hash)) {
                    $found[$hash] = $path;
                } else {
                    $fallbacks[$hash] = $path;
                }
            }
        }

        foreach ($inline as $hash => $extension) {
            $found[$hash] = $this->assetResolver->store($this->decodeAsset($hash, $assetsRaw[$hash]), $extension);
        }

        return [$found, $fallbacks];
    }

    /** Whether this site stores exactly these bytes at $path. */
    private function holds(string $path, string $hash): bool
    {
        if (!$this->assetResolver->isAssetPath($path)) {
            return false;
        }
        $binary = $this->assetResolver->read($path);

        return $binary !== null && hash_equals($hash, hash('sha256', $binary));
    }

    /**
     * @param array<mixed> $asset
     */
    private function decodeAsset(string $hash, array $asset): string
    {
        $data = $asset['data'] ?? null;
        if (!is_string($data) || !is_string($asset['extension'] ?? null)) {
            throw new ImportRefusedException(sprintf('Malformed asset entry for %s.', $hash));
        }
        $binary = base64_decode($data, true);
        if ($binary === false) {
            throw new ImportRefusedException(sprintf('Invalid base64 data for asset %s.', $hash));
        }

        return $binary;
    }
}
