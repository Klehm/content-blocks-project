<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Form\Extension\AiFieldTypeExtension;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Tests\Fixtures\RecordingAiProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;

/**
 * Which fields get the ✦ button: prose, HTML and an image's `alt`, and only
 * while an action that fills them is on.
 */
final class AiFieldTypeExtensionTest extends TestCase
{
    public function testTranslatableProseGetsTheTextActions(): void
    {
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('title', TextType::class, ['cb_translatable' => true])
                ->add('body', TextareaType::class, ['cb_translatable' => true]);
        });

        $this->assertSame('text', $view['title']->vars['cb_ai']);
        $this->assertSame('text', $view['body']->vars['cb_ai']);
    }

    public function testAFieldThatIsNotProseGetsNothing(): void
    {
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('classes', TextType::class)
                ->add('link', UrlType::class, ['cb_translatable' => true]);
        });

        $this->assertArrayNotHasKey('cb_ai', $view['classes']->vars, 'not tagged: a CSS class, a slug');
        $this->assertArrayNotHasKey('cb_ai', $view['link']->vars, 'a URL is not rewritten');
    }

    public function testAnAltBesideAnImagePointsAtIt(): void
    {
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('src', ImageUploadType::class)->add('alt', TextType::class, ['cb_translatable' => true]);
        });

        $this->assertSame('alt', $view['alt']->vars['cb_ai']);
        $this->assertSame('form_src', $view['alt']->vars['cb_ai_image']);
    }

    public function testAnAltWithoutAnImageIsPlainProse(): void
    {
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('alt', TextType::class, ['cb_translatable' => true]);
        });

        $this->assertSame('text', $view['alt']->vars['cb_ai']);
    }

    public function testTheOptionDecidesOverTheInference(): void
    {
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('html', TextareaType::class, ['cb_translatable' => true, 'cb_ai' => false])
                ->add('raw', TextareaType::class, ['cb_translatable' => true, 'attr' => ['data-cb-ai' => 'off']])
                ->add('rich', TextareaType::class, ['cb_ai' => 'html']);
        });

        $this->assertArrayNotHasKey('cb_ai', $view['html']->vars);
        $this->assertArrayNotHasKey('cb_ai', $view['raw']->vars, 'the attribute works without the option');
        $this->assertSame('html', $view['rich']->vars['cb_ai']);
    }

    public function testNoButtonWhileTheActionsAreOff(): void
    {
        $text = [AiAction::REWRITE => false, AiAction::SHORTEN => false, AiAction::TONE => false];
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('title', TextType::class, ['cb_translatable' => true])
                ->add('src', ImageUploadType::class)
                ->add('alt', TextType::class, ['cb_translatable' => true]);
        }, new AiFeatures([new RecordingAiProvider([AiAction::ALT_TEXT])], $text));

        $this->assertArrayNotHasKey('cb_ai', $view['title']->vars);
        $this->assertArrayNotHasKey('cb_ai', $view['alt']->vars);
    }

    public function testOneTextActionLeftIsEnough(): void
    {
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('title', TextType::class, ['cb_translatable' => true]);
        }, new AiFeatures([new RecordingAiProvider()], [AiAction::REWRITE => false, AiAction::TONE => false]));

        $this->assertSame('text', $view['title']->vars['cb_ai']);
    }

    public function testNoButtonWithoutAProvider(): void
    {
        $view = $this->view(static function (FormBuilderInterface $b): void {
            $b->add('title', TextType::class, ['cb_translatable' => true]);
        }, new AiFeatures());

        $this->assertArrayNotHasKey('cb_ai', $view['title']->vars);
    }

    /**
     * @param \Closure(FormBuilderInterface): void $build
     */
    private function view(\Closure $build, ?AiFeatures $features = null): FormView
    {
        $builder = Forms::createFormFactoryBuilder()
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->addTypeExtension(new AiFieldTypeExtension($features ?? new AiFeatures([new RecordingAiProvider()])))
            ->getFormFactory()
            ->createBuilder(FormType::class);
        $build($builder);

        return $builder->getForm()->createView();
    }
}
