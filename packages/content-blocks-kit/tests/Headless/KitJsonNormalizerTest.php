<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Headless;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Block\ButtonGroupBlock;
use ContentBlocks\Kit\Block\CardBlock;
use ContentBlocks\Kit\Block\EmbedBlock;
use ContentBlocks\Kit\Block\GalleryBlock;
use ContentBlocks\Kit\Block\HtmlRawBlock;
use ContentBlocks\Kit\Block\ImageBlock;
use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\Block\VideoBlock;
use ContentBlocks\Kit\Headless\KitJsonNormalizer;
use ContentBlocks\Kit\RichText\RichTextSanitizerFactory;
use ContentBlocks\Rendering\BlockView;
use ContentBlocks\Rendering\RenderContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

final class KitJsonNormalizerTest extends TestCase
{
    public function testRichTextIsSanitized(): void
    {
        $data = $this->normalize('rich_text', ['content' => '<p>Hi</p><script>x()</script>'
            . '<a href="javascript:alert(1)">l</a>']);

        $this->assertSame('<p>Hi</p><a>l</a>', $data['content']);
    }

    public function testUnsafeLinksAreBlanked(): void
    {
        $this->assertSame('', $this->normalize('button', ['url' => 'javascript:alert(1)'])['url']);
        $this->assertSame('/contact', $this->normalize('button', ['url' => ' /contact '])['url']);

        $items = $this->normalize('button_group', ['items' => [
            ['text' => 'a', 'url' => 'data:text/html,x'],
            ['text' => 'b', 'url' => 'mailto:a@b.c'],
        ]])['items'];
        $this->assertSame(['', 'mailto:a@b.c'], array_column($items, 'url'));
    }

    // The image view gives the resolver the width of its size preset.
    public function testAnImageGoesThroughTheResolverWithItsDisplayBox(): void
    {
        $data = $this->normalize('image', ['src' => '/u/a.png', 'size' => 'lg', 'url' => 'vbscript:x']);

        $this->assertSame('/u/a.png?w=1200&h=', $data['src']);
        $this->assertSame('/u/a.png?w=1200&h= 1x', $data['srcset']);
        $this->assertNull($data['sizes']);
        $this->assertSame('', $data['url']);

        $custom = $this->normalize('image', [
            'src' => '/u/a.png',
            'size' => 'custom',
            'customWidth' => 300,
            'customHeightAuto' => false,
            'customHeight' => 200,
        ]);
        $this->assertSame('/u/a.png?w=300&h=200', $custom['src']);
    }

    public function testCollectionImagesAndPosterAreResolved(): void
    {
        $gallery = $this->normalize('gallery', ['items' => [['src' => '/g.png'], ['src' => '']]]);
        $this->assertSame('/g.png?w=&h=', $gallery['items'][0]['src']);
        $this->assertSame('', $gallery['items'][1]['src']);
        $this->assertNull($gallery['items'][1]['srcset']);

        $card = $this->normalize('card', ['items' => [['src' => '/c.png', 'url' => 'javascript:x']]]);
        $this->assertSame('/c.png?w=&h=', $card['items'][0]['src']);
        $this->assertSame('', $card['items'][0]['url']);

        $video = $this->normalize('video', ['src' => '/v.mp4', 'poster' => '/p.png', 'captions' => 'javascript:x']);
        $this->assertSame('/v.mp4', $video['src']);
        $this->assertSame('/p.png?w=&h=', $video['poster']);
        $this->assertSame('/p.png?w=&h= 1x', $video['posterSrcset']);
        $this->assertArrayHasKey('posterSizes', $video);
        $this->assertSame('', $video['captions']);
    }

    public function testEmbedGainsItsPlayerUrl(): void
    {
        $this->assertSame(
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            $this->normalize('embed', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])['embedUrl'],
        );
        $this->assertNull($this->normalize('embed', ['url' => 'https://example.com'])['embedUrl']);
    }

    // html_raw trusts its editors: it is opt-in for exactly that reason.
    public function testRawHtmlIsLeftAsStored(): void
    {
        $this->assertSame(['html' => '<script>x()</script>'], $this->normalize('html_raw', ['html' => '<script>x()</script>']));
    }

    public function testABlockTheKitDoesNotOwnIsLeftAlone(): void
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new class () extends AbstractBlockType {
            public function getType(): string
            {
                return 'rich_text';
            }
            public function getLabel(): string
            {
                return 'Host rich text';
            }
            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
            }
            public function getDefaultData(): array
            {
                return [];
            }
        });

        $data = ['content' => '<script>host()</script>'];
        $this->assertSame($data, $this->normalizer($registry)->normalize(new BlockView(1, 'rich_text'), $data, new RenderContext()));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(string $type, array $data): array
    {
        $registry = new BlockTypeRegistry();
        foreach ([
            new ButtonBlock(), new ButtonGroupBlock(), new CardBlock(), new EmbedBlock(),
            new GalleryBlock(), new HtmlRawBlock(), new ImageBlock(), new RichTextBlock(),
            new VideoBlock(),
        ] as $block) {
            $registry->register($block);
        }

        return $this->normalizer($registry)->normalize(new BlockView(1, $type), $data, new RenderContext());
    }

    private function normalizer(BlockTypeRegistry $registry): KitJsonNormalizer
    {
        $images = new class () implements ImageUrlResolverInterface {
            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                $url = $src . '?w=' . $width . '&h=' . $height;

                return new ResolvedImage($url, $url . ' 1x');
            }
        };

        return new KitJsonNormalizer($registry, $images, RichTextSanitizerFactory::create());
    }
}
