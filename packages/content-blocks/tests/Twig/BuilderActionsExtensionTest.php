<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionCollection;
use ContentBlocks\Builder\BuilderActionProviderInterface;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Twig\BuilderActionsExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class BuilderActionsExtensionTest extends TestCase
{
    // A host including the launcher directly passes only its own actions:
    // the providers' must show all the same.
    public function testTheShellGetsTheProvidersActionsBesideItsOwn(): void
    {
        $twig = new Environment(new ArrayLoader([
            'menu' => '{% for a in cb_builder_actions(area, own) %}[{{ a.key }}]{% endfor %}',
        ]));
        $twig->addExtension(new BuilderActionsExtension(new BuilderActionCollection([
            new class () implements BuilderActionProviderInterface {
                public function getActions(ContentArea $area): iterable
                {
                    yield new BuilderAction('library', 'Library');
                }
            },
        ])));

        $html = $twig->render('menu', [
            'area' => new ContentArea(),
            'own' => [['key' => 'translate', 'label' => 'Translate'], ['key' => 'library', 'label' => 'Twice']],
        ]);

        // Deduplicated by key: ContentAreaType has merged them already.
        $this->assertSame('[library][translate]', $html);
    }
}
