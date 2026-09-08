<?php

declare(strict_types=1);

namespace ContentBlocks\Command;

use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Form\Type\BlockFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Gives collection entries written before `_id` existed their stable identity.
 * Idempotent, so a partial run can simply be repeated.
 *
 * @see docs/internals/clipboard.md#why-the-backfill-is-a-command
 */
#[AsCommand(
    name: 'content-blocks:backfill-collection-ids',
    description: 'Give a stable `_id` to collection entries stored before the key existed.',
)]
final class BackfillCollectionIdsCommand extends Command
{
    /** Flush every N blocks, rather than one giant unit of work. */
    private const BATCH = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
        private readonly CollectionItemIds $collectionItemIds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would change without writing anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $total = (int) $this->em->createQuery(
            'SELECT COUNT(b.id) FROM ' . Block::class . ' b',
        )->getSingleScalarResult();

        // Streamed: a real install has six figures of blocks, and findAll()
        // would hold every one in the identity map for the whole run.
        $blocks = $this->em->createQuery('SELECT b FROM ' . Block::class . ' b')->toIterable();

        $changed = 0;
        $processed = 0;
        $skipped = [];

        foreach ($blocks as $block) {
            ++$processed;

            if (!$this->registry->has($block->getType())) {
                $skipped[$block->getType()] = ($skipped[$block->getType()] ?? 0) + 1;
                $this->flushBatch($processed, $dryRun);

                continue;
            }

            $touched = false;
            foreach (['getDraftData' => 'setDraftData', 'getPublishedData' => 'setPublishedData'] as $get => $set) {
                $data = $block->{$get}();
                if (!\is_array($data) || $data === []) {
                    continue;
                }

                $filled = $this->collectionItemIds->backfill($this->formFor($block->getType(), $data), $data);
                if ($filled !== $data) {
                    $block->{$set}($filled);
                    $touched = true;
                }
            }

            if ($touched) {
                ++$changed;
            }

            $this->flushBatch($processed, $dryRun);
        }

        if ($dryRun) {
            // Nothing must reach the database: drop the in-memory changes.
            $this->em->clear();
            $io->note('Dry run — no changes written.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d block%s %s collection ids (out of %d).',
            $changed,
            $changed === 1 ? '' : 's',
            $dryRun ? 'would gain' : 'gained',
            $total,
        ));

        foreach ($skipped as $type => $count) {
            $io->warning(sprintf(
                'Skipped %d block(s) of unregistered type "%s" — install the block type and re-run.',
                $count,
                $type,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Commits and releases a batch. Both modes clear — a dry run writes
     * nothing but would still fill the identity map.
     */
    private function flushBatch(int $processed, bool $dryRun): void
    {
        if ($processed % self::BATCH !== 0) {
            return;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $this->em->clear();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formFor(string $type, array $data): \Symfony\Component\Form\FormInterface
    {
        // The form is only used for its shape (which children are collections),
        // never submitted, so building it against the stored data is enough.
        return $this->formFactory->create(BlockFormType::class, $data, [
            'block_type' => $this->registry->get($type),
            'block_data' => $data,
        ]);
    }
}
