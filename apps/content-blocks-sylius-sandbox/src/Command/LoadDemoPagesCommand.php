<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Page;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds demo pages that exercise every kit block type. Idempotent: re-running
 * deletes the previously seeded demo pages (slug prefix `demo-`) first.
 */
#[AsCommand(name: 'app:demo:load', description: 'Load demo pages showcasing every content block')]
final class LoadDemoPagesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Idempotent reset: drop previously seeded demo pages (cascade removes
        // their ContentArea tree).
        $existing = $this->em->getRepository(Page::class)->createQueryBuilder('p')
            ->where('p.slug LIKE :prefix')->setParameter('prefix', 'demo-%')
            ->getQuery()->getResult();
        foreach ($existing as $page) {
            $this->em->remove($page);
        }
        $this->em->flush();

        $pages = [$this->buildLanding(), $this->buildDocs()];
        $this->em->flush();

        $io->success('Loaded demo pages showcasing every block:');
        $io->listing(array_map(
            static fn (Page $p): string => sprintf('%s — /page/%d (edit at /admin/pages)', $p->getTitle(), $p->getId()),
            $pages,
        ));

        return Command::SUCCESS;
    }

    private function buildLanding(): Page
    {
        $page = $this->page('Demo — Landing showcase', 'demo-landing');
        $area = $page->getContentArea();
        $pos = 0;

        // Hero: boxed section, centered title + intro + CTA.
        $hero = $this->section($area, Section::LAYOUT_FULL, $pos++, 'boxed');
        $col = $this->column($hero, 'col-12', 0);
        $b = 0;
        $this->block($col, 'title', ['text' => 'Build pages with ContentBlocks', 'tag' => 'h1'], $b++);
        $this->block($col, 'text', ['content' => 'A modular page builder for Symfony. Every block below ships with the kit — styled, responsive and framework-neutral.'], $b++);
        $this->block($col, 'button', ['text' => 'Get started', 'href' => '#', 'variant' => 'primary', 'size' => 'lg', 'align' => 'center', 'fullWidth' => false, 'newTab' => false], $b++);

        // Feature row: three columns, each icon + title + text.
        $features = $this->section($area, Section::LAYOUT_THREE_COLS, $pos++);
        $feats = [
            ['zap', 'Fast', 'Live components + Stimulus, hot-reloading preview.'],
            ['shield', 'Safe', 'IDOR-protected, CSRF-guarded, secure by default.'],
            ['settings', 'Extensible', 'Custom blocks, palettes and section presets.'],
        ];
        foreach ($feats as $i => [$icon, $title, $text]) {
            $c = $this->column($features, 'col-4', $i);
            $this->block($c, 'icon', ['name' => $icon, 'color' => '#4f46e5', 'size' => 44, 'align' => 'center'], 0);
            $this->block($c, 'title', ['text' => $title, 'tag' => 'h3'], 1);
            $this->block($c, 'text', ['content' => $text], 2);
        }

        // Image with a caption, centered.
        $imgSection = $this->section($area, Section::LAYOUT_FULL, $pos++);
        $col = $this->column($imgSection, 'col-12', 0);
        $this->block($col, 'image', [
            'src' => $this->placeholder('Hero image', 960, 420, '#4f46e5'),
            'alt' => 'Demo hero',
            'size' => 'lg', 'customWidth' => 600, 'customHeightAuto' => true, 'customHeight' => 400,
            'fit' => 'cover', 'align' => 'center', 'link' => '', 'caption' => 'An image block with a caption.',
            'borderRadius' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12, 'linked' => true],
        ], 0);

        // Gallery (airy section).
        $gallerySection = $this->section($area, Section::LAYOUT_FULL, $pos++, 'airy');
        $col = $this->column($gallerySection, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Gallery', 'tag' => 'h2'], 0);
        $this->block($col, 'gallery', [
            'layout' => 'grid', 'columns' => 3, 'fit' => 'cover',
            'borderRadius' => ['top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8, 'linked' => true],
            'items' => [
                ['src' => $this->placeholder('1', 600, 450, '#6366f1'), 'alt' => 'One', 'caption' => 'First', 'link' => ''],
                ['src' => $this->placeholder('2', 600, 450, '#8b5cf6'), 'alt' => 'Two', 'caption' => 'Second', 'link' => ''],
                ['src' => $this->placeholder('3', 600, 450, '#ec4899'), 'alt' => 'Three', 'caption' => 'Third', 'link' => ''],
                ['src' => $this->placeholder('4', 600, 450, '#0ea5e9'), 'alt' => 'Four', 'caption' => 'Fourth', 'link' => ''],
                ['src' => $this->placeholder('5', 600, 450, '#10b981'), 'alt' => 'Five', 'caption' => 'Fifth', 'link' => ''],
                ['src' => $this->placeholder('6', 600, 450, '#f59e0b'), 'alt' => 'Six', 'caption' => 'Sixth', 'link' => ''],
            ],
        ], 1);

        // Cards.
        $cardSection = $this->section($area, Section::LAYOUT_FULL, $pos++);
        $col = $this->column($cardSection, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Plans', 'tag' => 'h2'], 0);
        $this->block($col, 'card', [
            'layout' => 'grid', 'columns' => 3,
            'items' => [
                ['src' => $this->placeholder('Starter', 480, 300, '#e0f2fe'), 'title' => 'Starter', 'content' => "Everything to get going.\nUp to 3 pages.", 'buttonUrl' => '#', 'buttonLabel' => 'Choose'],
                ['src' => $this->placeholder('Pro', 480, 300, '#4f46e5'), 'title' => 'Pro', 'content' => "For growing sites.\nUnlimited pages.", 'buttonUrl' => '#', 'buttonLabel' => 'Choose'],
                ['src' => $this->placeholder('Team', 480, 300, '#111827'), 'title' => 'Team', 'content' => "Collaboration & roles.\nPriority support.", 'buttonUrl' => '#', 'buttonLabel' => 'Choose'],
            ],
        ], 1);

        // Alert + divider + embed.
        $foot = $this->section($area, Section::LAYOUT_FULL, $pos++);
        $col = $this->column($foot, 'col-12', 0);
        $b = 0;
        $this->block($col, 'alert', ['type' => 'success', 'title' => 'Heads up', 'message' => 'Every one of these blocks is editable in the builder.'], $b++);
        $this->block($col, 'divider', ['style' => 'dashed', 'color' => '#4f46e5'], $b++);
        $this->block($col, 'embed', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Demo video'], $b++);

        return $page;
    }

    private function buildDocs(): Page
    {
        $page = $this->page('Demo — Docs & FAQ', 'demo-docs');
        $area = $page->getContentArea();
        $pos = 0;

        // Breadcrumb + title.
        $head = $this->section($area, Section::LAYOUT_FULL, $pos++);
        $col = $this->column($head, 'col-12', 0);
        $this->block($col, 'breadcrumb', ['items' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Docs', 'url' => '/page/demo-docs'],
            ['label' => 'Getting started', 'url' => ''],
        ]], 0);
        $this->block($col, 'title', ['text' => 'Documentation', 'tag' => 'h1'], 1);
        $this->block($col, 'rich_text', ['content' => '<p>This page shows the <strong>text-heavy</strong> blocks. Mix and match to build docs, FAQs and articles.</p>'], 2);

        // Two lists side by side.
        $lists = $this->section($area, Section::LAYOUT_TWO_COLS, $pos++, 'boxed');
        $l = $this->column($lists, 'col-6', 0);
        $this->block($l, 'title', ['text' => 'Included', 'tag' => 'h3'], 0);
        $this->block($l, 'list', ['style' => 'check', 'items' => [
            ['text' => '17 self-contained blocks'],
            ['text' => 'Per-block enable/disable'],
            ['text' => 'Palette color picker'],
        ]], 1);
        $r = $this->column($lists, 'col-6', 1);
        $this->block($r, 'title', ['text' => 'Steps', 'tag' => 'h3'], 0);
        $this->block($r, 'list', ['style' => 'numbered', 'items' => [
            ['text' => 'Install the packages'],
            ['text' => 'Link the kit stylesheet'],
            ['text' => 'Add a ContentArea to your entity'],
        ]], 1);

        // FAQ accordion.
        $faq = $this->section($area, Section::LAYOUT_FULL, $pos++);
        $col = $this->column($faq, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'FAQ', 'tag' => 'h2'], 0);
        $this->block($col, 'accordion', ['exclusive' => true, 'items' => [
            ['title' => 'Does the kit need Tailwind?', 'content' => "No. The kit ships its own stylesheet and neutral classes."],
            ['title' => 'Can I disable a block?', 'content' => "Yes — content_blocks_kit.blocks.<type>.enabled: false."],
            ['title' => 'How are colors themed?', 'content' => "Via the content_blocks.palette config, reused across blocks."],
        ]], 1);

        // Table.
        $tableSection = $this->section($area, Section::LAYOUT_FULL, $pos++);
        $col = $this->column($tableSection, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Comparison', 'tag' => 'h2'], 0);
        $this->block($col, 'table', [
            'striped' => true,
            'columns' => [
                ['label' => 'Feature', 'align' => 'left'],
                ['label' => 'Starter', 'align' => 'center'],
                ['label' => 'Pro', 'align' => 'center'],
            ],
            'rows' => [
                ['cells' => [['content' => 'Pages'], ['content' => '3'], ['content' => 'Unlimited']]],
                ['cells' => [['content' => 'Custom blocks'], ['content' => '—'], ['content' => 'Yes']]],
                ['cells' => [['content' => 'Support'], ['content' => 'Email'], ['content' => 'Priority']]],
            ],
        ], 1);

        // Tabs + raw HTML callout.
        $tabsSection = $this->section($area, Section::LAYOUT_FULL, $pos++, 'airy');
        $col = $this->column($tabsSection, 'col-12', 0);
        $this->block($col, 'tabs', ['tabs' => [
            ['title' => 'Overview', 'content' => 'The tabs block groups related content behind CSS-only tabs.'],
            ['title' => 'Details', 'content' => 'No JavaScript required — pure radio + sibling selectors.'],
        ]], 0);
        $this->block($col, 'html_raw', ['html' => '<div style="padding:1rem;border:1px dashed #4f46e5;border-radius:8px;color:#4f46e5">Raw HTML block — full control when you need it.</div>'], 1);

        return $page;
    }

    // ---------- builders ----------

    private function page(string $title, string $slug): Page
    {
        $page = new Page();
        $page->setTitle($title);
        $page->setSlug($slug);
        $page->setEnabled(true);
        $page->setContentArea(new ContentArea());
        $this->em->persist($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $settingsExtra
     */
    private function section(ContentArea $area, string $layout, int $position, ?string $styleName = null, array $settingsExtra = []): Section
    {
        $section = new Section();
        $section->setContentArea($area);
        $section->setLayout($layout);
        $section->setPosition($position);
        $section->setPreviewPosition($position);
        $settings = $settingsExtra;
        if ($styleName !== null) {
            $settings['styleName'] = $styleName;
        }
        if ($settings !== []) {
            $section->setDraftSettings($settings);
            $section->setPublishedSettings($settings);
        }
        $section->publish();
        $area->addSection($section);

        return $section;
    }

    private function column(Section $section, string $preset, int $position): Column
    {
        $column = new Column();
        $column->setSection($section);
        $column->setPreset($preset);
        $column->setPosition($position);
        $column->setPreviewPosition($position);
        $column->publish();
        $section->addColumn($column);

        return $column;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function block(Column $column, string $type, array $data, int $position): Block
    {
        $block = new Block();
        $block->setColumn($column);
        $block->setType($type);
        $block->setDraftData($data);
        $block->setPublishedData($data);
        $block->setPosition($position);
        $block->setPreviewPosition($position);
        $block->publish();
        $column->addBlock($block);

        return $block;
    }

    /** A self-contained SVG placeholder image as a data URI (no assets needed). */
    private function placeholder(string $label, int $w, int $h, string $bg): string
    {
        $fg = '#ffffff';
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">'
            . '<rect width="100%%" height="100%%" fill="%s"/>'
            . '<text x="50%%" y="50%%" fill="%s" font-family="sans-serif" font-size="%d" '
            . 'text-anchor="middle" dominant-baseline="middle">%s</text></svg>',
            $w, $h, $w, $h, $bg, $fg, (int) max(16, min($w, $h) / 6), htmlspecialchars($label, \ENT_QUOTES),
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
