<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Builder;

use ContentBlocks\Builder\BuilderShellExtensionInterface;
use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Seo\Controller\AssetController;

/**
 * Puts the audit panel in the builder shell, hidden until its action opens it.
 */
final class SeoShellExtension implements BuilderShellExtensionInterface
{
    /**
     * @param array{
     *     paragraphMaxWords: int,
     *     genericLinkTexts: list<string>,
     *     disabled: list<string>,
     * } $auditOptions
     */
    public function __construct(private readonly array $auditOptions)
    {
    }

    public function getFragments(ContentArea $area): iterable
    {
        yield new BuilderShellFragment('@ContentBlocksSeo/panel.html.twig', [
            'auditOptions' => $this->auditOptions,
            'assetVersions' => [
                'css' => AssetController::version('css'),
                'js' => AssetController::version('js'),
                'audit' => AssetController::version('audit'),
            ],
        ]);
    }
}
