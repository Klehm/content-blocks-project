<?php

declare(strict_types=1);

namespace App\Command;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentPageTranslation;
use ContentBlocks\Sylius\Entity\ContentSlot;
use ContentBlocks\Sylius\Slot\SlotType;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builder content on top of Sylius's `default` fixtures: the Fashion
 * homepage, an "About us" page in two languages, a product's own content,
 * and a Lookbook folder in the media library. Idempotent.
 */
#[AsCommand(name: 'app:demo:load', description: 'Load the builder demo content of the Sylius shop')]
final class LoadContentDemoCommand extends Command
{
    private const CHANNEL = 'FASHION_WEB';
    private const PRODUCT = 'Bold_Beach_Cap';
    private const TINT = '#f3f4f6';
    private const LOOKBOOK = 'Lookbook';
    private const PHOTOS = [
        ['dresses/dress_06.webp', 'Beach days', 'Light dresses for long afternoons by the water.'],
        ['t-shirts/woman/t-shirt_01_1.webp', 'Easy tees', 'Soft cotton, cut loose, in colours that fade well.'],
        ['jeans/woman/jeans_01_1.webp', 'Denim, always', 'Five fits, one rule: it has to feel broken in.'],
        ['caps/cap_01.webp', 'Made for the sun', 'Caps that keep their shape after a summer outside.'],
        ['t-shirts/man/t-shirt_01_1.webp', 'Off duty', 'The shirt you reach for first, in organic cotton.'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentAreaPublisherInterface $publisher,
        private readonly FileStorageInterface $storage,
        #[Autowire('%kernel.project_dir%/vendor/sylius/sylius/src/Sylius/Bundle/CoreBundle/Resources/fixtures')]
        private readonly string $photos,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $channel = $this->em->getRepository(ChannelInterface::class)->findOneBy(['code' => self::CHANNEL]);
        if (!$channel instanceof ChannelInterface) {
            $io->error('Load the Sylius fixtures first: sylius:fixtures:load default');

            return Command::FAILURE;
        }

        $this->reset();
        $this->speakFrench($channel);

        $lookbook = $this->lookbook();
        $areas = [
            $this->homepage($lookbook),
            $this->aboutPage($channel),
            $this->productContent(),
        ];
        $this->em->flush();
        foreach ($areas as $area) {
            $this->publisher->publish($area);
        }
        $this->em->flush();

        $io->success('Builder demo content loaded.');
        $io->listing([
            'Homepage: /en_US/ (edit at /admin/content-blocks/homepage)',
            'About us: /en_US/pages/about-us, /fr_FR/pages/a-propos',
            'Product content: /en_US/products/bold-beach-cap',
            'Media library: the Lookbook folder, from any image field of the builder',
        ]);

        return Command::SUCCESS;
    }

    private function reset(): void
    {
        foreach ($this->em->getRepository(ContentPage::class)->findBy(['code' => 'about_us']) as $page) {
            $this->em->remove($page);
        }
        foreach ([[SlotType::HOMEPAGE, self::CHANNEL], [SlotType::PRODUCT, self::PRODUCT]] as [$type, $subject]) {
            $slot = $this->em->getRepository(ContentSlot::class)->findOneBy(['type' => $type, 'subject' => $subject]);
            if ($slot !== null) {
                $this->em->remove($slot);
            }
        }
        foreach ($this->em->getRepository(MediaFolder::class)->findBy(['name' => self::LOOKBOOK, 'parent' => null]) as $folder) {
            foreach ($this->em->getRepository(Media::class)->findBy(['folder' => $folder]) as $media) {
                $this->storage->remove($media->getPath());
                $this->em->remove($media);
            }
            $this->em->remove($folder);
        }
        $this->em->flush();
    }

    /**
     * Stored through the storage, as an upload is: the media package records
     * each file. Then filed, titled and described.
     *
     * @return list<array{path: string, title: string, text: string}>
     */
    private function lookbook(): array
    {
        $folder = new MediaFolder(self::LOOKBOOK);
        $this->em->persist($folder);
        $photos = [];
        foreach (self::PHOTOS as [$file, $title, $text]) {
            $path = $this->storage->uploadFromString((string) file_get_contents($this->photos . '/' . $file), 'webp');
            $media = $this->em->getRepository(Media::class)->findOneBy(['path' => $path]);
            if ($media instanceof Media) {
                $media->setTitle($title);
                $media->setAlt($title);
                $media->moveTo($folder);
            }
            $photos[] = ['path' => $path, 'title' => $title, 'text' => $text];
        }
        $this->em->flush();

        return $photos;
    }

    /** The fixtures translate every product into French; the channel did not offer it. */
    private function speakFrench(ChannelInterface $channel): void
    {
        $french = $this->em->getRepository(LocaleInterface::class)->findOneBy(['code' => 'fr_FR']);
        if ($french instanceof LocaleInterface && !$channel->hasLocale($french)) {
            $channel->addLocale($french);
        }
    }

    /** @param list<array{path: string, title: string, text: string}> $lookbook */
    private function homepage(array $lookbook): ContentArea
    {
        $slot = new ContentSlot(SlotType::HOMEPAGE, self::CHANNEL);
        $this->em->persist($slot);
        $area = $slot->getContentArea();

        $hero = $this->section($area, Section::LAYOUT_TWO_COLS, 0, $this->look(72, 72, self::TINT, '50,50'));
        $left = $this->column($hero, 'col-6', 0);
        $this->block($left, 'title', ['text' => 'Summer starts on the beach', 'tag' => 'h1', 'size' => 'h1', 'color' => ''], 0);
        $this->block($left, 'text', ['content' => 'Light linen, faded denim and caps made for salt and sun. The new collection is in, and delivery is free from $50.', 'color' => ''], 1);
        $this->block($left, 'button_group', [
            'items' => [
                ['text' => 'Shop the T-shirts', 'url' => '/en_US/taxons/category/t-shirts', 'variant' => 'primary', 'newTab' => false],
                ['text' => 'Our story', 'url' => '/en_US/pages/about-us', 'variant' => 'outline', 'newTab' => false],
            ],
            'size' => 'lg',
            'align' => 'start',
            'stackOnMobile' => true,
        ], 2);
        $right = $this->column($hero, 'col-6', 1);
        $this->block($right, 'product', ['product' => self::PRODUCT, 'addToCart' => true], 0);

        $arrivals = $this->section($area, Section::LAYOUT_FULL, 1, $this->look(56, 24));
        $col = $this->column($arrivals, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'New arrivals', 'tag' => 'h2', 'size' => 'h2', 'color' => ''], 0);
        $this->block($col, 'product_list', [
            'source' => 'latest', 'products' => [], 'taxon' => '', 'limit' => 8, 'layout' => 'carousel', 'columns' => 4,
        ], 1);

        $categories = $this->section($area, Section::LAYOUT_FULL, 2, $this->look(24, 56));
        $col = $this->column($categories, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Shop by category', 'tag' => 'h2', 'size' => 'h2', 'color' => ''], 0);
        $this->block($col, 'taxon_list', ['source' => 'children', 'parent' => 'category', 'taxons' => [], 'columns' => 4], 1);

        // A section shown as a slider: one column per slide.
        $slider = $this->section($area, Section::LAYOUT_FULL, 3, $this->look(24, 56) + [
            'display' => 'slider',
            'sliderPerView' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1],
            'sliderControls' => 'both',
        ]);
        foreach ($lookbook as $i => $photo) {
            $col = $this->column($slider, 'col-4', $i);
            $this->block($col, 'card', ['layout' => 'list', 'columns' => 3, 'items' => [[
                'src' => $photo['path'], 'title' => $photo['title'], 'content' => $photo['text'],
                'url' => '/en_US/taxons/category', 'buttonText' => 'Discover',
            ]]], 0);
        }

        $jeans = $this->section($area, Section::LAYOUT_TWO_COLS, 4, $this->look(56, 72, self::TINT, '33,67'));
        $left = $this->column($jeans, 'col-4', 0);
        $this->block($left, 'title', ['text' => 'Denim for every day', 'tag' => 'h2', 'size' => 'h2', 'color' => ''], 0);
        $this->block($left, 'list', [
            'style' => 'check',
            'items' => [['text' => 'Organic cotton'], ['text' => 'Free alterations in store'], ['text' => '30-day returns']],
        ], 1);
        $right = $this->column($jeans, 'col-8', 1);
        $this->block($right, 'product_list', [
            'source' => 'taxon', 'products' => [], 'taxon' => 'jeans', 'limit' => 3, 'layout' => 'grid', 'columns' => 3,
        ], 0);

        return $area;
    }

