<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\VideoUploadType;
use ContentBlocks\Storage\MediaLibraryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

/** The *Browse library* button: shown only when a library takes the kind. */
final class MediaLibraryButtonTest extends TestCase
{
    public function testNoLibraryNoButton(): void
    {
        $view = $this->factory(null)->create(ImageUploadType::class)->createView();

        $this->assertFalse($view->vars['media_library']);
    }

    public function testALibraryTakingImagesShowsItOnAnImageField(): void
    {
        $view = $this->factory(['image'])->create(ImageUploadType::class)->createView();

        $this->assertTrue($view->vars['media_library']);
    }

    // finishView reads the kind after VideoUploadType::buildView set it.
    public function testAVideoFieldAsksForVideos(): void
    {
        $images = $this->factory(['image'])->create(VideoUploadType::class)->createView();
        $videos = $this->factory(['video'])->create(VideoUploadType::class)->createView();

        $this->assertFalse($images->vars['media_library']);
        $this->assertTrue($videos->vars['media_library']);
    }

    public function testTheAltFieldIsNamedAsTheBrowserPostsIt(): void
    {
        $view = $this->factory(null)
            ->createNamedBuilder('block', FormType::class)
            ->add('src', ImageUploadType::class, ['cb_alt_field' => 'alt'])
            ->add('alt', TextType::class)
            ->getForm()
            ->createView();

        $this->assertSame('block[alt]', $view['src']->vars['alt_field']);
        $this->assertSame($view['alt']->vars['full_name'], $view['src']->vars['alt_field']);
    }

    public function testAnUnnamedRootGivesTheBareName(): void
    {
        $view = $this->factory(null)
            ->createNamedBuilder('', FormType::class)
            ->add('src', ImageUploadType::class, ['cb_alt_field' => 'alt'])
            ->getForm()
            ->createView();

        $this->assertSame('alt', $view['src']->vars['alt_field']);
    }

    public function testNoAltFieldByDefault(): void
    {
        $view = $this->factory(null)->create(ImageUploadType::class)->createView();

        $this->assertNull($view->vars['alt_field']);
    }

    /** @param list<string>|null $kinds null registers no library at all */
    private function factory(?array $kinds): FormFactoryInterface
    {
        $library = $kinds === null ? null : new class ($kinds) implements MediaLibraryInterface {
            /** @param list<string> $kinds */
            public function __construct(private readonly array $kinds)
            {
            }

            public function supports(string $kind): bool
            {
                return \in_array($kind, $this->kinds, true);
            }
        };

        return Forms::createFormFactoryBuilder()
            ->addType(new ImageUploadType($library))
            ->getFormFactory();
    }
}
