<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Command;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Kit\Block\AbstractKitBlock;
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

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly BlockTypeRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'Restrict output to a single block type (e.g. "button").')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale used to render translated block labels.', 'en')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "txt" (human) or "json" (machine, e.g. to generate docs).', 'txt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = (string) $input->getOption('locale');
        $format = (string) $input->getOption('format');

        if (!\in_array($format, ['txt', 'json'], true)) {
            $io->error(sprintf('Unknown format "%s". Use "txt" or "json".', $format));

            return Command::INVALID;
        }

        $blocks = ContentBlocksKitBundle::BLOCKS;
        $type = $input->getArgument('type');
        if (null !== $type) {
            if (!isset($blocks[$type])) {
                $io->error(sprintf('Unknown block type "%s". Known types: %s', $type, implode(', ', array_keys($blocks))));

                return Command::INVALID;
            }
            $blocks = [$type => $blocks[$type]];
        }

        if ('json' === $format) {
            $output->writeln($this->toJson($blocks, $locale));

            return Command::SUCCESS;
        }

        $io->title('ContentBlocks Kit — block library');
        $io->text([
            sprintf('%d block(s). Configure each under <info>content_blocks_kit.blocks.\<type\></info>:', \count($blocks)),
            '  <comment>enabled</comment> (bool) · <comment>options</comment> (knobs) · <comment>choices</comment> (restrict or replace a select) · <comment>defaults</comment> (initial values)',
        ]);

        foreach ($blocks as $blockType => $class) {
            // The registered instance carries this app's config; the bare one
            // is the kit as shipped. Preferring the former is what makes the
            // command usable for checking an override actually took.
            $registered = $this->registry->has($blockType) ? $this->registry->get($blockType) : null;
            $this->renderBlock($io, $blockType, $registered instanceof AbstractKitBlock ? $registered : new $class(), $locale);
        }

        return Command::SUCCESS;
    }

    /**
     * Machine-readable description of the whole library, keyed by block type.
     * Consumed by the docs generator so the per-block reference pages never
     * drift from the code. Each entry carries the block's label, its
     * disabled-by-default flag, and the same `options` / `choices` / `defaults`
     * surface {@see \ContentBlocks\Kit\Block\AbstractKitBlock::describe()} exposes —
     * with each choice field flattened to its ordered value list plus the
     * default value (the `*` marker of the text output, made explicit).
     *
     * @param array<string, class-string> $blocks
     */
    private function toJson(array $blocks, string $locale): string
    {
        $out = [];
        foreach ($blocks as $type => $class) {
            /** @var \ContentBlocks\Kit\Block\AbstractKitBlock $block */
            $block = new $class();
            $desc = $block->describe();
            $label = $block::getLabel();

            $choices = [];
            foreach ($desc['choices'] as $field => $map) {
                $choices[$field] = [
                    'values' => array_values($map),
                    'default' => $desc['defaults'][$field] ?? null,
                ];
            }

            $out[$type] = [
                'type' => $type,
                'label' => $label instanceof TranslatableInterface ? $label->trans($this->translator, $locale) : (string) $label,
                'disabledByDefault' => \in_array($type, ContentBlocksKitBundle::DEFAULT_DISABLED, true),
                'options' => (object) $desc['options'],
                'choices' => (object) $choices,
                'defaults' => (object) $desc['defaults'],
            ];
        }

        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function renderBlock(SymfonyStyle $io, string $type, object $block, string $locale): void
    {
        /** @var \ContentBlocks\Kit\Block\AbstractKitBlock $block */
        $coded = $block->describe();
        $desc = $block->describeConfigured();
        $label = $block::getLabel();
        $labelStr = $label instanceof TranslatableInterface ? $label->trans($this->translator, $locale) : (string) $label;

        $suffix = \in_array($type, ContentBlocksKitBundle::DEFAULT_DISABLED, true) ? '  (disabled by default — opt in with enabled: true)' : '';
        $io->section(sprintf('%s — %s%s', $type, $labelStr, $suffix));

        // Named explicitly rather than left to be inferred: the whole reason a
        // host runs this command after editing config is to find out whether
        // what they wrote took effect.
        $overridden = array_keys(array_filter(
            $desc['choices'],
            static fn (array $map, string $field): bool => array_values($map) !== array_values($coded['choices'][$field] ?? []),
            \ARRAY_FILTER_USE_BOTH,
        ));
        if ([] !== $overridden) {
            $io->writeln(sprintf(
                ' <comment>config applied</comment> — choices overridden for: %s',
                implode(', ', $overridden),
            ));
        }

        if ([] !== $desc['options']) {
            $io->writeln(' <info>options</info>');
            $io->table(
                ['option', 'default'],
                array_map(static fn ($k, $v): array => [$k, self::stringify($v)], array_keys($desc['options']), $desc['options']),
            );
        }

        if ([] !== $desc['choices']) {
            $io->writeln(' <info>choices</info>  (host: <comment>choices: { field: [values] }</comment> to restrict, <comment>choices: { field: { value: label } }</comment> to replace — <comment>*</comment> marks the default)');
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
