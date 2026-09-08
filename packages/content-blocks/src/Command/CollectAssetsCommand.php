<?php

declare(strict_types=1);

namespace ContentBlocks\Command;

use ContentBlocks\Asset\AssetGarbageCollector;
use ContentBlocks\Asset\AssetGarbageReport;
use ContentBlocks\Storage\StoredAsset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports — and, with `--force`, deletes — uploaded files that no stored
 * content references any more.
 *
 * A command rather than anything automatic, for two reasons that are not going
 * to change:
 *
 * - The upload directory is not necessarily the package's alone. A host that
 *   points `content_blocks.upload.directory` at a shared folder, or that
 *   stores its own entities' images there, needs to have registered an
 *   {@see \ContentBlocks\Asset\AssetReferenceProviderInterface} first. Nothing
 *   should be deleting files on a schedule the operator never chose.
 * - Deletion is irreversible and reference detection is a heuristic on
 *   host-shaped JSON. `--dry-run` being the *default* — and `--force` the
 *   opt-in — is the whole safety design; do not invert it.
 *
 * @internal The command name and its options are the contract, not this class.
 *           See FREEZE-AUDIT.md.
 */
#[AsCommand(
    name: 'content-blocks:assets:gc',
    description: 'Report (or delete, with --force) uploaded files no content references any more.',
)]
final class CollectAssetsCommand extends Command
{
    /** Rows printed in table mode before the listing is summarized. */
    private const PREVIEW_ROWS = 20;

    public function __construct(
        private readonly AssetGarbageCollector $collector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Actually delete the unreferenced files. Without it the command only reports.',
            )
            ->addOption(
                'retention',
                null,
                InputOption::VALUE_REQUIRED,
                'Spare files modified within this many days — the window that protects an upload whose block has not been saved yet.',
                (string) AssetGarbageCollector::DEFAULT_RETENTION_DAYS,
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: "table" or "json".',
                'table',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');

        if (!\in_array($format, ['table', 'json'], true)) {
            $io->error(sprintf('Unknown format "%s" — expected "table" or "json".', $format));

            return Command::INVALID;
        }

        if (!$this->collector->isSupported()) {
            $io->error(
                'The configured file storage cannot list its files, so unreferenced assets cannot be '
                . 'identified. Implement ContentBlocks\\Storage\\AssetInventoryInterface on it '
                . '(LocalFileStorage does), or sweep with your storage provider\'s own tooling.',
            );

            return Command::FAILURE;
        }

        $retention = filter_var($input->getOption('retention'), \FILTER_VALIDATE_INT);
        if ($retention === false || $retention < 0) {
            $io->error('--retention expects a number of days, zero or more.');

            return Command::INVALID;
        }

        $force = (bool) $input->getOption('force');
        $report = $this->collector->collect(dryRun: !$force, retentionDays: $retention);

        if ($format === 'json') {
            $output->writeln((string) json_encode($report->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->renderTable($io, $report);

        return $report->failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function renderTable(SymfonyStyle $io, AssetGarbageReport $report): void
    {
        $io->definitionList(
            ['Files scanned' => (string) $report->scanned],
            ['Referenced paths' => (string) $report->referencedPaths],
            ['Unreferenced' => sprintf('%d (%s)', \count($report->swept), self::humanBytes($report->reclaimedBytes()))],
            ['Held by retention' => sprintf(
                '%d (%s, younger than %d day%s)',
                \count($report->withheld),
                self::humanBytes($report->withheldBytes()),
                $report->retentionDays,
                $report->retentionDays === 1 ? '' : 's',
            )],
        );

        if ($report->swept !== []) {
            $io->table(
                ['Path', 'Size', 'Last modified'],
                array_map(
                    static fn (StoredAsset $a) => [
                        $a->publicPath,
                        self::humanBytes($a->size),
                        $a->lastModifiedAt->format('Y-m-d H:i'),
                    ],
                    \array_slice($report->swept, 0, self::PREVIEW_ROWS),
                ),
            );

            $hidden = \count($report->swept) - self::PREVIEW_ROWS;
            if ($hidden > 0) {
                $io->writeln(sprintf('  … and %d more (use --format=json for the full list).', $hidden));
                $io->newLine();
            }
        }

        foreach ($report->failed as $path) {
            $io->warning(sprintf('Could not delete %s — check permissions.', $path));
        }

        if ($report->dryRun) {
            $io->note(sprintf(
                'Dry run — nothing was deleted. Re-run with --force to reclaim %s.',
                self::humanBytes($report->reclaimedBytes()),
            ));

            return;
        }

        $io->success(sprintf(
            'Deleted %d file%s, reclaiming %s.',
            \count($report->swept),
            \count($report->swept) === 1 ? '' : 's',
            self::humanBytes($report->reclaimedBytes()),
        ));
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return $unit === 0
            ? sprintf('%d %s', (int) $value, $units[$unit])
            : sprintf('%.1f %s', $value, $units[$unit]);
    }
}
