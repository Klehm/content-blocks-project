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
 *
 * A console command rather than a Doctrine migration on purpose: which JSON
 * keys hold a *collection* is knowledge that lives in the block types' forms,
 * and SQL cannot ask them. A migration would have to hard-code a list of block
 * type / field pairs — exactly the drift this package avoids everywhere else,
 * and it would be wrong the moment a host ships its own collection block.
 *
 * It is idempotent: an entry that already carries an id keeps it, so running
 * twice is harmless and a partial run can simply be repeated.
 *
 * Blocks whose type is no longer registered are skipped and reported — there is
 * no form to ask, and minting ids blind would guess at the shape.
 */
#[AsCommand(
    name: 'content-blocks:backfill-collection-ids',
    description: 'Give a stable `_id` to collection entries stored before the key existed.',
)]
final class BackfillCollectionIdsCommand extends Command
{
    /** Flush every N blocks so a large install does not build one giant unit of work. */
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

        // Streamed and cleared in batches: a real install has six figures of
        // blocks, and findAll() would hold every one of them in the identity
        // map for the whole run.
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
     * Commits and releases a batch. Clearing matters as much as flushing: a
     * dry run writes nothing but would still accumulate every block in the
     * identity map, so both modes clear.
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
