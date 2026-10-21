<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Builder\ConfiguredBuilderStructureResolver;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Form\Extension\AiFieldTypeExtension;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Publishing\UnpublishedChanges;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Tests\Fixtures\RecordingAiProvider;
use ContentBlocks\Twig\AiExtension;
use ContentBlocks\Twig\BuilderStructureExtension;
use ContentBlocks\Twig\SectionLayoutExtension;
use ContentBlocks\Twig\UnpublishedChangesExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

/**
 * Without a provider the builder draws nothing AI; with one, each part
 * appears only for the actions it serves.
 */
final class BuilderAiTemplateTest extends TestCase
{
    public function testWithoutAProviderThereIsNoTraceOfAi(): void
    {
        $html = $this->shell(new AiFeatures());

        $this->assertStringNotContainsString('cb-shell__ai', $html);
        $this->assertStringNotContainsString('cb-ai', $html);
    }

    public function testWithAProviderTheGenerateButtonAndDialogAppear(): void
    {
        $html = $this->shell(new AiFeatures([new RecordingAiProvider()]));

        $this->assertStringContainsString('cb-shell__ai', $html);
        $this->assertStringContainsString('data-cb-builder-target="aiDialog"', $html);
        $this->assertStringContainsString(
            'data-cb-ai-actions="rewrite shorten tone alt_text add edit_section edit_page"',
            $html,
        );
        foreach (['add', 'edit_section', 'edit_page'] as $scope) {
            $this->assertStringContainsString('value="' . $scope . '"', $html);
        }
    }

    public function testFieldActionsAloneDrawThePopoverButNoDialog(): void
    {
        $off = [
            AiAction::ADD => false,
            AiAction::EDIT_SECTION => false,
            AiAction::EDIT_PAGE => false,
        ];
        $html = $this->shell(new AiFeatures([new RecordingAiProvider()], $off));

        $this->assertStringNotContainsString('cb-shell__ai"', $html);
        $this->assertStringNotContainsString('aiDialog', $html);
        $this->assertStringContainsString('data-cb-builder-target="aiPopover"', $html);
    }

    public function testOnlyTheEnabledScopeIsOffered(): void
    {
        $html = $this->shell(new AiFeatures([new RecordingAiProvider()], [AiAction::ADD => false]));

        $this->assertStringNotContainsString('value="add"', $html);
        $this->assertStringContainsString('value="edit_section"', $html);
        $this->assertStringNotContainsString('name="replace"', $html, 'gone: rewriting the page is an edit');
    }

    public function testTheEditsAloneStillOpenTheDialog(): void
    {
        $html = $this->shell(new AiFeatures([new RecordingAiProvider()], [
            AiAction::ADD => false,
            AiAction::EDIT_PAGE => false,
        ]));

        $this->assertStringContainsString('cb-shell__ai', $html);
        $this->assertStringContainsString('value="edit_section"', $html);
    }

    public function testAProseFieldRowCarriesItsTrigger(): void
    {
        $html = $this->row(new AiFeatures([new RecordingAiProvider()]));

        $this->assertStringContainsString('cb-form-row--ai', $html);
        $this->assertStringContainsString('data-cb-builder-kind-param="text"', $html);
        $this->assertStringContainsString('data-cb-builder-field-param="block_title"', $html);
        $this->assertStringContainsString('data-cb-builder-kind-param="alt"', $html);
        $this->assertStringContainsString('data-cb-builder-image-param="block_src"', $html);
    }

    public function testNoTriggerWithoutAProvider(): void
    {
        $this->assertStringNotContainsString('cb-ai-trigger', $this->row(new AiFeatures()));
    }

    private function shell(AiFeatures $features): string
    {
        $area = new ContentArea();
        (new \ReflectionProperty($area::class, 'id'))->setValue($area, 1);

        return $this->twig($features)->render('@ContentBlocks/builder/shell.html.twig', [
            'area' => $area,
            'iframeUrl' => 'about:blank',
        ]);
    }

    private function row(AiFeatures $features): string
    {
        $form = Forms::createFormFactoryBuilder()
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->addTypeExtension(new AiFieldTypeExtension($features))
            ->getFormFactory()
            ->createNamedBuilder('block', FormType::class)
            ->add('title', TextType::class, ['cb_translatable' => true])
            ->add('src', ImageUploadType::class)
            ->add('alt', TextType::class)
            ->getForm();

        return $this->twig($features)->createTemplate(
            "{% form_theme form '@ContentBlocks/form/cb_form_theme.html.twig' %}{{ form_widget(form) }}",
        )->render(['form' => $form->createView()]);
    }

    private function twig(AiFeatures $features): Environment
    {
        $root = \dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root . '/templates', 'ContentBlocks');
        $loader->addPath($root . '/vendor/symfony/twig-bridge/Resources/views/Form');

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $env->addExtension(new SectionLayoutExtension(new SectionLayoutRegistry()));
        $env->addExtension(new BuilderStructureExtension(new ConfiguredBuilderStructureResolver()));
        $env->addExtension(new AiExtension($features));
        $env->addExtension(new UnpublishedChangesExtension(new UnpublishedChanges()));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $env);
        $env->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $env->addExtension(new FormExtension());
        $env->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token'));
        $env->addFunction(new TwigFunction('cb_api_base', static fn (): string => '/_content-blocks'));
        $env->addFunction(new TwigFunction('cb_shell_fragments', static fn (ContentArea $area): array => []));
        $env->addFunction(new TwigFunction('cb_builder_actions', static fn ($area, array $own = []): array => $own));
        $env->addFunction(new TwigFunction('cb_public_url', static fn (ContentArea $area): string => '/page'));
        $env->addFunction(new TwigFunction(
            'cb_history_state',
            static fn (ContentArea $area): array => ['canUndo' => false, 'canRedo' => false],
        ));

        return $env;
    }
}
