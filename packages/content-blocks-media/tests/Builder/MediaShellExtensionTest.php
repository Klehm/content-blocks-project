<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Builder;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Media\Builder\MediaShellExtension;
use ContentBlocks\Media\Controller\AssetController;
use ContentBlocks\Media\Security\AllowAllMediaManager;
use ContentBlocks\Media\Security\DenyAllMediaManager;
use PHPUnit\Framework\TestCase;

final class MediaShellExtensionTest extends TestCase
{
    public function testThePickerIsOneFragmentCarryingTheKindsAndAssetVersions(): void
    {
        $fragments = iterator_to_array((new MediaShellExtension(['image'], new DenyAllMediaManager()))->getFragments(new ContentArea()), false);

        $this->assertCount(1, $fragments);
        $this->assertSame('@ContentBlocksMedia/picker.html.twig', $fragments[0]->template);
        $this->assertSame(['image'], $fragments[0]->context['kinds']);
        $this->assertSame(AssetController::version('js'), $fragments[0]->context['assetVersions']['js']);
        $this->assertFalse($fragments[0]->context['canManage']);
        // Reads nothing off the shell: the i18n workbench renders it too.
        $this->assertTrue($fragments[0]->standalone);
    }

    public function testTheDialogKnowsWhetherItsEditorManages(): void
    {
        $fragments = iterator_to_array((new MediaShellExtension(['image'], new AllowAllMediaManager()))->getFragments(new ContentArea()), false);

        $this->assertTrue($fragments[0]->context['canManage']);
    }

    public function testTheTemplateExistsWhereTheFragmentNamesIt(): void
    {
        $this->assertFileExists(\dirname(__DIR__, 2) . '/templates/picker.html.twig');
    }

    // PHP refuses first when its limit is lower: the picker must know that one.
    public function testTheUploadLimitIsTheLowerOfThePackagesAndPhps(): void
    {
        $php = (int) \Symfony\Component\HttpFoundation\File\UploadedFile::getMaxFilesize();

        $huge = new MediaShellExtension(['image'], new DenyAllMediaManager(), \PHP_INT_MAX);
        $tiny = new MediaShellExtension(['image'], new DenyAllMediaManager(), 1024);

        $this->assertSame($php, $huge->maxUploadSize());
        $this->assertSame(min(1024, $php), $tiny->maxUploadSize());
        $fragments = iterator_to_array($tiny->getFragments(new ContentArea()), false);
        $this->assertSame($tiny->maxUploadSize(), $fragments[0]->context['maxUploadSize']);
    }
}
