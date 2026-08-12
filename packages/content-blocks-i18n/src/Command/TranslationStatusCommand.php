<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Command;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Progress\TranslationInspector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Translation progress, per area and locale, on the command line.
 *
 * The reason to have it beyond the UI: this is the view that answers "are we
 * ready to launch the German site?" across every page at once, and it is
 * scriptable — `--incomplete` exits non-zero when anything is missing or
 * outdated, so a release pipeline can gate on it.
 */
#[AsCommand(
    name: 'content-blocks:i18n:status',
    description: 'Show translation progress for one content area or all of them.',
)]
final class TranslationStatusCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslationInspector $inspector,
        private readonly TranslationLocales $locales,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('area', InputArgument::OPTIONAL, 'Content area id; omit for every area')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Restrict to one target locale')
            ->addOption('incomplete', null, InputOption::VALUE_NONE, 'Exit non-zero if anything is missing or outdated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targets = $this->locales->getTargetLocales();

        if ($targets === []) {
            $io->warning('No target locales configured (content_blocks_i18n.locales).');

            return Command::SUCCESS;
        }

        $only = $input->getOption('locale');

        if (\is_string($only) && !$this->locales->isTarget($only)) {
            $io->error(sprintf('Unknown target locale "%s". Configured: %s.', $only, implode(', ', $targets)));

            return Command::FAILURE;
        }

        $areas = $this->areas($input->getArgument('area'));

        if ($areas === []) {
            $io->warning('No content area found.');

            return Command::SUCCESS;
        }

        $rows = [];
        $incomplete = false;

        foreach ($areas as $area) {
            foreach ($this->inspector->progressMatrix($area) as $locale => $progress) {
                if (\is_string($only) && $locale !== $only) {
                    continue;
                }

                // Areas with nothing to translate would otherwise fill the
                // table with 100% rows that say nothing.
                if ($progress->getTotal() === 0) {
                    continue;
                }

                $incomplete = $incomplete || $progress->needsAttention();

                $rows[] = [
                    (string) $area->getId(),
                    $locale,
                    $progress->getPercent() . '%',
                    (string) $progress->translated,
                    $progress->outdated > 0 ? "<comment>{$progress->outdated}</comment>" : '0',
                    $progress->missing > 0 ? "<comment>{$progress->missing}</comment>" : '0',
                    (string) $progress->getTotal(),
                ];
            }
        }

        if ($rows === []) {
            $io->success('Nothing translatable found.');

            return Command::SUCCESS;
        }

        $io->table(['Area', 'Locale', 'Done', 'Translated', 'Outdated', 'Missing', 'Total'], $rows);

        if ($incomplete && $input->getOption('incomplete')) {
            $io->warning('Some content is missing or outdated.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @return list<ContentArea> */
    private function areas(mixed $id): array
    {
        if ($id === null) {
            /** @var list<ContentArea> $all */
            $all = $this->em->getRepository(ContentArea::class)->findBy([], ['id' => 'ASC']);

            return $all;
        }

        $area = $this->em->find(ContentArea::class, (int) $id);

        return $area === null ? [] : [$area];
    }
}
