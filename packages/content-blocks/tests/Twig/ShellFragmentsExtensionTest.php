<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Builder\BuilderShellExtensionInterface;
use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Builder\BuilderShellFragmentCollection;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Twig\ShellFragmentsExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ShellFragmentsExtensionTest extends TestCase
{
    public function testTheFunctionIsNamedCbShellFragments(): void
    {
        $extension = new ShellFragmentsExtension(new BuilderShellFragmentCollection([]));

        $names = array_map(static fn ($f) => $f->getName(), $extension->getFunctions());

        $this->assertSame(['cb_shell_fragments'], $names);
    }

    public function testTheFunctionReturnsTheCollectionsOrderedList(): void
    {
        $extension = new ShellFragmentsExtension(new BuilderShellFragmentCollection([
            new class () implements BuilderShellExtensionInterface {
                public function getFragments(ContentArea $area): iterable
                {
                    yield new BuilderShellFragment('late.html.twig', priority: -1);
                    yield new BuilderShellFragment('early.html.twig', priority: 1);
                }
            },
        ]));
        $twig = new Environment(new ArrayLoader([
            'list' => '{% for f in cb_shell_fragments(area) %}{{ f.template }};{% endfor %}',
        ]), ['strict_variables' => true]);
        $twig->addExtension($extension);

        $this->assertSame('early.html.twig;late.html.twig;', $twig->render('list', ['area' => new ContentArea()]));
    }
}
