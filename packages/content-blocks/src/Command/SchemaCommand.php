<?php

declare(strict_types=1);

namespace ContentBlocks\Command;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Schema\ContentSchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the content schema as JSON, for a host's own generation pipeline or
 * an MCP server.
 *
 * @see docs/guide/content-schema.md
 *
 * @internal the command name and its options are the contract, not this class
 */
#[AsCommand(
    name: 'content-blocks:schema',
    description: 'Print the content schema (block types, layouts, palette, JSON Schema of the output) as JSON.',
)]
final class SchemaCommand extends Command
{
    public function __construct(
        private readonly ContentSchemaBuilder $builder,
        private readonly ?EntityManagerInterface $em = null,
        private readonly int $schemaExamples = 3,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Id of a content area, for its own structure mode.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale of the labels and titles.')
            ->addOption(
                'examples',
                null,
                InputOption::VALUE_REQUIRED,
                'How many saved section templates to include as examples.',
            )
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Print a single key, e.g. "schema" or "blocks".')
            ->addOption('compact', null, InputOption::VALUE_NONE, 'One line, no indentation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $area = null;
        $areaId = $input->getOption('area');
        if ($areaId !== null) {
            $area = ctype_digit((string) $areaId) ? $this->em?->find(ContentArea::class, (int) $areaId) : null;
            if ($area === null) {
                $io->error(sprintf('No content area with id "%s".', (string) $areaId));

                return Command::FAILURE;
            }
        }

        $examples = $input->getOption('examples');
        if ($examples !== null && !ctype_digit((string) $examples)) {
            $io->error('--examples takes a number, 0 for none.');

            return Command::INVALID;
        }

        $locale = $input->getOption('locale');
        $document = $this->builder->build(
            $area,
            \is_string($locale) ? $locale : null,
            $examples !== null ? (int) $examples : $this->schemaExamples,
        )->toArray();

        $only = $input->getOption('only');
        if ($only !== null) {
            if (!\array_key_exists((string) $only, $document)) {
                $keys = implode(', ', array_keys($document));
                $io->error(sprintf('Unknown key "%s"; expected one of: %s.', (string) $only, $keys));

                return Command::INVALID;
            }
            $document = $document[(string) $only];
        }

        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;
        if (!$input->getOption('compact')) {
            $flags |= \JSON_PRETTY_PRINT;
        }
        $output->writeln(json_encode($document, $flags));

        return Command::SUCCESS;
    }
}
