<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests;

use ContentBlocks\Media\ContentBlocksMediaBundle;
use ContentBlocks\Media\Library\MediaLibrary;
use ContentBlocks\Media\Storage\RecordingFileStorage;
use ContentBlocks\Media\Storage\RecordingInventoriedFileStorage;
use ContentBlocks\Media\Tests\Fixtures\PlainStorage;
use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\LocalFileStorage;
use ContentBlocks\Storage\MediaLibraryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BundleConfigurationTest extends TestCase
{
    public function testTheLibraryOffersImagesAndVideosByDefault(): void
    {
        $library = $this->compile([])->get(MediaLibraryInterface::class);

        $this->assertInstanceOf(MediaLibrary::class, $library);
        $this->assertTrue($library->supports('image'));
        $this->assertTrue($library->supports('video'));
    }

    public function testTheKindsCanBeNarrowed(): void
    {
        $library = $this->compile(['kinds' => ['image']])->get(MediaLibraryInterface::class);

        $this->assertTrue($library->supports('image'));
        $this->assertFalse($library->supports('video'));
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown kind "pdf"');

        $this->compile(['kinds' => ['image', 'pdf']]);
    }

    // Whatever the host aliased, its inventory survives for the asset GC.
    public function testTheHostStorageIsDecoratedAndKeepsItsInventory(): void
    {
        $storage = $this->compile([])->get(FileStorageInterface::class);

        $this->assertInstanceOf(RecordingInventoriedFileStorage::class, $storage);
        $this->assertInstanceOf(AssetInventoryInterface::class, $storage);
    }

    public function testAStorageWithoutInventoryIsDecoratedWithoutOne(): void
    {
        $storage = $this->compile([], inventory: false)->get(FileStorageInterface::class);

        $this->assertInstanceOf(RecordingFileStorage::class, $storage);
    }

    /** @param array<string, mixed> $config */
    private function compile(array $config, bool $inventory = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new ContentBlocksMediaBundle();
        $extension = $bundle->getContainerExtension();
        \assert($extension !== null);
        $container->registerExtension($extension);
        $container->loadFromExtension('content_blocks_media', $config);

        if ($inventory) {
            $container->register(LocalFileStorage::class, LocalFileStorage::class)->setArguments([sys_get_temp_dir()]);
            $container->setAlias(FileStorageInterface::class, LocalFileStorage::class);
        } else {
            $container->register(PlainStorage::class, PlainStorage::class);
            $container->setAlias(FileStorageInterface::class, PlainStorage::class);
        }
        $container->register(Connection::class)->setSynthetic(true);

        $container->addCompilerPass(new class () implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ([FileStorageInterface::class, MediaLibraryInterface::class] as $id) {
                    $container->hasAlias($id)
                        ? $container->getAlias($id)->setPublic(true)
                        : $container->getDefinition($id)->setPublic(true);
                }
            }
        });
        $container->compile();
        $container->set(Connection::class, $this->createMock(Connection::class));

        return $container;
    }
}
