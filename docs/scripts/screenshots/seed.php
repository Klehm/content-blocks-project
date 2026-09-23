<?php
// Seeds the pages the documentation screenshots are taken from, published,
// in the main sandbox. Run from apps/content-blocks-sandbox; prints JSON.
use App\Entity\Page;
use App\Kernel;
use ContentBlocks\Entity\{Block, Column, ContentArea, Section};
use Symfony\Component\Dotenv\Dotenv;

require 'vendor/autoload.php';
(new Dotenv())->bootEnv('.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

$photo = fn (string $seed, int $w, int $h) => "https://picsum.photos/seed/$seed/$w/$h";
$look = function (int $top, int $bottom, string $bg = '', int $max = 1180, ?int $gap = null, ?string $valign = null) {
    $styling = ['padding' => [
        'desktop' => ['top' => $top, 'right' => 24, 'bottom' => $bottom, 'left' => 24],
        'mobile' => ['top' => (int) ($top * .6), 'right' => 20, 'bottom' => (int) ($bottom * .6), 'left' => 20],
    ]];
    if ($bg) { $styling['backgroundColor'] = $bg; }
    if ($gap) { $styling['gap'] = ['desktop' => $gap]; }
    if ($valign) { $styling['verticalAlign'] = $valign; }
    return ['widthMode' => 'centered', 'maxWidth' => $max, 'stylingCustom' => true, 'styling' => $styling];
};

$newPage = function (string $title) use ($em): array {
    $area = new ContentArea();
    $page = (new Page())->setTitle($title)->setSlug(strtolower($title) . '-' . uniqid());
    $page->setContentArea($area);
    $em->persist($page);
    return [$page, $area];
};
$section = function (ContentArea $area, string $layout, array $settings) use ($em) {
    $s = (new Section())->setLayout($layout)->setPreviewPosition(count($area->getSections()));
    $s->setContentArea($area);
    $s->setDraftSettings($settings);
    $area->addSection($s);
    $em->persist($s);
    return $s;
};
$column = function (Section $s, string $preset, int $i) use ($em) {
    $c = (new Column())->setPreset($preset)->setPreviewPosition($i);
    $c->setSection($s);
    $s->addColumn($c);
    $em->persist($c);
    return $c;
};
// $gap: the bottom margin an editor would give the block in its Style tab.
$block = function (Column $c, string $type, array $data, int $i, int $gap = 0) use ($em) {
    if ($gap > 0) {
        $data['styling']['margin'] = [
            'desktop' => ['top' => 0, 'right' => 0, 'bottom' => $gap, 'left' => 0],
            'mobile' => ['bottom' => (int) round($gap * .75)],
        ];
    }
    $b = (new Block())->setType($type)->setDraftData($data)->setPreviewPosition($i);
    $b->setColumn($c);
    $c->addBlock($b);
    $em->persist($b);
    return $b;
};
$publish = function (ContentArea $area) use ($em) {
    $em->flush();
    foreach ($area->getSections() as $s) {
        $s->publish();
        foreach ($s->getColumns() as $col) {
            $col->publish();
            foreach ($col->getBlocks() as $b) { $b->publish(); }
        }
    }
    $em->flush();
};
$radius = fn (int $r) => ['top' => $r, 'right' => $r, 'bottom' => $r, 'left' => $r, 'linked' => true];

// The showcase: a landing page, for the builder, workbench and public shots.
[$showcase, $area] = $newPage('Showcase');
$indigo = '#4f46e5';
$hero = $section($area, 'two_cols', $look(96, 96, '#eef2ff', 1180, 56, 'center'));
$l = $column($hero, 'col-7', 0);
$block($l, 'title', ['text' => 'Pages your team can build, on the site you already run', 'tag' => 'h1', 'size' => 'h1'], 0, 24);
$block($l, 'text', ['content' => 'Sections, columns and blocks, edited over a live preview of your own page. Drafts stay drafts until you publish.'], 1, 32);
$block($l, 'button_group', ['items' => [
    ['text' => 'Get started', 'url' => '/start', 'variant' => 'primary', 'newTab' => false],
    ['text' => 'Read the docs', 'url' => '/docs', 'variant' => 'secondary', 'newTab' => false],
], 'size' => 'lg', 'align' => 'start', 'stackOnMobile' => true], 2);
$r = $column($hero, 'col-5', 1);
$block($r, 'image', ['src' => $photo('cb-hero-desk', 1000, 760), 'alt' => 'A team at work', 'size' => 'full', 'customWidth' => 600, 'customHeightAuto' => true, 'customHeight' => 400, 'ratio' => 'auto', 'fit' => 'cover', 'align' => 'center', 'url' => '', 'caption' => '', 'borderRadius' => $radius(18)], 0);

$features = $section($area, 'three_cols', $look(88, 88, '', 1180, 48));
foreach ([['zap', 'Fast', 'The preview is your real page, refreshed in place as you edit.'], ['shield', 'Safe', 'Editors change drafts; the published page moves on Publish only.'], ['settings', 'Yours', 'Your entities, your routes, your auth: the builder fills one gap.']] as $i => [$icon, $t, $txt]) {
    $c = $column($features, 'col-4', $i);
    $block($c, 'icon', ['name' => $icon, 'color' => $indigo, 'size' => 40, 'align' => 'start'], 0, 16);
    $block($c, 'title', ['text' => $t, 'tag' => 'h3', 'size' => 'h3'], 1, 8);
    $block($c, 'text', ['content' => $txt], 2);
}

$plans = $section($area, 'full', $look(88, 88, '#f8fafc', 1180));
$c = $column($plans, 'col-12', 0);
$block($c, 'title', ['text' => 'Made with blocks', 'tag' => 'h2', 'size' => 'h2'], 0, 32);
$block($c, 'card', ['layout' => 'grid', 'columns' => 3, 'items' => [
    ['src' => $photo('cb-card-studio', 800, 520), 'title' => 'Studio', 'content' => 'A portfolio in an afternoon.', 'url' => '#', 'buttonText' => 'Open'],
    ['src' => $photo('cb-card-cafe', 800, 520), 'title' => 'Café', 'content' => 'Menus that the team updates.', 'url' => '#', 'buttonText' => 'Open'],
    ['src' => $photo('cb-card-travel', 800, 520), 'title' => 'Travel', 'content' => 'One page per destination.', 'url' => '#', 'buttonText' => 'Open'],
]], 1);

$faq = $section($area, 'two_cols', $look(88, 88, '', 1180, 56));
$c = $column($faq, 'col-5', 0);
$block($c, 'title', ['text' => 'Questions, answered', 'tag' => 'h2', 'size' => 'h2'], 0, 16);
$block($c, 'text', ['content' => 'A native <details> accordion from the kit: no script, no CSS framework.'], 1);
$c = $column($faq, 'col-7', 1);
$block($c, 'accordion', ['exclusive' => true, 'items' => [
    ['title' => 'Does it own my URLs?', 'content' => 'No. Your entity, your route, your template: the builder fills a ContentArea.'],
    ['title' => 'Can editors break the live page?', 'content' => 'Every change is a draft until Publish.'],
    ['title' => 'Can I write my own blocks?', 'content' => 'One PHP class and a Symfony form.'],
]], 0);
$publish($area);

// One section per kit block, in the kit's own order, for its reference page.
[$kitPage, $kitArea] = $newPage('Kit');
$kit = [
    'title' => ['text' => 'A title, sized apart from its tag', 'tag' => 'h2', 'size' => 'h1', 'color' => $indigo],
    'text' => ['content' => "Plain text, in a colour from the site's palette, for a lead or a short paragraph.", 'color' => ''],
    'rich_text' => ['content' => '<h3>Rich text</h3><p>Written in <strong>TinyMCE</strong> or <em>CKEditor</em>, sanitized when it is rendered, with <a href="#">links</a> and lists:</p><ul><li>headings</li><li>emphasis</li><li>quotes</li></ul>'],
    'image' => ['src' => $photo('cb-kit-image', 1200, 700), 'alt' => 'Coastline', 'size' => 'lg', 'customWidth' => 600, 'customHeightAuto' => true, 'customHeight' => 400, 'ratio' => '16-9', 'fit' => 'cover', 'align' => 'center', 'url' => '', 'caption' => 'An image with a ratio, rounded corners and a caption', 'borderRadius' => $radius(14)],
    // Fixed picsum ids: landscapes, whatever the seed service returns today.
    'gallery' => ['layout' => 'grid', 'columns' => 3, 'fit' => 'cover', 'borderRadius' => $radius(10), 'items' => array_map(
        fn ($id) => ['src' => "https://picsum.photos/id/$id/800/600", 'alt' => '', 'caption' => '', 'url' => ''],
        [10, 15, 28, 29, 43, 57],
    )],
    'button' => ['text' => 'Start your project', 'url' => '#', 'variant' => 'primary', 'size' => 'lg', 'align' => 'center', 'fullWidth' => false, 'newTab' => false],
    'button_group' => ['items' => [
        ['text' => 'Get started', 'url' => '#', 'variant' => 'primary', 'newTab' => false],
        ['text' => 'See pricing', 'url' => '#', 'variant' => 'secondary', 'newTab' => false],
        ['text' => 'Contact us', 'url' => '#', 'variant' => 'outline', 'newTab' => false],
    ], 'size' => 'md', 'align' => 'center', 'stackOnMobile' => true],
    'card' => ['layout' => 'grid', 'columns' => 3, 'items' => [
        ['src' => $photo('cb-kit-c1', 800, 520), 'title' => 'Studio', 'content' => 'A portfolio in an afternoon.', 'url' => '#', 'buttonText' => 'Open'],
        ['src' => $photo('cb-kit-c2', 800, 520), 'title' => 'Café', 'content' => 'Menus the team updates.', 'url' => '#', 'buttonText' => 'Open'],
        ['src' => $photo('cb-kit-c3', 800, 520), 'title' => 'Travel', 'content' => 'One page per destination.', 'url' => '#', 'buttonText' => 'Open'],
    ]],
    'list' => ['style' => 'check', 'items' => [
        ['text' => 'Drafts until you publish'], ['text' => 'Undo and redo'], ['text' => 'Copy and paste between pages'], ['text' => 'Translated content'],
    ]],
    'icon' => ['name' => 'star', 'color' => $indigo, 'size' => 64, 'align' => 'center'],
    'alert' => ['type' => 'success', 'title' => 'Published', 'content' => 'Your changes are live. The previous version stays one click away until the next publish.'],
    'divider' => ['style' => 'dashed', 'color' => $indigo],
    'accordion' => ['exclusive' => false, 'items' => [
        ['title' => 'Does it own my URLs?', 'content' => 'No. Your entity, your route, your template.'],
        ['title' => 'Can editors break the live page?', 'content' => 'Every change is a draft until Publish.'],
        ['title' => 'Can I write my own blocks?', 'content' => 'One PHP class and a Symfony form.'],
    ]],
    'table' => ['striped' => true, 'columns' => [
        ['label' => 'Plan', 'align' => 'start'], ['label' => 'Pages', 'align' => 'center'], ['label' => 'Price', 'align' => 'end'],
    ], 'rows' => [
        ['cells' => [['content' => 'Starter'], ['content' => '10'], ['content' => '€9']]],
        ['cells' => [['content' => 'Team'], ['content' => '100'], ['content' => '€29']]],
        ['cells' => [['content' => 'Agency'], ['content' => 'Unlimited'], ['content' => '€99']]],
    ]],
    'embed' => ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'title' => 'Big Buck Bunny'],
    'video' => ['src' => '/uploads/content-blocks/demo.mp4', 'poster' => $photo('cb-kit-video', 1280, 720), 'autoplay' => false, 'muted' => false, 'controls' => true, 'loop' => false, 'size' => 'md', 'align' => 'center', 'caption' => 'A self-hosted file in a native player', 'captions' => '', 'captionsLang' => ''],
    'breadcrumb' => ['items' => [['label' => 'Home', 'url' => '/'], ['label' => 'Guides', 'url' => '/'], ['label' => 'Getting started', 'url' => '']]],
    'tabs' => ['items' => [
        ['title' => 'Overview', 'content' => "Tabs made of radios and CSS: no script.\nEach panel is a labelled region."],
        ['title' => 'Details', 'content' => 'Second panel.'],
        ['title' => 'Reviews', 'content' => 'Third panel.'],
    ]],
    'html_raw' => ['html' => '<p style="text-align:center;font:600 1.1rem system-ui">Raw HTML, <code>|raw</code>: for trusted editors only.</p>'],
];
foreach ($kit as $type => $data) {
    $s = $section($kitArea, 'full', $look(40, 40, '', 880));
    $block($column($s, 'col-12', 0), $type, $data, 0);
}
$publish($kitArea);

echo json_encode([
    'showcase' => ['page' => $showcase->getId(), 'area' => $area->getId()],
    'kit' => ['page' => $kitPage->getId(), 'types' => array_keys($kit)],
]), "\n";
