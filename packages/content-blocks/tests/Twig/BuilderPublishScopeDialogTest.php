<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Builder\ConfiguredBuilderStructureResolver;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\LocaleDrafts;
use ContentBlocks\Publishing\LocaleDraftsProviderInterface;
use ContentBlocks\Publishing\UnpublishedChanges;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Twig\AiExtension;
use ContentBlocks\Twig\BuilderStructureExtension;
use ContentBlocks\Twig\SectionLayoutExtension;
use ContentBlocks\Twig\UnpublishedChangesExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The locale dialog of Publish and Discard is in the shell only when a bundle
 * keeps locale drafts: without one, the builder asks nothing.
 */
final class BuilderPublishScopeDialogTest extends TestCase
{
    public function testNoProviderRendersNoDialog(): void
    {
        $html = $this->renderShell(new LocaleDrafts());

        $this->assertStringNotContainsString('cb-publish-scope', $html);
    }

    public function testAProviderRendersTheDialogAsABuilderTarget(): void
    {
        $provider = $this->createStub(LocaleDraftsProviderInterface::class);

        $html = $this->renderShell(new LocaleDrafts([$provider]));

        $this->assertStringContainsString('data-cb-builder-target="publishScopeDialog"', $html);
        $this->assertMatchesRegularExpression('/<dialog class="cb-publish-scope"/', $html);
        // Read by the JS, so a host translation reaches the dialog.
        $this->assertStringContainsString('data-cb-publish-scope-strings=', $html);
    }

    private function renderShell(LocaleDrafts $drafts): string
    {
        $area = new ContentArea();
        (new \ReflectionProperty($area::class, 'id'))->setValue($area, 1);

        return $this->makeTwig($drafts)->render('@ContentBlocks/builder/shell.html.twig', [
            'area' => $area,
            'iframeUrl' => 'about:blank',
        ]);
    }

    private function makeTwig(LocaleDrafts $drafts): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ContentBlocks');

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $env->addExtension(new SectionLayoutExtension(new SectionLayoutRegistry()));
        $env->addExtension(new BuilderStructureExtension(new ConfiguredBuilderStructureResolver()));
        $env->addExtension(new AiExtension(new AiFeatures()));
        $env->addExtension(new UnpublishedChangesExtension(new UnpublishedChanges(), $drafts));
        $env->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token'));
        $env->addFunction(new TwigFunction('cb_api_base', static fn (): string => '/_content-blocks'));
        $env->addFunction(new TwigFunction('cb_shell_fragments', static fn (ContentArea $area): array => []));
        $env->addFunction(new TwigFunction('cb_public_url', static fn (ContentArea $area): string => '/page/1'));
        $env->addFunction(new TwigFunction(
            'cb_history_state',
            static fn (ContentArea $area): array => ['canUndo' => false, 'canRedo' => false],
        ));

        return $env;
    }
}
