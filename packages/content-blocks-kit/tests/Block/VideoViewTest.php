<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Kit\Block\VideoBlock;
use ContentBlocks\Kit\RichText\RichTextSanitizerFactory;
use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use ContentBlocks\Kit\Twig\SafeContentExtension;
use ContentBlocks\Twig\ColorToneExtension;
use ContentBlocks\Twig\ImageExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class VideoViewTest extends TestCase
{
    public function testDefaultsRenderAPlayableVideoWithControls(): void
    {
        $html = $this->render(['src' => '/uploads/clip.mp4']);

        $this->assertMatchesRegularExpression('/<video class="cb-kit-video__player" src="\/uploads\/clip.mp4"/', $html);
        $this->assertMatchesRegularExpression('/<video[^>]* controls[ >]/', $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]* (autoplay|muted|loop)[ >]/', $html);
        $this->assertStringContainsString('preload="metadata"', $html);
        $this->assertStringContainsString('cb-kit-video--center cb-kit-video--size-full', $html);
        // Full width: no inline cap.
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*style=/', $html);
    }

    /** Browsers block unmuted autoplay; inline keeps iOS out of fullscreen. */
    public function testAutoplayIsAlwaysMutedAndInline(): void
    {
        $html = $this->render(['src' => '/v.mp4', 'autoplay' => true, 'muted' => false, 'controls' => false]);

        $this->assertMatchesRegularExpression('/<video[^>]* autoplay playsinline muted[ >]/', $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]* controls[ >]/', $html);
    }

    /** Without autoplay, hiding the controls would make it unplayable. */
    public function testControlsCannotBeHiddenWithoutAutoplay(): void
    {
        $html = $this->render(['src' => '/v.mp4', 'autoplay' => false, 'controls' => false]);

        $this->assertMatchesRegularExpression('/<video[^>]* controls[ >]/', $html);
    }

    public function testLoopMutedSizeAndAlign(): void
    {
        $html = $this->render([
            'src' => '/v.mp4',
            'muted' => true,
            'loop' => true,
            'size' => 'md',
            'align' => 'end',
        ]);

        $this->assertMatchesRegularExpression('/<video[^>]* muted loop style="width:800px"/', $html);
        $this->assertStringContainsString('cb-kit-video--end cb-kit-video--size-md', $html);
    }

    /** The poster goes through the image seam, like every kit picture. */
    public function testThePosterGoesThroughTheImageResolver(): void
    {
        $resolver = new class () implements ImageUrlResolverInterface {
            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                return new ResolvedImage('/cdn' . $src . '?w=' . ($width ?? 'auto'));
            }
        };

        $html = $this->render(['src' => '/v.mp4', 'poster' => '/p.jpg', 'size' => 'lg'], $resolver);

        $this->assertStringContainsString('poster="/cdn/p.jpg?w=1200"', $html);
    }

    public function testCaptionAndMalformedAlignment(): void
    {
        $html = $this->render(['src' => '/v.mp4', 'caption' => 'Behind the scenes', 'align' => 'bogus start']);

        $this->assertStringContainsString('<figcaption class="cb-kit-video__caption">Behind the scenes</figcaption>', $html);
        $this->assertStringContainsString('cb-kit-video--center', $html);
        $this->assertStringNotContainsString('bogus', $html);
    }

    public function testCaptionsBecomeADefaultTrack(): void
    {
        $html = $this->render(['src' => '/v.mp4', 'captions' => '/subs/fr.vtt', 'captionsLang' => 'fr']);

        $this->assertMatchesRegularExpression(
            '#<video[^>]*>\s*<track kind="captions" src="/subs/fr.vtt" label="[^"]+" srclang="fr" default>\s*</video>#',
            $html,
        );
    }

    public function testNoCaptionsNoTrack(): void
    {
        $this->assertStringNotContainsString('<track', $this->render(['src' => '/v.mp4']));
    }

    public function testAnUnsafeCaptionsUrlIsDropped(): void
    {
        $html = $this->render(['src' => '/v.mp4', 'captions' => 'javascript:alert(1)']);

        $this->assertStringNotContainsString('<track', $html);
    }

    public function testAMalformedLanguageIsLeftOut(): void
    {
        $html = $this->render(['src' => '/v.mp4', 'captions' => '/a.vtt', 'captionsLang' => 'fr" onload="x']);

        $this->assertStringContainsString('<track kind="captions" src="/a.vtt"', $html);
        $this->assertStringNotContainsString('srclang', $html);
    }

    public function testNoFileRendersThePlaceholder(): void
    {
        $html = $this->render(['src' => '  ']);

        $this->assertStringNotContainsString('<video', $html);
        $this->assertStringContainsString('cb_kit.block.video.no_video', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(array $data, ?ImageUrlResolverInterface $resolver = null): string
    {
        $data += (new VideoBlock())->getDefaultData();

        return $this->makeTwig($resolver)->render('@ContentBlocksKit/block/video/view.html.twig', ['data' => $data]);
    }

    private function makeTwig(?ImageUrlResolverInterface $resolver): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new ChoiceTokenExtension());
        $env->addExtension(new SafeContentExtension(RichTextSanitizerFactory::create()));
        $env->addExtension(new ColorToneExtension());
        $env->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $env->addExtension(new ImageExtension($resolver ?? new PassthroughImageUrlResolver()));

        return $env;
    }
}
