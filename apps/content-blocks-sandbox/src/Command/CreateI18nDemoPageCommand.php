<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Page;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\MachineTranslator;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds a published, multilingual demo page — and, with `--verify`, walks the
 * whole translation lifecycle in one run so the wiring can be checked without
 * clicking through the builder.
 *
 * The interesting part is the order it demonstrates, which is the design's main
 * safety rule: translations are written to the **draft**, so the public page
 * keeps rendering French until a second Publish. A translation can never go
 * live against a source that has not.
 */
#[AsCommand(
    name: 'app:i18n:demo',
    description: 'Create a published demo page, translate it into every target locale, and show the result.',
)]
final class CreateI18nDemoPageCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentAreaPublisherInterface $publisher,
        private readonly MachineTranslator $translator,
        private readonly TranslationInspector $inspector,
        private readonly TranslationLocales $locales,
        private readonly BlockRendererInterface $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Machine-translation provider to use')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Render the page in every locale and print what changed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $page = $this->seed();
        $area = $page->getContentArea();
        $io->success(sprintf('Page #%d created (area #%d).', $page->getId(), $area->getId()));

        // Publish the source first: there is nothing to translate against an
        // area that has never been public.
        $this->publisher->publish($area);

        $provider = $input->getOption('provider');
        $provider = \is_string($provider) && $provider !== '' ? $provider : null;

        foreach ($this->locales->getTargetLocales() as $locale) {
            $result = $this->translator->translateArea($area, $locale, providerName: $provider);
            $this->em->flush();

            $io->writeln(sprintf(
                '  <info>%s</info> — %d translated, %d failed (provider: %s)',
                $locale,
                $result->getTranslatedCount(),
                $result->getFailedCount(),
                $result->provider,
            ));

            foreach ($result->failed as $ref => $error) {
                $io->writeln(sprintf('    <comment>%s</comment>: %s', $ref, $error));
            }
        }

        if ($input->getOption('verify')) {
            $io->section('Public render before publishing the translations');
            $this->renderTable($io, $area);

            $this->publisher->publish($area);

            $io->section('Public render after publishing the translations');
            $this->renderTable($io, $area);
        } else {
            $this->publisher->publish($area);
        }

        $io->section('Progress');
        $rows = [];

        foreach ($this->inspector->progressMatrix($area) as $locale => $progress) {
            $rows[] = [$locale, $progress->getPercent() . '%', $progress->translated, $progress->outdated, $progress->missing];
        }

        $io->table(['Locale', 'Done', 'Translated', 'Outdated', 'Missing'], $rows);

        $io->writeln(sprintf('  Source : /page/%d', $page->getId()));

        foreach ($this->locales->getTargetLocales() as $locale) {
            $io->writeln(sprintf('  %-7s: /%s/page/%d', $locale, $locale, $page->getId()));
        }

        $io->writeln(sprintf('  Builder: /admin/page/%d', $page->getId()));

        return Command::SUCCESS;
    }

    private function renderTable(SymfonyStyle $io, ContentArea $area): void
    {
        $rows = [];

        foreach ([null, ...$this->locales->getTargetLocales()] as $locale) {
            $html = $this->renderer->render($area, RenderContext::forPublic($locale));
            $rows[] = [$locale ?? $this->locales->getSourceLocale() . ' (source)', $this->firstTexts($html)];
        }

        $io->table(['Locale', 'Rendered text'], $rows);
    }

    private function firstTexts(string $html): string
    {
        preg_match_all('/>([^<>]{3,60})</', $html, $matches);

        $texts = array_values(array_filter(array_map('trim', $matches[1]), static fn (string $s): bool => $s !== ''));

        return implode(' · ', \array_slice($texts, 0, 3));
    }

    private function seed(): Page
    {
        $area = new ContentArea();
        $section = new Section();
        $column = new Column();
        $section->addColumn($column);
        $area->addSection($section);

        // Source content is French: the sandbox's `default_locale`, and what
        // `content_blocks_i18n.source_locale` declares.
        $title = new Block();
        $title->setType('title');
        $title->setDraftData(['tag' => 'h2', 'size' => 'h2', 'text' => 'Bienvenue dans notre boutique']);
        $column->addBlock($title);

        $text = new Block();
        $text->setType('text');
        $text->setDraftData(['content' => 'Nous livrons partout en Europe sous 48 heures.']);
        $column->addBlock($text);

        // A collection block, so the demo exercises the part of the schema that
        // keys translations by entry `_id` rather than by position.
        $card = new Block();
        $card->setType('card');
        $card->setDraftData(['items' => [
            [
                '_id' => bin2hex(random_bytes(6)),
                'title' => 'Livraison rapide',
                'content' => 'Expédié le jour même.',
                'url' => '/livraison',
                'buttonText' => 'En savoir plus',
                'src' => '',
            ],
            [
                '_id' => bin2hex(random_bytes(6)),
                'title' => 'Retours gratuits',
                'content' => 'Sous 30 jours, sans justificatif.',
                'url' => '/retours',
                'buttonText' => 'Nos conditions',
                'src' => '',
            ],
        ]]);
        $column->addBlock($card);

        $page = new Page();
        $page->setTitle('Démo multilingue');
        $page->setSlug('demo-i18n-' . bin2hex(random_bytes(3)));
        $page->setContentArea($area);

        $this->em->persist($page);
        $this->em->flush();

        return $page;
    }
}
