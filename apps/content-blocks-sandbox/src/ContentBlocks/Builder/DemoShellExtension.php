<?php

declare(strict_types=1);

namespace App\ContentBlocks\Builder;

use ContentBlocks\Builder\BuilderShellExtensionInterface;
use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Entity\ContentArea;

/**
 * Sandbox demo of a bundle contributing its own UI to the builder shell.
 *
 * A real bundle (an area history, say) would render a dialog and a script
 * served from its own route. This one renders a single chip and an inline
 * module that does the smallest thing worth proving: it changes the area
 * through an existing package endpoint, then tells the builder to catch up
 * with `cb:area:changed`. The e2e suite drives it (shell-fragments.spec.js).
 *
 * No service definition anywhere: implementing the interface is the whole
 * registration, through the package's autoconfiguration.
 */
final class DemoShellExtension implements BuilderShellExtensionInterface
{
    public function getFragments(ContentArea $area): iterable
    {
        yield new BuilderShellFragment('page/_shell_fragment_demo.html.twig', [
            'label' => 'Demo fragment: add a section',
        ]);
    }
}
