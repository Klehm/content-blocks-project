<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Command;

use ContentBlocks\Kit\ContentBlocksKitBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Documents the kit's block library and its host-configurable surface, read
 * straight from each block's coded schema ({@see \ContentBlocks\Kit\Block\AbstractKitBlock::describe()}),
 * so the output can never drift from what `buildForm()` actually offers.
 *
 * For every block it prints the three levers a host can set under
 * `content_blocks_kit.blocks.<type>`: `options`, `choices` and `defaults`.
 */
#[AsCommand(
    name: 'content-blocks-kit:blocks',
    description: 'List kit blocks and their host-configurable surface (options, choices, defaults).',
)]
final class ListBlocksCommand extends Command
{
    /** Above this, a choice list is truncated in the table to stay readable (e.g. the icon set). */
    private const MAX_CHOICES_SHOWN = 15;

    public function __construct(private readonly TranslatorInterface $translator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'Restrict output to a single block type (e.g. "button").')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale used to render translated block labels.', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = (string) $input->getOption('locale');

        $blocks = ContentBlocksKitBundle::BLOCKS;
        $type = $input->getArgument('type');
        if (null !== $type) {
            if (!isset($blocks[$type])) {
                $io->error(sprintf('Unknown block type "%s". Known types: %s', $type, implode(', ', array_keys($blocks))));

                return Command::INVALID;
            }
            $blocks = [$type => $blocks[$type]];
        }

        $io->title('ContentBlocks Kit — block library');
        $io->text([
            sprintf('%d block(s). Configure each under <info>content_blocks_kit.blocks.\<type\></info>:', \count($blocks)),
            '  <comment>enabled</comment> (bool) · <comment>options</comment> (knobs) · <comment>choices</comment> (restrict a select) · <comment>defaults</comment> (initial values)',
        ]);

        foreach ($blocks as $blockType => $class) {
            $this->renderBlock($io, $blockType, new $class(), $locale);
        }

        return Command::SUCCESS;
    }

    private function renderBlock(SymfonyStyle $io, string $type, object $block, string $locale): void
    {
        /** @var \ContentBlocks\Kit\Block\AbstractKitBlock $block */
        $desc = $block->describe();
        $label = $block::getLabel();
        $labelStr = $label instanceof TranslatableInterface ? $label->trans($this->translator, $locale) : (string) $label;

        $suffix = \in_array($type, ContentBlocksKitBundle::DEFAULT_DISABLED, true) ? '  (disabled by default — opt in with enabled: true)' : '';
        $io->section(sprintf('%s — %s%s', $type, $labelStr, $suffix));

        if ([] !== $desc['options']) {
            $io->writeln(' <info>options</info>');
            $io->table(
                ['option', 'default'],
                array_map(static fn ($k, $v): array => [$k, self::stringify($v)], array_keys($desc['options']), $desc['options']),
            );
        }

        if ([] !== $desc['choices']) {
            $io->writeln(' <info>choices</info>  (host: <comment>choices: { field: [values] }</comment> — <comment>*</comment> marks the default)');
            $rows = [];
            foreach ($desc['choices'] as $field => $map) {
                $rows[] = [$field, self::renderChoiceValues(array_values($map), $desc['defaults'][$field] ?? null)];
            }
            $io->table(['field', 'values'], $rows);
        }

        $io->writeln(' <info>defaults</info>  (host: <comment>defaults: { field: value }</comment>)');
        $io->table(
            ['field', 'default'],
            array_map(static fn ($k, $v): array => [$k, self::stringify($v)], array_keys($desc['defaults']), $desc['defaults']),
        );
    }

    /**
     * Comma-joined choice values, the default flagged with `*`, truncated past
     * {@see self::MAX_CHOICES_SHOWN} so a large set (icons) stays readable.
     *
     * @param list<mixed> $values
     */
    private static function renderChoiceValues(array $values, mixed $default): string
    {
        $total = \count($values);
        $shown = \array_slice($values, 0, self::MAX_CHOICES_SHOWN);

        $parts = array_map(
            static fn ($v): string => self::scalar($v) === self::scalar($default) ? self::scalar($v) . ' *' : self::scalar($v),
            $shown,
        );

        if ($total > self::MAX_CHOICES_SHOWN) {
            $parts[] = sprintf('… (+%d more)', $total - self::MAX_CHOICES_SHOWN);
        }

        return implode(', ', $parts);
    }

    private static function stringify(mixed $value): string
    {
        if (\is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[array]';
        }

        return self::scalar($value);
    }

    private static function scalar(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (null === $value) {
            return 'null';
        }
        if ('' === $value) {
            return "''";
        }

        return (string) $value;
    }
}