    private function aboutPage(ChannelInterface $channel): ContentArea
    {
        $page = new ContentPage();
        $page->setCode('about_us');
        $page->setEnabled(true);
        $page->addChannel($channel);
        foreach ([
            'en_US' => ['About us', 'about-us', 'Who makes the Fashion Web Store, and how.'],
            'fr_FR' => ['À propos', 'a-propos', 'Qui fait Fashion Web Store, et comment.'],
        ] as $locale => [$title, $slug, $description]) {
            $translation = new ContentPageTranslation();
            $translation->setLocale($locale);
            $translation->setTitle($title);
            $translation->setSlug($slug);
            $translation->setMetaDescription($description);
            $page->addTranslation($translation);
        }
        $area = new ContentArea();
        $page->setContentArea($area);
        $this->em->persist($page);

        $intro = $this->section($area, Section::LAYOUT_TWO_COLS, 0, $this->look(72, 48, '', '50,50'));
        $left = $this->column($intro, 'col-6', 0);
        $this->block($left, 'title', ['text' => 'Made by the sea since 2012', 'tag' => 'h1', 'size' => 'h1', 'color' => ''], 0);
        $this->block($left, 'text', ['content' => 'We are a small team of designers and tailors working between Biarritz and Porto. Every piece is cut in small runs, from fabrics we can trace back to the mill.', 'color' => ''], 1);
        $this->block($left, 'alert', ['type' => 'success', 'title' => 'Free returns', 'content' => 'Changed your mind? Send it back within 30 days, on us.'], 2);
        $right = $this->column($intro, 'col-6', 1);
        $this->block($right, 'image', [
            'src' => 'https://picsum.photos/id/1011/1200/800', 'alt' => 'Our workshop by the sea', 'size' => 'full',
            'customWidth' => 600, 'customHeightAuto' => true, 'customHeight' => 400, 'fit' => 'cover', 'align' => 'center',
            'url' => '', 'caption' => '', 'ratio' => '3-2',
            'borderRadius' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12, 'linked' => true],
        ], 0);

