<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Builder;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Media\Builder\MediaShellExtension;
use ContentBlocks\Media\Controller\AssetController;
use PHPUnit\Framework\TestCase;

final class MediaShellExtensionTest extends TestCase
{
    public function testThePickerIsOneFragmentCarryingTheKindsAndAssetVersions(): void
    {
        $fragments = iterator_to_array((new MediaShellExtension(['image']))->getFragments(new ContentArea()), false);

        $this->assertCount(1, $fragments);
        $this->assertSame('@ContentBlocksMedia/picker.html.twig', $fragments[0]->template);
        $this->assertSame(['image'], $fragments[0]->context['kinds']);
        $this->assertSame(AssetController::version('js'), $fragments[0]->context['assetVersions']['js']);
    }

    public function testTheTemplateExistsWhereTheFragmentNamesIt(): void
    {
        $this->assertFileExists(\dirname(__DIR__, 2) . '/templates/picker.html.twig');
    }
}
