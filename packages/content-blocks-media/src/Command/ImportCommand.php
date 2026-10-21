<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Command;

use ContentBlocks\Asset\AssetReferenceProviderInterface;
use ContentBlocks\Media\Asset\LibraryAssetReferenceProvider;
use ContentBlocks\Media\Library\MediaKind;
use ContentBlocks\Media\Storage\MediaRecord;
use ContentBlocks\Media\Storage\MediaRows;
use ContentBlocks\Media\Storage\UploadRecorder;
use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings the files stored before the library existed into it. By default only
 * the ones some content uses: the library keeps a file from the GC for good.
 *
 * @see docs/guide/media-library.md#files-uploaded-before-the-library
 */
#[AsCommand(
    name: 'content-blocks-media:import',
    description: 'Add the files already in storage to the media library',
)]
final class ImportCommand extends Command
{
    /**
     * @param iterable<AssetReferenceProviderInterface> $referenceProviders
     */
    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly MediaRows $rows,
        private readonly UploadRecorder $recorder,
        private readonly iterable $referenceProviders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also import files no content references')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be imported, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->storage instanceof AssetInventoryInterface) {
            $io->error(sprintf('Storage "%s" cannot list its files (no %s), so there is nothing to import from.', $this->storage::class, AssetInventoryInterface::class));

            return Command::FAILURE;
        }

        $all = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');
        $referenced = $all ? [] : $this->referencedPaths();

        $imported = 0;
        $skipped = 0;
        foreach ($this->storage->listAssets() as $asset) {
            if ($this->rows->has($asset->publicPath)) {
                continue;
            }
            if (!$all && !isset($referenced[$asset->publicPath])) {
                ++$skipped;

                continue;
            }

            if ($dryRun) {
                $io->writeln($asset->publicPath, OutputInterface::VERBOSITY_VERBOSE);
                ++$imported;

                continue;
            }

            if ($this->recorder->record($this->describe($asset->publicPath, $asset->size, $asset->lastModifiedAt))) {
                $io->writeln($asset->publicPath, OutputInterface::VERBOSITY_VERBOSE);
                ++$imported;
            }
        }

        $io->success(sprintf(
            '%s %d file(s) to the library.%s',
            $dryRun ? 'Would add' : 'Added',
            $imported,
            $skipped > 0 ? sprintf(' %d file(s) no content uses were left out (--all to take them).', $skipped) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * The library's own provider is left out: it would vouch for itself.
     *
     * @return array<string, true>
     */
    private function referencedPaths(): array
    {
        $paths = [];
        foreach ($this->referenceProviders as $provider) {
            if ($provider instanceof LibraryAssetReferenceProvider) {
                continue;
            }
            foreach ($provider->referencedAssetPaths() as $path) {
                if (\is_string($path) && $path !== '') {
                    $paths[$path] = true;
                }
            }
        }

        return $paths;
    }

    /** Dimensions need the bytes, so only an image is read. */
    private function describe(string $path, int $size, \DateTimeImmutable $storedAt): MediaRecord
    {
        $record = MediaRecord::describe($path, '', null, $size, false, $storedAt);
        if ($record->kind !== MediaKind::IMAGE) {
            return $record;
        }

        $contents = $this->storage->read($path);

        return MediaRecord::describe(
            $path,
            '',
            null,
            $size,
            $contents === null ? false : @getimagesizefromstring($contents),
            $storedAt,
        );
    }
}
