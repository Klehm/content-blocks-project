<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Icon\CoreUiIcons;
use ContentBlocks\Icon\UiIconProviderInterface;
use ContentBlocks\Icon\UiIconRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * A host's autoconfigured icon provider must be read before the core set,
 * which is what lets it redraw a core icon under the same name.
 */
final class UiIconServicesTest extends TestCase
{
    public function testAHostProviderIsReadBeforeTheCoreSet(): void
    {
        $container = new ContainerBuilder();
        (new ContentBlocksBundle())->build($container);

        $container->register(CoreUiIcons::class, CoreUiIcons::class)
            ->addTag('content_blocks.ui_icon_provider', ['priority' => -1000]);
        $container->register(HostUiIcons::class, HostUiIcons::class)->setAutoconfigured(true);
        $container->register(UiIconRegistry::class, UiIconRegistry::class)
            ->setArguments([new TaggedIteratorArgument('content_blocks.ui_icon_provider')])
            ->setPublic(true);
        $container->compile();

        $registry = $container->get(UiIconRegistry::class);
        \assert($registry instanceof UiIconRegistry);

        $this->assertStringContainsString('<circle r="9"/>', (string) $registry->svg('auto'));
        $this->assertTrue($registry->has('pos-tl'));
    }

    public function testTheShippedServicesAreWiredThatWay(): void
    {
        $container = new ContainerBuilder();
        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config')))->load('services.php');

        $core = $container->getDefinition(CoreUiIcons::class);
        $this->assertFalse($core->isAutoconfigured(), 'an autoconfigured copy of the tag would come first');
        $this->assertSame([['priority' => -1000]], $core->getTag('content_blocks.ui_icon_provider'));

        foreach ([
            \ContentBlocks\Form\Extension\SidebarLayoutTypeExtension::class,
            \ContentBlocks\Form\Extension\IconChoiceTypeExtension::class,
        ] as $extension) {
            // Tagged by FrameworkBundle's autoconfiguration, via the glob.
            $this->assertTrue($container->getDefinition($extension)->isAutoconfigured(), $extension);
        }
    }
}

final class HostUiIcons implements UiIconProviderInterface
{
    public function getIcons(): array
    {
        return ['auto' => '<circle r="9"/>'];
    }
}
