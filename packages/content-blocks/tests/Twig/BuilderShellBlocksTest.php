<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Twig\SectionLayoutExtension;
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
 * The shell's empty blocks, overridden the way a host does it: a template that
 * extends the shipped one and fills a block, nothing else copied.
 */
final class BuilderShellBlocksTest extends TestCase
{
    public function testEveryBlockRendersNothingUntilOverridden(): void
    {
        $html = $this->twig([])->render('@ContentBlocks/builder/shell.html.twig', $this->context());

        $this->assertStringNotContainsString('host-', $html);
    }

    public function testAHostFillsEachBlockInItsPlace(): void
    {
        $html = $this->twig([
            'host_shell.html.twig' => <<<'TWIG'
                {% extends '@ContentBlocks/builder/shell.html.twig' %}
                {% block cb_shell_topbar_left_end %}<i>host-left-end-{{ area.id }}</i>{% endblock %}
                {% block cb_shell_topbar_right_start %}<i>host-right-start</i>{% endblock %}
                {% block cb_shell_topbar_right_end %}<i>host-right-end</i>{% endblock %}
                {% block cb_shell_end %}<i>host-shell-end</i>{% endblock %}
                TWIG,
        ])->render('host_shell.html.twig', $this->context());

        $leftEnd = strpos($html, 'host-left-end-3');
        $rightStart = strpos($html, 'host-right-start');
        $publicLink = strpos($html, 'cb-shell__public-link');
        $publish = strpos($html, 'cb-shell__publish');
        $rightEnd = strpos($html, 'host-right-end');
        $shellEnd = strpos($html, 'host-shell-end');

        // In the left cluster, after the history pair.
        $this->assertGreaterThan(strpos($html, 'cb-shell__history'), $leftEnd);
        $this->assertLessThan(strpos($html, 'cb-shell__topbar-right'), $leftEnd);
        // Framing the right cluster, first and last.
        $this->assertLessThan($publicLink, $rightStart);
        $this->assertGreaterThan($publish, $rightEnd);
        $this->assertLessThan(strpos($html, '</header>'), $rightEnd);
        // After the shell's own fragments, still inside the shell.
        $this->assertGreaterThan(strpos($html, 'cb-shell__undo'), $shellEnd);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $area = new ContentArea();
        (new \ReflectionProperty($area, 'id'))->setValue($area, 3);

        return ['area' => $area, 'iframeUrl' => 'about:blank'];
    }

    /** @param array<string, string> $hostTemplates */
    private function twig(array $hostTemplates): Environment
    {
        $files = new FilesystemLoader();
        $files->addPath(__DIR__ . '/../../templates', 'ContentBlocks');

        $env = new Environment(
            new ChainLoader([new ArrayLoader($hostTemplates), $files]),
            ['strict_variables' => true],
        );
        $env->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $env->addExtension(new SectionLayoutExtension(new SectionLayoutRegistry()));
        $env->addFunction(new TwigFunction('csrf_token', static fn (): string => 'tok'));
        $env->addFunction(new TwigFunction('cb_api_base', static fn (): string => '/_content-blocks'));
        $env->addFunction(new TwigFunction('cb_shell_fragments', static fn (): array => []));
        $env->addFunction(new TwigFunction('cb_public_url', static fn (): string => '/page/3'));
        $env->addFunction(new TwigFunction(
            'cb_history_state',
            static fn (): array => ['canUndo' => false, 'canRedo' => false],
        ));
        $env->addFunction(new TwigFunction('cb_import_max_bytes', static fn (): int => 0));

        return $env;
    }
}
