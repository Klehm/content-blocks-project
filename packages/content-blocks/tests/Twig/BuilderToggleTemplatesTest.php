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
 * Renders the builder shell / launcher templates to verify the `enable_replace`
 * and `enable_import_export` UI toggles actually hide their buttons when set to
 * false.
 *
 * Regression guard: these flags used to be read with `|default(true)`, which in
 * Twig treats a boolean `false` as "empty" and falls back to `true` — so a host
 * passing `false` could never turn the feature off. The fix switched to the
 * null-coalescing `?? true`, which only defaults when the value is undefined.
 */
final class BuilderToggleTemplatesTest extends TestCase
{
    public function testReplaceButtonIsHiddenWhenDisabled(): void
    {
        $html = $this->renderShell(['enableReplace' => false]);

        $this->assertStringNotContainsString('cb-shell__replace', $html);
        $this->assertStringNotContainsString('cb-replace-picker', $html);
    }

    public function testReplaceButtonIsShownByDefault(): void
    {
        // Flag omitted entirely → `?? true` keeps the button (back-compat).
        $html = $this->renderShell([]);

        $this->assertStringContainsString('cb-shell__replace', $html);
        $this->assertStringContainsString('cb-replace-picker', $html);
    }

    public function testImportExportButtonIsHiddenWhenDisabled(): void
    {
        $html = $this->renderShell(['enableImportExport' => false]);

        $this->assertStringNotContainsString('cb-shell__import-export', $html);
        $this->assertStringNotContainsString('cb-import-export-picker', $html);
    }

    public function testImportExportButtonIsShownByDefault(): void
    {
        $html = $this->renderShell([]);

        $this->assertStringContainsString('cb-shell__import-export', $html);
        $this->assertStringContainsString('cb-import-export-picker', $html);
    }

    /**
     * The launcher forwards the flags into the shell via an `{% include %}`.
     * A `false` must survive the hand-off rather than being re-defaulted to
     * true at the boundary.
     */
    public function testLauncherForwardsDisabledFlagsToShell(): void
    {
        $html = $this->render('@ContentBlocks/builder/launcher.html.twig', [
            'area' => $this->makeArea(),
            'enableReplace' => false,
            'enableImportExport' => false,
        ]);

        $this->assertStringNotContainsString('cb-shell__replace', $html);
        $this->assertStringNotContainsString('cb-shell__import-export', $html);
    }

    /** @param array<string, mixed> $extra */
    private function renderShell(array $extra): string
    {
        return $this->render('@ContentBlocks/builder/shell.html.twig', [
            'area' => $this->makeArea(),
            'iframeUrl' => 'about:blank',
        ] + $extra);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): string
    {
        return $this->makeTwig()->render($template, $context);
    }

    private function makeArea(int $id = 1): ContentArea
    {
        $area = new ContentArea();
        $ref = new \ReflectionProperty($area::class, 'id');
        $ref->setValue($area, $id);

        return $area;
    }

    private function makeTwig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ContentBlocks');

        // strict_variables surfaces any template var we forget to pass, so the
        // test fails loudly rather than silently rendering an empty toggle.
        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension($this->makeTranslator()));
        // The shell renders a CSRF token; the value is irrelevant here.
        $env->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token'));

        return $env;
    }

    private function makeTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            use TranslatorTrait;
        };
    }
}
