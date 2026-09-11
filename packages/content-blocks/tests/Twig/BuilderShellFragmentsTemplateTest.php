<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Entity\ContentArea;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders the real builder shell with contributed fragments, to pin down the
 * rendering contract a bundle relies on: where the fragment lands, what it
 * sees, and what it does not.
 */
final class BuilderShellFragmentsTemplateTest extends TestCase
{
    public function testWithNoExtensionTheShellRendersAsBefore(): void
    {
        $html = $this->renderShell([]);

        $this->assertStringContainsString('class="cb-shell"', $html);
        $this->assertStringNotContainsString('fragment-marker', $html);
    }

    public function testAFragmentIsRenderedInsideTheShellAfterTheChrome(): void
    {
        $html = $this->renderShell([new BuilderShellFragment('fragment')]);

        $fragmentAt = strpos($html, 'fragment-marker');
        $this->assertNotFalse($fragmentAt);
        // After the snackbar — the last piece of the builder's own chrome —
        // so an overlay stacks above it in DOM order …
        $this->assertGreaterThan(strpos($html, 'cb-shell__undo-btn'), $fragmentAt);
        // … and before the shell's closing tag, so a script inside can find
        // the CSRF token and the area id with `closest()`.
        $this->assertGreaterThan($fragmentAt, strrpos($html, '</div>'));
    }

    public function testTheFragmentSeesItsOwnContextAndTheArea(): void
    {
        $html = $this->renderShell([
            new BuilderShellFragment('fragment', ['greeting' => 'hello']),
        ], areaId: 77);

        $this->assertStringContainsString('greeting=hello', $html);
        $this->assertStringContainsString('area=77', $html);
    }

    /**
     * The shell's own variables are internal and may change; a fragment that
     * could read them would break when they do.
     */
    public function testTheFragmentDoesNotSeeTheShellsVariables(): void
    {
        $html = $this->renderShell([new BuilderShellFragment('leak-probe')]);

        $this->assertStringContainsString('iframeUrl-defined=no', $html);
    }

    public function testFragmentsRenderInTheOrderTheCollectionReturnsThem(): void
    {
        $html = $this->renderShell([
            new BuilderShellFragment('fragment', ['greeting' => 'first']),
            new BuilderShellFragment('fragment', ['greeting' => 'second']),
        ]);

        $this->assertLessThan(strpos($html, 'greeting=second'), strpos($html, 'greeting=first'));
    }

    /**
     * @param list<BuilderShellFragment> $fragments
     */
    private function renderShell(array $fragments, int $areaId = 1): string
    {
        return $this->makeTwig($fragments)->render('@ContentBlocks/builder/shell.html.twig', [
            'area' => $this->makeArea($areaId),
            'iframeUrl' => 'about:blank',
        ]);
    }

    private function makeArea(int $id): ContentArea
    {
        $area = new ContentArea();
        $ref = new \ReflectionProperty($area::class, 'id');
        $ref->setValue($area, $id);

        return $area;
    }

    /**
     * @param list<BuilderShellFragment> $fragments
     */
    private function makeTwig(array $fragments): Environment
    {
        $files = new FilesystemLoader();
        $files->addPath(__DIR__ . '/../../templates', 'ContentBlocks');
        $inline = new ArrayLoader([
            'fragment' => '<div class="fragment-marker">greeting={{ greeting|default("none") }} area={{ area.id }}</div>',
            // `defined` is the one test that does not trip strict_variables.
            'leak-probe' => '<div class="fragment-marker">iframeUrl-defined={{ iframeUrl is defined ? "yes" : "no" }}</div>',
        ]);

        $env = new Environment(new ChainLoader([$inline, $files]), ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension($this->makeTranslator()));
        $env->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token'));
        // Stand-in for ShellFragmentsExtension: this test is about the
        // template, and the extension has its own.
        $env->addFunction(new TwigFunction('cb_shell_fragments', static fn (ContentArea $area): array => $fragments));
        $env->addFunction(new TwigFunction(
            'cb_history_state',
            static fn (ContentArea $area): array => ['canUndo' => false, 'canRedo' => false],
        ));

        return $env;
    }

    private function makeTranslator(): TranslatorInterface
    {
        return new class () implements TranslatorInterface {
            use TranslatorTrait;
        };
    }
}
