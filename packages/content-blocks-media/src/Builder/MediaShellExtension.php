<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Builder;

use ContentBlocks\Builder\BuilderShellExtensionInterface;
use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Media\Controller\AssetController;

/**
 * Puts the picker dialog in the builder shell. It answers the upload widget's
 * `cb:media:browse`, and reads nothing off the shell.
 *
 * @see docs/internals/media.md#the-picker-is-self-contained
 */
final class MediaShellExtension implements BuilderShellExtensionInterface
{
    /** @param list<string> $kinds */
    public function __construct(private readonly array $kinds)
    {
    }

    public function getFragments(ContentArea $area): iterable
    {
        yield new BuilderShellFragment('@ContentBlocksMedia/picker.html.twig', [
            'kinds' => $this->kinds,
            'assetVersions' => [
                'css' => AssetController::version('css'),
                'js' => AssetController::version('js'),
            ],
        ]);
    }
}
