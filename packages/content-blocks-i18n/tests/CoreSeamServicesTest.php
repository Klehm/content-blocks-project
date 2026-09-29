<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests;

use ContentBlocks\I18n\ContentBlocksI18nBundle;
use ContentBlocks\I18n\Lifecycle\TranslationUnpublishedChanges;
use ContentBlocks\I18n\Snapshot\TranslationSnapshotExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The two services implementing core 1.2 seams are always registered: since
 * the core floor is ^1.2, nothing gates templates and the clipboard any more.
 */
final class CoreSeamServicesTest extends TestCase
{
    public function testBothServicesAreRegisteredUnconditionally(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new ContentBlocksI18nBundle())->getContainerExtension();
        $this->assertNotNull($extension);
        $container->registerExtension($extension);
        $extension->load([['source_locale' => 'en', 'locales' => ['fr']]], $container);

        $this->assertTrue($container->hasDefinition(TranslationSnapshotExtension::class));
        $this->assertTrue($container->hasDefinition(TranslationUnpublishedChanges::class));
    }
}
