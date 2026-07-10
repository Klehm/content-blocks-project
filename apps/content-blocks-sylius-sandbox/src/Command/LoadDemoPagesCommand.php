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
 *
 * The pages are dressed to look like a real marketing site ("Nova"): centered
 * sections with generous vertical rhythm, alternating tinted background bands,
 * real photography (Lorem Picsum, loaded by the browser — no local assets) and
 * copywriting instead of terse placeholders.
 */
#[AsCommand(name: 'app:demo:load', description: 'Load demo pages showcasing every content block')]
final class LoadDemoPagesCommand extends Command
{
    /** Soft indigo tint used for the hero / CTA bands. */
    private const TINT = '#eef2ff';
    /** Neutral slate tint used to alternate section rhythm. */
    private const SLATE = '#f8fafc';
    private const INDIGO = '#4f46e5';

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

        // ---- Hero: text left, photo right, on a tinted full-bleed band. ----
        $hero = $this->section($area, Section::LAYOUT_TWO_COLS, $pos++, null, $this->look(104, 104, self::TINT, 1180, 56, 'center', '54,46'));
        $l = $this->column($hero, 'col-6', 0);
        $this->block($l, 'title', ['text' => 'Composez des pages qui convertissent, sans écrire une ligne de code.', 'tag' => 'h1'], 0);
        $this->block($l, 'text', ['content' => 'Nova réunit sections, colonnes et blocs dans un éditeur visuel en temps réel. Vos équipes marketing publient des landing pages, des articles et des pages produit en quelques minutes — sans jamais ouvrir de ticket au pôle technique.'], 1);
        $this->block($l, 'button', ['text' => 'Démarrer gratuitement', 'href' => '#', 'variant' => 'primary', 'size' => 'lg', 'align' => 'start', 'fullWidth' => false, 'newTab' => false], 2);
        $r = $this->column($hero, 'col-6', 1);
        $this->block($r, 'image', $this->imageData($this->photo('nova-hero-workspace', 1000, 860), 'Une équipe qui construit une page dans Nova', 'full', '', 22), 0);

