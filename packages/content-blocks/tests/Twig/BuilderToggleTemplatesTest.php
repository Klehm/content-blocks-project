<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\UnpublishedChanges;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Tests\Fixtures\FixedBuilderStructureResolver;
use ContentBlocks\Twig\BuilderStructureExtension;
use ContentBlocks\Twig\SectionLayoutExtension;
use ContentBlocks\Twig\UnpublishedChangesExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders the builder shell / launcher templates to verify the `enable_replace`
 * and `enable_import_export` (or `enable_import` / `enable_export`) UI toggles
 * actually hide their buttons when set to false.
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
        $this->assertStringNotContainsString('<dialog class="cb-transfer"', $html);
    }

    public function testImportExportButtonIsShownByDefault(): void
    {
        $html = $this->renderShell([]);

        $this->assertStringContainsString('cb-shell__import-export', $html);
        $this->assertStringContainsString('<dialog class="cb-transfer"', $html);
    }

    public function testImportCanBeHiddenWhileExportStays(): void
    {
        $html = $this->renderShell(['enableImport' => false]);

        $this->assertStringContainsString('cb-shell__import-export', $html);
        $this->assertStringContainsString('cb.builder.transfer.tab_export</span>', $html);
        $this->assertStringContainsString('data-cb-transfer-panel="export"', $html);
        $this->assertStringNotContainsString('data-cb-transfer-panel="import"', $html);
        $this->assertStringNotContainsString('data-cb-transfer="file"', $html);
        $this->assertStringNotContainsString('role="tablist"', $html);
    }

    public function testExportCanBeHiddenWhileImportStays(): void
    {
        $html = $this->renderShell(['enableExport' => false]);

        $this->assertStringContainsString('cb.builder.transfer.tab_import</span>', $html);
        $this->assertStringContainsString('data-cb-transfer-panel="import"', $html);
        $this->assertStringNotContainsString('data-cb-transfer-panel="export"', $html);
        $this->assertStringNotContainsString('/area/1/export', $html);
        // Alone, the import panel is not hidden behind an absent tab.
        $this->assertDoesNotMatchRegularExpression('~data-cb-transfer-panel="import"\s+hidden~', $html);
    }

    public function testBothHalvesKeepTheTabs(): void
    {
        $html = $this->renderShell([]);

        $this->assertStringContainsString('cb.builder.import_export.open</span>', $html);
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertMatchesRegularExpression('~data-cb-transfer-panel="import"\s+hidden~', $html);
    }

    public function testEachHalfOverridesTheCombinedFlag(): void
    {
        $html = $this->renderShell(['enableImportExport' => false, 'enableExport' => true]);

        $this->assertStringContainsString('data-cb-transfer-panel="export"', $html);
        $this->assertStringNotContainsString('data-cb-transfer-panel="import"', $html);
    }

    public function testTheDialogExportsWithMediaByDefault(): void
    {
        $html = $this->renderShell([]);

        $this->assertMatchesRegularExpression(
            '~<input type="checkbox" class="cb-transfer__switch" data-cb-transfer="media" checked>~',
            $html,
        );
        $this->assertStringContainsString('href="/_content-blocks/area/1/export"', $html);
        $this->assertStringContainsString('data-cb-transfer-strings="{', $html);
    }

    public function testPublicLinkIsShownByDefault(): void
    {
        $html = $this->renderShell([]);

        $this->assertMatchesRegularExpression(
            '~<a class="cb-shell__public-link"\s+href="/page/1"\s+target="_blank"\s+rel="noopener"~',
            $html,
        );
    }

    public function testPublicLinkIsHiddenWhenDisabled(): void
    {
        $html = $this->renderShell(['enablePublicLink' => false]);

        $this->assertStringNotContainsString('cb-shell__public-link', $html);
    }

    /**
     * The launcher forwards the flags into the shell via an `{% include %}`.
     * A `false` must survive the hand-off rather than being re-defaulted to
     * true at the boundary.
     */
    public function testEditableSectionsOfferLayoutsAndTheLibrary(): void
    {
        $html = $this->renderShell([]);

        $this->assertStringContainsString('data-cb-sections="editable"', $html);
        $this->assertStringContainsString('data-cb-tree-sections-value="editable"', $html);
        $this->assertStringContainsString('cb-sidebar-empty__sections', $html);
        $this->assertStringContainsString('cb-sidebar-library', $html);
    }

    /**
     * Fixed or hidden, nothing adds a section: no layout buttons, no library.
     */
    #[DataProvider('lockedSections')]
    public function testLockedSectionsLeaveNoWayToAddOne(string $mode): void
    {
        $html = $this->renderShell([], new BuilderStructure($mode));

        $this->assertStringContainsString('data-cb-sections="' . $mode . '"', $html);
        $this->assertStringContainsString('data-cb-tree-sections-value="' . $mode . '"', $html);
        $this->assertStringNotContainsString('cb-sidebar-empty__sections', $html);
        $this->assertStringNotContainsString('cb-sidebar-library', $html);
        $this->assertStringContainsString('cb-sidebar-empty__hint', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function lockedSections(): iterable
    {
        yield 'fixed' => [BuilderStructure::SECTIONS_FIXED];
        yield 'hidden' => [BuilderStructure::SECTIONS_HIDDEN];
    }

    public function testLauncherForwardsDisabledFlagsToShell(): void
    {
        $html = $this->render('@ContentBlocks/builder/launcher.html.twig', [
            'area' => $this->makeArea(),
            'enableReplace' => false,
            'enableImportExport' => false,
            'enablePublicLink' => false,
        ]);

        $this->assertStringNotContainsString('cb-shell__replace', $html);
        $this->assertStringNotContainsString('cb-shell__import-export', $html);
        $this->assertStringNotContainsString('cb-shell__public-link', $html);
    }

    public function testLauncherForwardsEachHalfToShell(): void
    {
        $html = $this->render('@ContentBlocks/builder/launcher.html.twig', [
            'area' => $this->makeArea(),
            'enableImport' => false,
        ]);

        $this->assertStringContainsString('data-cb-transfer-panel="export"', $html);
        $this->assertStringNotContainsString('data-cb-transfer-panel="import"', $html);
    }

    /** @param array<string, mixed> $extra */
    private function renderShell(array $extra, ?BuilderStructure $structure = null): string
    {
        return $this->render('@ContentBlocks/builder/shell.html.twig', [
            'area' => $this->makeArea(),
            'iframeUrl' => 'about:blank',
        ] + $extra, $structure);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context, ?BuilderStructure $structure = null): string
    {
        return $this->makeTwig($structure)->render($template, $context);
    }

    private function makeArea(int $id = 1): ContentArea
    {
        $area = new ContentArea();
        $ref = new \ReflectionProperty($area::class, 'id');
        $ref->setValue($area, $id);

        return $area;
    }

    private function makeTwig(?BuilderStructure $structure = null): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ContentBlocks');

        // strict_variables surfaces any template var we forget to pass, so the
        // test fails loudly rather than silently rendering an empty toggle.
        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension($this->makeTranslator()));
        $env->addExtension(new SectionLayoutExtension(new SectionLayoutRegistry()));
        $env->addExtension(new BuilderStructureExtension(new FixedBuilderStructureResolver(
            $structure ?? new BuilderStructure(),
        )));
        $env->addExtension(new UnpublishedChangesExtension(new UnpublishedChanges()));
        // The shell renders a CSRF token; the value is irrelevant here.
        $env->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token'));
        $env->addFunction(new TwigFunction('cb_api_base', static fn (): string => '/_content-blocks'));
        // The shell asks for contributed fragments; none here (see
        // BuilderShellFragmentsTemplateTest for that contract).
        $env->addFunction(new TwigFunction('cb_shell_fragments', static fn (ContentArea $area): array => []));
        // The undo/redo pair starts from the journal; an empty stack here.
        $env->addFunction(new TwigFunction(
            'cb_public_url',
            static fn (ContentArea $area): string => '/page/' . $area->getId(),
        ));
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
