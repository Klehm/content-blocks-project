<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use ContentBlocks\Kit\Icon\IconRegistry;
use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use ContentBlocks\Kit\Twig\IconExtension;
use ContentBlocks\Twig\ImageExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Every choice field in the kit, checked end to end.
 *
 * Making `choices` able to *add* values is only half a feature: the value has to
 * survive into the rendered page, and the views do not all treat their choice
 * fields alike. Auditing that by hand once is how it silently rots — so the
 * audit lives here, and a new choice field on a kit block has to declare which
 * side of the line it falls on.
 *
 * Three categories, and the count assertion below makes sure none is forgotten:
 *
 *  - **open** — the value reaches the markup, so a host's CSS can address it.
 *  - **closed** — `title.tag` alone: the value *becomes the element*, and
 *    widening what markup the kit emits is not something config gets to do.
 *  - **seam** — `icon.name`: the picker is extensible, but through
 *    `IconProviderInterface`, because a name without a glyph renders nothing.
 */
final class ChoiceFieldCoverageTest extends TestCase
{
    /** Minimum data for each block's view to render anything at all. */
    private const FIXTURES = [
        'title' => ['text' => 'X'],
        'image' => ['src' => '/uploads/x.png'],
        'gallery' => ['items' => [['src' => '/uploads/x.png']]],
        'button' => ['text' => 'X'],
        'card' => ['items' => [['title' => 'X']]],
        'list' => ['items' => [['text' => 'X']]],
        'icon' => ['name' => 'star'],
        'alert' => ['content' => 'X'],
        'divider' => [],
    ];

    /**
     * field => [synthetic value, extra data needed for it to show up].
     *
     * The extra data is never decoration: `image.fit` only lands in the markup
     * once a height pins the box, which is the only case `object-fit` means
     * anything.
     *
     * @return iterable<string, array{string, string, string, array<string, mixed>}>
     */
    public static function openFieldProvider(): iterable
    {
        yield 'title.size' => ['title', 'size', 'display', []];
        yield 'image.size' => ['image', 'size', 'xxl', []];
        yield 'image.align' => ['image', 'align', 'baseline', []];
        yield 'image.fit' => ['image', 'fit', 'scale-down', [
            'size' => 'custom', 'customWidth' => 100, 'customHeightAuto' => false, 'customHeight' => 50,
        ]];
        yield 'gallery.layout' => ['gallery', 'layout', 'masonry', []];
        yield 'gallery.columns' => ['gallery', 'columns', '7', []];
        yield 'gallery.fit' => ['gallery', 'fit', 'scale-down', []];
        yield 'button.variant' => ['button', 'variant', 'ghost', []];
        yield 'button.size' => ['button', 'size', 'jumbo', []];
        yield 'button.align' => ['button', 'align', 'stretch', []];
        yield 'card.layout' => ['card', 'layout', 'carousel', []];
        yield 'card.columns' => ['card', 'columns', '7', ['layout' => 'grid']];
        yield 'list.style' => ['list', 'style', 'arrow', []];
        yield 'icon.align' => ['icon', 'align', 'baseline', []];
        yield 'alert.type' => ['alert', 'type', 'tip', []];
        yield 'divider.style' => ['divider', 'style', 'double', []];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('openFieldProvider')]
    public function testAnAddedValueReachesTheRenderedMarkup(
        string $type,
        string $field,
        string $value,
        array $extra,
    ): void {
        // The picker half: config offers it.
        $block = $this->block($type, [$field => [$value => 'Added']]);
        $choices = new \ReflectionMethod($block, 'choices');
        $this->assertContains(
            $value,
            array_values($choices->invoke($block, $field)),
            sprintf('%s.%s should offer a configured value', $type, $field),
        );

        // The page half: the view does not swap it back for a coded default.
        $html = $this->render($type, [$field => $value] + $extra);
        $this->assertStringContainsString(
            $value,
            $html,
            sprintf('%s.%s should reach the markup, so host CSS can address it', $type, $field),
        );
    }

    public function testTitleTagStaysClosedWhateverConfigSays(): void
    {
        // Config widens what a host can style, never what markup the kit emits.
        $block = $this->block('title', ['tag' => ['marquee' => 'Marquee']]);
        $choices = new \ReflectionMethod($block, 'choices');

        $this->assertContains('marquee', array_values($choices->invoke($block, 'tag')), 'the picker follows config');

        $html = $this->render('title', ['tag' => 'marquee']);

        $this->assertStringNotContainsString('marquee', $html, 'but the element does not');
        $this->assertStringContainsString('<h2', $html);
    }

    public function testIconNameNeedsTheProviderSeamNotChoices(): void
    {
        // `choices` can offer the name, but only a provider can draw it — so a
        // config-only value falls back to a glyph instead of erasing the block.
        $html = $this->render('icon', ['name' => 'brand-logo']);

        $this->assertStringContainsString('<svg', $html, 'never an empty block');
        $this->assertStringNotContainsString('brand-logo', $html);
    }

    /**
     * Guards the audit itself: a new choice field on a kit block fails here
     * until someone decides, and states, which category it belongs to.
     */
    public function testEveryChoiceFieldInTheKitIsAccountedFor(): void
    {
        $declared = array_map(
            static fn (array $case): string => $case[0] . '.' . $case[1],
            iterator_to_array(self::openFieldProvider()),
        );
        $accounted = array_values($declared);
        $accounted[] = 'title.tag';   // closed by design
        $accounted[] = 'icon.name';   // extensible through IconProviderInterface

        $actual = [];
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            $block = new $class();
            $fields = (new \ReflectionMethod($block, 'choiceFields'))->invoke($block);
            foreach (array_keys($fields) as $field) {
                $actual[] = $type . '.' . $field;
            }
        }

        sort($actual);
        sort($accounted);
        $this->assertSame(
            $accounted,
            $actual,
            'A kit choice field is not covered by this audit — classify it as open, closed or seam.',
        );
    }

    /**
     * @param array<string, mixed> $choiceOverrides
     */
    private function block(string $type, array $choiceOverrides): object
    {
        $class = ContentBlocksKitBundle::BLOCKS[$type];

        return new $class([], $choiceOverrides, []);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $type, array $data): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new ChoiceTokenExtension());
        $env->addExtension(new IconExtension(new IconRegistry()));
        $env->addExtension(new ImageExtension(new PassthroughImageUrlResolver()));
        $env->addExtension(new TranslationExtension(new class implements TranslatorInterface {
            use TranslatorTrait;
        }));

        return $env->render(
            sprintf('@ContentBlocksKit/block/%s/view.html.twig', $type),
            ['data' => $data + self::FIXTURES[$type]],
        );
    }
}
