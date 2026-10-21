<?php

declare(strict_types=1);

namespace ContentBlocks\Command;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Ai\GeneratedContentWriter;
use ContentBlocks\Ai\GenerationRefusedException;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a generated document (the content schema's output) to an area's
 * draft, for a pipeline or an MCP server that called the model itself.
 *
 * @see docs/guide/ai.md#from-your-own-pipeline
 *
 * @internal the command name and its options are the contract, not this class
 */
#[AsCommand(
    name: 'content-blocks:ai:apply',
    description: 'Write a generated {"sections": [...]} document to the draft of a content area.',
)]
final class ApplyGeneratedCommand extends Command
{
    /** Same cap as an import: a document is a page, not a site. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeneratedContentWriter $writer,
        private readonly AiFeatures $features,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('area', InputArgument::REQUIRED, 'Id of the content area.')
            ->addArgument('file', InputArgument::OPTIONAL, 'The JSON document; "-" or none reads stdin.', '-')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Id of the section the content goes after.')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Delete the draft sections first (Discard brings them back).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->features->isEnabled(AiAction::APPLY)) {
            $io->error('Writing generated content is switched off (content_blocks.ai.actions.apply).');

            return Command::FAILURE;
        }

        $areaId = (string) $input->getArgument('area');
        $area = ctype_digit($areaId) ? $this->em->find(ContentArea::class, (int) $areaId) : null;
        if ($area === null) {
            $io->error(sprintf('No content area with id "%s".', $areaId));

            return Command::FAILURE;
        }

        $after = null;
        $afterId = $input->getOption('after');
        if ($afterId !== null) {
            $after = ctype_digit((string) $afterId) ? $this->em->find(Section::class, (int) $afterId) : null;
            if ($after === null || $after->getContentArea() !== $area || $after->isDeleted()) {
                $io->error(sprintf('No live section "%s" in area %s.', (string) $afterId, $areaId));

                return Command::FAILURE;
            }
        }

        $file = (string) $input->getArgument('file');
        $json = @file_get_contents($file === '-' ? 'php://stdin' : $file, false, null, 0, self::MAX_BYTES + 1);
        if ($json === false || \strlen($json) > self::MAX_BYTES) {
            $io->error(sprintf('Cannot read "%s", or it is larger than %d bytes.', $file, self::MAX_BYTES));

            return Command::FAILURE;
        }

        $document = json_decode($json, true);
        if (!\is_array($document)) {
            $io->error('The document is not JSON.');

            return Command::INVALID;
        }

        try {
            $result = $this->writer->write($area, $document, $after, replace: (bool) $input->getOption('replace'));
        } catch (GenerationRefusedException $e) {
            $io->error(sprintf('Refused: %s.', $e->reason));

            return Command::FAILURE;
        }
        $this->em->flush();

        $io->success(sprintf(
            'Wrote %d section(s) and %d block(s) to the draft of area %s. Publish from the builder.',
            \count($result->sections),
            \count($result->blocks),
            $areaId,
        ));
        if ($result->skippedBlockTypes !== []) {
            $io->warning('Skipped unknown block types: ' . implode(', ', $result->skippedBlockTypes));
        }
        foreach ($result->droppedFields as $dropped) {
            $io->warning(sprintf('%s: reset to default: %s', $dropped['blockType'], implode(', ', $dropped['droppedFields'])));
        }

        return Command::SUCCESS;
    }
}
