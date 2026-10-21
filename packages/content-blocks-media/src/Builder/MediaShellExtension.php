<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Builder;

use ContentBlocks\Builder\BuilderShellExtensionInterface;
use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Media\Controller\AssetController;
use ContentBlocks\Media\Security\MediaManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Puts the picker dialog in the builder shell. It answers `cb:media:browse`,
 * and reads nothing off the shell, so the i18n workbench renders it too.
 *
 * @see docs/internals/media.md#the-picker-is-self-contained
 */
final class MediaShellExtension implements BuilderShellExtensionInterface
{
    /** @param list<string> $kinds */
    public function __construct(
        private readonly array $kinds,
        private readonly MediaManagerInterface $manager,
        private readonly int $uploadMaxSize = 0,
    ) {
    }

    public function getFragments(ContentArea $area): iterable
    {
        yield new BuilderShellFragment('@ContentBlocksMedia/picker.html.twig', [
            'kinds' => $this->kinds,
            'canManage' => $this->manager->canManage(),
            'maxUploadSize' => $this->maxUploadSize(),
            'assetVersions' => [
                'css' => AssetController::version('css'),
                'js' => AssetController::version('js'),
            ],
        ], standalone: true);
    }

    /**
     * What an upload may weigh: the package's limit, or PHP's when lower,
     * so the picker refuses a file before sending it for nothing.
     */
    public function maxUploadSize(): int
    {
        $limits = array_filter([$this->uploadMaxSize, (int) UploadedFile::getMaxFilesize()], static fn (int $l) => $l > 0);

        return $limits === [] ? 0 : min($limits);
    }
}
