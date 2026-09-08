<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Command;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\MachineTranslator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Machine-translate one area, or every area. Bulk counterpart of the workbench
 * button, on the same translator and the same draft-only writes.
 *
 * @see docs/internals/i18n.md#machine-translation-is-a-seam
 */
#[AsCommand(
    name: 'content-blocks:i18n:translate',
    description: 'Machine-translate content areas into the configured target locales.',
)]
final class TranslateAreaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MachineTranslator $translator,
        private readonly TranslationLocales $locales,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('area', InputArgument::OPTIONAL, 'Content area id; omit for every area')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target locale; repeatable. Defaults to every configured target.')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider name; defaults to the configured one')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Also re-translate fields that are already translated and current')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be translated without calling the provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $requested */
        $requested = $input->getOption('locale');
        $targets = $requested === [] ? $this->locales->getTargetLocales() : $requested;

        if ($targets === []) {
            $io->warning('No target locales configured (content_blocks_i18n.locales).');

            return Command::SUCCESS;
        }

        foreach ($targets as $locale) {
            if (!$this->locales->isTarget($locale)) {
                $io->error(sprintf(
                    'Unknown target locale "%s". Configured: %s.',
                    $locale,
                    implode(', ', $this->locales->getTargetLocales()),
                ));

                return Command::FAILURE;
            }
        }

        $areaIds = $this->areaIds($input->getArgument('area'));

        if ($areaIds === []) {
            $io->warning('No content area found.');

            return Command::SUCCESS;
        }

        $overwrite = (bool) $input->getOption('overwrite');
        $provider = $input->getOption('provider');
        $provider = \is_string($provider) && $provider !== '' ? $provider : null;

        if ($input->getOption('dry-run')) {
            $io->note('Dry run — no provider call, nothing written.');
            $io->text(sprintf(
                '%d area(s) × %d locale(s): %s',
                \count($areaIds),
                \count($targets),
                implode(', ', $targets),
            ));

            return Command::SUCCESS;
        }

        $translated = 0;
        $failed = 0;

        // Ids, re-fetched after every clear: a row created against a detached
        // block is a "new entity found through the relationship" at flush.
        foreach ($areaIds as $areaId) {
            foreach ($targets as $locale) {
                $area = $this->em->find(ContentArea::class, $areaId);

                if ($area === null) {
                    continue;
                }

                $result = $this->translator->translateArea($area, $locale, $overwrite, $provider);

                // Flushed per area/locale so a provider failure halfway through
                // keeps the work already done.
                $this->em->flush();
                $this->em->clear();

                $translated += $result->getTranslatedCount();
                $failed += $result->getFailedCount();

                if ($result->isEmpty()) {
                    continue;
                }

                $io->writeln(sprintf(
                    '  area <info>%s</info> · <info>%s</info> — %d translated, %d failed, %d skipped',
                    $areaId,
                    $locale,
                    $result->getTranslatedCount(),
                    $result->getFailedCount(),
                    $result->skipped,
                ));

                foreach ($result->failed as $ref => $error) {
                    $io->writeln(sprintf('    <comment>%s</comment>: %s', $ref, $error));
                }
            }
        }

        $io->success(sprintf('%d field(s) translated, %d failed.', $translated, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<int> */
    private function areaIds(mixed $id): array
    {
        if ($id !== null) {
            return [(int) $id];
        }

        /** @var list<array{id: int}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('a.id')
            ->from(ContentArea::class, 'a')
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }
}
