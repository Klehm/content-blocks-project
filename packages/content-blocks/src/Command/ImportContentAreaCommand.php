<?php

declare(strict_types=1);

namespace ContentBlocks\Command;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads an exported content envelope into an existing area, from the shell.
 *
 * The counterpart of the builder's Export button, which already writes this
 * exact JSON. Together they are the fixture workflow: compose a page in the
 * builder, export it, commit the file, replay it wherever it is needed — a
 * seed script, a demo install, a colleague's database.
 *
 * The area has to exist. A `ContentArea` belongs to a host entity (a Page, a
 * Product…) whose shape this package does not know, so minting one here would
 * only produce the orphan rows the widget goes out of its way to avoid.
 *
 * Like every console command this runs outside a session, so
 * `AccessCheckerInterface` — which answers "may *this user* edit that area?" —
 * has no meaning and is not consulted. Shell access is the authorization.
 */
#[AsCommand(
    name: 'content-blocks:import',
    description: 'Import an exported content envelope into an existing ContentArea.',
)]
final class ImportContentAreaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentAreaImporterInterface $importer,
        private readonly ContentAreaPublisherInterface $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'area-id',
                InputArgument::REQUIRED,
                'Id of the ContentArea to import into. Its current sections are replaced.',
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Path to the JSON envelope produced by the builder\'s Export button.',
            )
            ->addOption(
                'publish',
                null,
                InputOption::VALUE_NONE,
                'Publish the area afterwards. Without it the import stays a draft — visible in the builder, absent from the public page.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Parse and validate the envelope without writing anything.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $publish = (bool) $input->getOption('publish');

        if ($dryRun && $publish) {
            $io->error('--dry-run and --publish are mutually exclusive.');

            return Command::INVALID;
        }

        $areaId = (int) $input->getArgument('area-id');
        $area = $this->em->find(ContentArea::class, $areaId);
        if (!$area instanceof ContentArea) {
            $io->error(sprintf('No ContentArea with id %d.', $areaId));

            return Command::FAILURE;
        }

        $path = (string) $input->getArgument('file');
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            $io->error(sprintf('Cannot read "%s".', $path));

            return Command::FAILURE;
        }

        try {
            $payload = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error('Invalid JSON: ' . $e->getMessage());

            return Command::FAILURE;
        }
        if (!is_array($payload)) {
            $io->error('Invalid payload: expected a JSON object.');

            return Command::FAILURE;
        }

        try {
            $result = $this->importer->import($area, $payload);
        } catch (\InvalidArgumentException $e) {
            // Wrong format, or a content version this build has no upgrade
            // step for. Both are the envelope's problem, not a crash.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            // The importer built an entity graph and materialized assets to
            // disk; drop the graph so none of it reaches the database.
            $this->em->clear();
            $io->note(sprintf('Dry run — %d section(s) would be imported, nothing written.', $result->sectionCount));
        } else {
            $this->em->flush();
        }

        // Reported, never fatal: a partial import the operator can see beats
        // an all-or-nothing failure on a page that is mostly portable.
        if ($result->skippedBlockCount > 0) {
            $io->warning(sprintf(
                'Skipped %d block(s) of unregistered type(s): %s. Install the block type(s) and re-import.',
                $result->skippedBlockCount,
                implode(', ', $result->skippedBlockTypes),
            ));
        }
        if ($result->unknownFields !== []) {
            // Kept, not dropped: see ImportResult on why an undeclared key is
            // judged differently from an unregistered block type.
            $io->warning(sprintf(
                "Kept key(s) no registered block type declares:\n%s",
                implode("\n", array_map(
                    static fn (array $entry): string => sprintf(
                        '  %s: %s',
                        $entry['blockType'],
                        implode(', ', $entry['unknownKeys']),
                    ),
                    $result->unknownFields,
                )),
            ));
        }

        if ($dryRun) {
            return Command::SUCCESS;
        }

        if ($publish) {
            $this->publisher->publish($area);
            $io->success(sprintf(
                'Imported %d section(s) into area #%d and published.',
                $result->sectionCount,
                $areaId,
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Imported %d section(s) into area #%d.', $result->sectionCount, $areaId));
        $io->note('Written as draft: the builder shows it, the public page does not. Re-run with --publish, or publish from the builder.');

        return Command::SUCCESS;
    }
}