        // ---- Section intro: heading + subtitle (narrow, left-aligned). ----
        $intro = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(96, 8, '', 820));
        $col = $this->column($intro, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Une boîte à outils complète, prête à l\'emploi', 'tag' => 'h2'], 0);
        $this->block($col, 'text', ['content' => 'Dix-sept blocs autonomes, une palette de couleurs partagée et des présets de section : de quoi bâtir une identité cohérente sans dépendre d\'un framework CSS tiers.'], 1);

        // ---- Features: three columns, icon + title + text. ----
        $features = $this->section($area, Section::LAYOUT_THREE_COLS, $pos++, null, $this->look(24, 96, '', 1180, 48));
        $feats = [
            ['zap', 'Rapide', 'Composants Live et Stimulus, prévisualisation qui se recharge à chaud. Ce que vous voyez est exactement ce que vos visiteurs verront.'],
            ['shield', 'Sécurisé', 'Protection IDOR, jetons CSRF et permissions déléguées à votre application. Sécurisé par défaut, sans configuration piège.'],
            ['settings', 'Extensible', 'Blocs sur mesure, palettes, présets de section : chaque point d\'extension est une interface simple à implémenter.'],
        ];
        foreach ($feats as $i => [$icon, $title, $text]) {
            $c = $this->column($features, 'col-4', $i);
            $this->block($c, 'icon', ['name' => $icon, 'color' => self::INDIGO, 'size' => 44, 'align' => 'start'], 0);
            $this->block($c, 'title', ['text' => $title, 'tag' => 'h3'], 1);
            $this->block($c, 'text', ['content' => $text], 2);
        }

        // ---- Showcase: wide photo with a caption, on a slate band. ----
        $showcase = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(96, 96, self::SLATE, 1120));
        $col = $this->column($showcase, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Voyez vos idées prendre forme', 'tag' => 'h2'], 0);
        $this->block($col, 'rich_text', ['content' => '<p>Glissez un bloc, ajustez son style dans la barre latérale, publiez. L\'aperçu est une <strong>vraie page</strong> rendue par votre application — pas une approximation. Brouillon et version publiée vivent côte à côte&nbsp;: vous préparez la prochaine campagne sans jamais toucher au live.</p>'], 1);
        $this->block($col, 'image', $this->imageData($this->photo('nova-editor-preview', 1600, 760), 'Aperçu de l\'éditeur Nova', 'full', 'L\'éditeur Nova : glissez vos blocs, ajustez, publiez.', 18), 2);

        // ---- Gallery: made-with-Nova grid. ----
        $gallery = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(96, 96, '', 1180));
        $col = $this->column($gallery, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Fait avec Nova', 'tag' => 'h2'], 0);
        $this->block($col, 'text', ['content' => 'Un aperçu de pages construites par nos clients — landing produit, portfolio, page événement ou magazine en ligne.'], 1);
        $this->block($col, 'gallery', [
            'layout' => 'grid', 'columns' => 3, 'fit' => 'cover',
            'borderRadius' => ['top' => 14, 'right' => 14, 'bottom' => 14, 'left' => 14, 'linked' => true],
            'items' => [
                ['src' => $this->photo('nova-gal-studio', 800, 600), 'alt' => 'Page studio créatif', 'caption' => 'Studio Lumen', 'link' => ''],
                ['src' => $this->photo('nova-gal-cafe', 800, 600), 'alt' => 'Page café de quartier', 'caption' => 'Café Nord', 'link' => ''],
                ['src' => $this->photo('nova-gal-travel', 800, 600), 'alt' => 'Page agence de voyage', 'caption' => 'Voyages Méridien', 'link' => ''],
                ['src' => $this->photo('nova-gal-fashion', 800, 600), 'alt' => 'Page mode', 'caption' => 'Atelier Nine', 'link' => ''],
                ['src' => $this->photo('nova-gal-tech', 800, 600), 'alt' => 'Page produit tech', 'caption' => 'Halo Devices', 'link' => ''],
                ['src' => $this->photo('nova-gal-food', 800, 600), 'alt' => 'Page restaurant', 'caption' => 'Table 12', 'link' => ''],
            ],
        ], 2);

        // ---- Pricing: three photo cards on a slate band. ----
        $pricing = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(96, 96, self::SLATE, 1180));
        $col = $this->column($pricing, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Des tarifs simples, sans surprise', 'tag' => 'h2'], 0);
        $this->block($col, 'text', ['content' => 'Commencez gratuitement, passez à la vitesse supérieure quand votre trafic décolle. Pas de frais cachés, résiliable à tout moment.'], 1);
        $this->block($col, 'card', [
            'layout' => 'grid', 'columns' => 3,
            'items' => [
                ['src' => $this->photo('nova-plan-starter', 800, 520), 'title' => 'Starter — 0 €', 'content' => "Tout pour se lancer.\nJusqu'à 3 pages et 1 utilisateur.\nModèles de base inclus.", 'buttonUrl' => '#', 'buttonLabel' => 'Commencer'],
                ['src' => $this->photo('nova-plan-pro', 800, 520), 'title' => 'Pro — 29 €/mois', 'content' => "Pour les sites qui grandissent.\nPages illimitées, 5 utilisateurs.\nBlocs personnalisés et historique.", 'buttonUrl' => '#', 'buttonLabel' => 'Choisir Pro'],
                ['src' => $this->photo('nova-plan-team', 800, 520), 'title' => 'Team — 79 €/mois', 'content' => "Collaboration à grande échelle.\nRôles, validation et support prioritaire.\nSSO et journal d'audit.", 'buttonUrl' => '#', 'buttonLabel' => 'Choisir Team'],
            ],
        ], 2);

        // ---- CTA band. ----
        $cta = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(96, 96, self::TINT, 760));
        $col = $this->column($cta, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Prêt à publier votre première page ?', 'tag' => 'h2'], 0);
        $this->block($col, 'text', ['content' => 'Créez un compte en trente secondes. Aucune carte bancaire requise, aucun engagement.'], 1);
        $this->block($col, 'button', ['text' => 'Créer mon compte', 'href' => '#', 'variant' => 'primary', 'size' => 'lg', 'align' => 'start', 'fullWidth' => false, 'newTab' => false], 2);

        // ---- Closing: demo video + note (alert / divider / embed). ----
        $foot = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(96, 96, '', 900));
        $col = $this->column($foot, 'col-12', 0);
        $b = 0;
        $this->block($col, 'title', ['text' => 'Une démo en 90 secondes', 'tag' => 'h2'], $b++);
        // A public, always-embeddable clip, so the demo seed stays self-contained
        // and carries no third-party branding.
        $this->block($col, 'embed', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Notre vidéo de présentation'], $b++);
        $this->block($col, 'divider', ['style' => 'dashed', 'color' => self::INDIGO], $b++);
        $this->block($col, 'alert', ['type' => 'success', 'title' => 'Bon à savoir', 'message' => 'Chacun des blocs de cette page est éditable dans le builder : ouvrez /admin/pages pour les manipuler.'], $b++);

        return $page;
    }

    private function buildDocs(): Page
    {
        $page = $this->page('Demo — Docs & FAQ', 'demo-docs');
        $area = $page->getContentArea();
        $pos = 0;

        // ---- Head: breadcrumb + title + intro. ----
        $head = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(72, 16, '', 820));
        $col = $this->column($head, 'col-12', 0);
        $this->block($col, 'breadcrumb', ['items' => [
            ['label' => 'Accueil', 'url' => '/'],
            ['label' => 'Documentation', 'url' => '/page/demo-docs'],
            ['label' => 'Prise en main', 'url' => ''],
        ]], 0);
        $this->block($col, 'title', ['text' => 'Documentation', 'tag' => 'h1'], 1);
        $this->block($col, 'rich_text', ['content' => '<p>Bienvenue dans la documentation de <strong>Nova</strong>. Cette page réunit les blocs riches en texte — parfaits pour rédiger un guide, une FAQ ou un article de fond. Tout ce que vous lisez ici est composé de blocs&nbsp;: <a href="#">titres</a>, listes, accordéons, tableaux et onglets.</p>'], 2);

        // ---- Two lists side by side, boxed. ----
        $lists = $this->section($area, Section::LAYOUT_TWO_COLS, $pos++, 'boxed', $this->look(56, 56, '', 980, 48));
        $lc = $this->column($lists, 'col-6', 0);
        $this->block($lc, 'title', ['text' => 'Inclus dans le kit', 'tag' => 'h3'], 0);
        $this->block($lc, 'list', ['style' => 'check', 'items' => [
            ['text' => '17 blocs autonomes, sans dépendance CSS'],
            ['text' => 'Activation / désactivation par bloc'],
            ['text' => 'Sélecteur de couleurs via la palette partagée'],
            ['text' => 'Redimensionnement et coins arrondis des images'],
            ['text' => 'Feuille de style servie sur une route publique'],
        ]], 1);
        $rc = $this->column($lists, 'col-6', 1);
        $this->block($rc, 'title', ['text' => 'Prise en main', 'tag' => 'h3'], 0);
        $this->block($rc, 'list', ['style' => 'numbered', 'items' => [
            ['text' => 'Installez les paquets via Composer'],
            ['text' => 'Liez la feuille de style du kit'],
            ['text' => 'Ajoutez un ContentArea à votre entité'],
            ['text' => 'Déclarez les contrôleurs Stimulus'],
            ['text' => 'Recompilez vos assets et publiez'],
        ]], 1);

        // ---- Editor preview image. ----
        $preview = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(80, 80, self::SLATE, 1040));
        $col = $this->column($preview, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Aperçu de l\'éditeur', 'tag' => 'h2'], 0);
        $this->block($col, 'image', $this->imageData($this->photo('nova-docs-editor', 1500, 720), 'Interface de l\'éditeur Nova', 'full', 'La sidebar de gauche liste les blocs ; le panneau de droite règle le style.', 16), 1);

        // ---- FAQ accordion. ----
        $faq = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(80, 80, '', 820));
        $col = $this->column($faq, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Questions fréquentes', 'tag' => 'h2'], 0);
        $this->block($col, 'accordion', ['exclusive' => true, 'items' => [
            ['title' => 'Le kit a-t-il besoin de Tailwind ou Bootstrap ?', 'content' => "Non. Le kit embarque sa propre feuille de style et des classes neutres préfixées cb-kit. Vous pouvez l'utiliser dans n'importe quel projet Symfony, avec ou sans framework CSS."],
            ['title' => 'Puis-je désactiver un bloc ?', 'content' => "Oui — content_blocks_kit.blocks.<type>.enabled: false suffit à retirer le bloc du sélecteur. Son service n'est même plus enregistré."],
            ['title' => 'Comment les couleurs sont-elles thématisées ?', 'content' => "Via la configuration content_blocks.palette. La même palette est réutilisée par tous les blocs et par l'éditeur de texte riche."],
            ['title' => 'Puis-je créer mes propres blocs ?', 'content' => "Absolument. Implémentez BlockTypeInterface, annotez la classe avec #[AsContentBlock], et le bloc est détecté automatiquement."],
        ]], 1);

        // ---- Comparison table. ----
        $table = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(80, 80, self::SLATE, 900));
        $col = $this->column($table, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Comparatif des offres', 'tag' => 'h2'], 0);
        $this->block($col, 'table', [
            'striped' => true,
            'columns' => [
                ['label' => 'Fonctionnalité', 'align' => 'left'],
                ['label' => 'Starter', 'align' => 'center'],
                ['label' => 'Pro', 'align' => 'center'],
                ['label' => 'Team', 'align' => 'center'],
            ],
            'rows' => [
                ['cells' => [['content' => 'Pages'], ['content' => '3'], ['content' => 'Illimitées'], ['content' => 'Illimitées']]],
                ['cells' => [['content' => 'Utilisateurs'], ['content' => '1'], ['content' => '5'], ['content' => 'Illimités']]],
                ['cells' => [['content' => 'Blocs personnalisés'], ['content' => '—'], ['content' => 'Oui'], ['content' => 'Oui']]],
                ['cells' => [['content' => 'Rôles & validation'], ['content' => '—'], ['content' => '—'], ['content' => 'Oui']]],
                ['cells' => [['content' => 'Support'], ['content' => 'E-mail'], ['content' => 'Prioritaire'], ['content' => 'Dédié']]],
            ],
        ], 1);

        // ---- Tabs + raw HTML callout. ----
        $tabs = $this->section($area, Section::LAYOUT_FULL, $pos++, null, $this->look(80, 96, '', 820));
        $col = $this->column($tabs, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Aller plus loin', 'tag' => 'h2'], 0);
        $this->block($col, 'tabs', ['tabs' => [
            ['title' => 'Présentation', 'content' => 'Le bloc onglets regroupe des contenus liés derrière des onglets 100 % CSS — idéal pour une documentation dense sans surcharger la page.'],
            ['title' => 'Détails', 'content' => 'Aucun JavaScript requis : de simples boutons radio et des sélecteurs de voisinage suffisent. Léger, accessible et imprimable.'],
            ['title' => 'Astuce', 'content' => 'Combinez les onglets avec le bloc HTML brut pour intégrer un extrait de code ou un composant maison.'],
        ]], 1);
        $this->block($col, 'html_raw', ['html' => '<div style="padding:1.25rem 1.5rem;border:1px solid #c7d2fe;background:#eef2ff;border-radius:12px;color:#3730a3;font-family:system-ui,sans-serif"><strong>Bloc HTML brut</strong> — le contrôle total quand vous en avez besoin : intégrez un widget, un formulaire ou un extrait de code.</div>'], 2);

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

    /**
     * Centered-section settings with vertical rhythm and an optional tinted
     * full-bleed background. Horizontal padding gives a gutter on narrow
     * viewports; `maxWidth` caps and centers the content on wide ones.
     *
     * @return array<string, mixed>
     */
    private function look(
        int $padTop,
        int $padBottom,
        string $bg = '',
        int $maxWidth = 1180,
        ?int $gap = null,
        ?string $verticalAlign = null,
        ?string $columnWidths = null,
    ): array {
        $styling = [
            'padding' => [
                'd' => ['top' => $padTop, 'right' => 24, 'bottom' => $padBottom, 'left' => 24],
                'm' => ['top' => (int) round($padTop * 0.6), 'right' => 20, 'bottom' => (int) round($padBottom * 0.6), 'left' => 20],
            ],
        ];
        if ($bg !== '') {
            $styling['backgroundColor'] = $bg;
        }
        if ($gap !== null) {
            $styling['gap'] = ['d' => $gap];
        }
        if ($verticalAlign !== null) {
            $styling['verticalAlign'] = $verticalAlign;
        }

        $extra = ['widthMode' => 'centered', 'maxWidth' => $maxWidth, 'styling' => $styling];
        if ($columnWidths !== null) {
            $extra['columnWidths'] = $columnWidths;
        }

        return $extra;
    }

    /**
     * Image block data with sane defaults; only src/alt/size/caption/radius vary.
     *
     * @return array<string, mixed>
     */
    private function imageData(string $src, string $alt, string $size, string $caption = '', int $radius = 16): array
    {
        return [
            'src' => $src,
            'alt' => $alt,
            'size' => $size,
            'customWidth' => 600,
            'customHeightAuto' => true,
            'customHeight' => 400,
            'fit' => 'cover',
            'align' => 'center',
            'link' => '',
            'caption' => $caption,
            'borderRadius' => ['top' => $radius, 'right' => $radius, 'bottom' => $radius, 'left' => $radius, 'linked' => true],
        ];
    }

    /**
     * A real, deterministic photograph via Lorem Picsum. Loaded by the
     * browser at render time — the demo ships no binary assets. The seed
     * keeps the same photo across reloads (and across re-seeding).
     */
    private function photo(string $seed, int $w, int $h): string
    {
        return sprintf('https://picsum.photos/seed/%s/%d/%d', rawurlencode($seed), $w, $h);
    }
}
