<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Entity\ContentArea;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The topbar's undo/redo pair. Their starting state comes from the journal
 * through `cb_history_state`, because the stack survives a page reload.
 */
final class BuilderHistoryButtonsTest extends TestCase
{
    public function testBothButtonsAreRenderedEvenWithAnEmptyStack(): void
    {
        $html = $this->renderShell(false, false);

        // Greyed, never absent: the pair is where the shortcut is discovered.
        $this->assertStringContainsString('cb-shell__history-btn--undo', $html);
        $this->assertStringContainsString('cb-shell__history-btn--redo', $html);
    }

    public function testAnEmptyStackDisablesBoth(): void
    {
        $html = $this->renderShell(false, false);

        $this->assertSame(2, substr_count($html, 'cb-shell__history-btn--'), 'guard: two buttons');
        $this->assertSame(2, $this->disabledCount($html));
    }

    /** The stack is a table, so a reload must not grey out a live undo. */
    public function testAStackSurvivingAReloadEnablesUndoOnly(): void
    {
        $html = $this->renderShell(true, false);

        $this->assertStringNotContainsString('disabled', $this->undoTag($html));
        $this->assertStringContainsString('disabled', $this->redoTag($html));
    }

    public function testAnUndoneEntryEnablesRedo(): void
    {
        $html = $this->renderShell(false, true);

        $this->assertStringContainsString('disabled', $this->undoTag($html));
        $this->assertStringNotContainsString('disabled', $this->redoTag($html));
    }

    public function testTheButtonsCarryTheirShortcut(): void
    {
        $html = $this->renderShell(true, true);

        // The pair is where an editor learns the chord exists.
        $this->assertStringContainsString('Ctrl+Z', $html);
        $this->assertStringContainsString('Ctrl+Shift+Z', $html);
    }

    // ---------- helpers ----------

    private function undoTag(string $html): string
    {
        return $this->tagAround($html, 'cb-shell__history-btn--undo');
    }

    private function redoTag(string $html): string
    {
        return $this->tagAround($html, 'cb-shell__history-btn--redo');
    }

    /** The single `<button …>` opening tag carrying $marker. */
    private function tagAround(string $html, string $marker): string
    {
        $at = strpos($html, $marker);
        self::assertNotFalse($at, $marker . ' not rendered');
        $open = strrpos(substr($html, 0, $at), '<button');
        self::assertNotFalse($open);
        $close = strpos($html, '>', $at);
        self::assertNotFalse($close);

        return substr($html, $open, $close - $open + 1);
    }

    private function disabledCount(string $html): int
    {
        return substr_count($this->undoTag($html) . $this->redoTag($html), 'disabled');
    }

    private function renderShell(bool $canUndo, bool $canRedo): string
    {
        return $this->makeTwig($canUndo, $canRedo)->render('@ContentBlocks/builder/shell.html.twig', [
            'area' => $this->makeArea(),
            'iframeUrl' => 'about:blank',
        ]);
    }

    private function makeArea(int $id = 1): ContentArea
    {
        $area = new ContentArea();
        (new \ReflectionProperty($area::class, 'id'))->setValue($area, $id);

        return $area;
    }

    private function makeTwig(bool $canUndo, bool $canRedo): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ContentBlocks');

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension($this->makeTranslator()));
        $env->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token'));
        $env->addFunction(new TwigFunction('cb_shell_fragments', static fn (ContentArea $area): array => []));
        $env->addFunction(new TwigFunction(
            'cb_history_state',
            static fn (ContentArea $area): array => ['canUndo' => $canUndo, 'canRedo' => $canRedo],
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