        $faq = $this->section($area, Section::LAYOUT_FULL, 1, $this->look(48, 72, self::TINT));
        $col = $this->column($faq, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Questions we get', 'tag' => 'h2', 'size' => 'h2', 'color' => ''], 0);
        $this->block($col, 'accordion', [
            'exclusive' => true,
            'items' => [
                ['title' => 'Where do you ship?', 'content' => 'Everywhere in the EU and the US, in 2 to 5 working days.'],
                ['title' => 'How do I find my size?', 'content' => 'Each product page has a size guide below its description.'],
            ],
        ], 1);
        $this->block($col, 'button', [
            'text' => 'Contact us', 'url' => '/en_US/contact/', 'variant' => 'primary', 'size' => 'md',
            'align' => 'start', 'fullWidth' => false, 'newTab' => false,
        ], 2);

        return $area;
    }

    private function productContent(): ContentArea
    {
        $slot = new ContentSlot(SlotType::PRODUCT, self::PRODUCT);
        $this->em->persist($slot);
        $area = $slot->getContentArea();

        $guide = $this->section($area, Section::LAYOUT_FULL, 0, $this->look(48, 48));
        $col = $this->column($guide, 'col-12', 0);
        $this->block($col, 'title', ['text' => 'Size guide', 'tag' => 'h2', 'size' => 'h3', 'color' => ''], 0);
        $this->block($col, 'table', [
            'striped' => true,
            'columns' => [['label' => 'Size', 'align' => 'start'], ['label' => 'Head (cm)', 'align' => 'end']],
            'rows' => [
                ['cells' => [['content' => 'S'], ['content' => '54–55']]],
                ['cells' => [['content' => 'M'], ['content' => '56–57']]],
                ['cells' => [['content' => 'L'], ['content' => '58–59']]],
            ],
        ], 1);

        return $area;
    }

    /** @param array<string, mixed> $settings */
    private function section(ContentArea $area, string $layout, int $position, array $settings): Section
    {
        $section = new Section();
        $section->setContentArea($area);
        $section->setLayout($layout);
        $section->setPreviewPosition($position);
        $section->setDraftSettings($settings);
        $area->addSection($section);

        return $section;
    }

    private function column(Section $section, string $preset, int $position): Column
    {
        $column = new Column();
        $column->setSection($section);
        $column->setPreset($preset);
        $column->setPreviewPosition($position);
        $section->addColumn($column);

        return $column;
    }

    /** @param array<string, mixed> $data */
    private function block(Column $column, string $type, array $data, int $position): Block
    {
        $block = new Block();
        $block->setColumn($column);
        $block->setType($type);
        $block->setDraftData($data);
        $block->setPreviewPosition($position);
        $column->addBlock($block);

        return $block;
    }

    /**
     * Centered like the shop's `.container`, with vertical rhythm.
     *
     * @return array<string, mixed>
     */
    private function look(int $top, int $bottom, string $background = '', ?string $columnWidths = null): array
    {
        $styling = ['padding' => [
            'desktop' => ['top' => $top, 'right' => 24, 'bottom' => $bottom, 'left' => 24],
            'mobile' => ['top' => (int) round($top * 0.6), 'right' => 16, 'bottom' => (int) round($bottom * 0.6), 'left' => 16],
        ]];
        if ($background !== '') {
            $styling['backgroundColor'] = $background;
        }
        $settings = ['widthMode' => 'centered', 'maxWidth' => 1320, 'styling' => $styling];
        if ($columnWidths !== null) {
            $settings['columnWidths'] = $columnWidths;
        }

        return $settings;
    }
}
