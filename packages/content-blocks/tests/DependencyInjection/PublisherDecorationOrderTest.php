<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\ContentAreaPublisher;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\EventDispatchingPublisher;
use ContentBlocks\Publishing\JournalPruningPublisher;
use ContentBlocks\Publishing\PublishContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\DecoratorServicePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Publish events go out once every decorator has committed — a host's, the
 * i18n one — and still go out when the host replaced the publisher.
 */
final class PublisherDecorationOrderTest extends TestCase
{
    public function testTheEventDecoratorWrapsAHostDecorator(): void
    {
        $container = $this->loadServices();
        $container->register('app.purging_publisher', HostPublisher::class)
            ->setDecoratedService(ContentAreaPublisherInterface::class)
            ->setArgument('$inner', new Reference('.inner'));

        (new DecoratorServicePass())->process($container);

        $this->assertSame(
            [EventDispatchingPublisher::class, HostPublisher::class, JournalPruningPublisher::class],
            $this->chain($container),
        );
    }

    public function testAReplacedPublisherStillDispatches(): void
    {
        $container = $this->loadServices();
        $container->register('app.publisher', HostPublisher::class)
            ->setArgument('$inner', null);
        $container->setAlias(ContentAreaPublisherInterface::class, 'app.publisher');

        (new DecoratorServicePass())->process($container);

        $this->assertSame(
            [EventDispatchingPublisher::class, HostPublisher::class],
            $this->chain($container),
        );
    }

    /**
     * Classes from the interface inwards, following each `$inner`.
     *
     * @return list<class-string>
     */
    private function chain(ContainerBuilder $container): array
    {
        $classes = [];
        $id = (string) $container->getAlias(ContentAreaPublisherInterface::class);
        while (true) {
            while ($container->hasAlias($id)) {
                $id = (string) $container->getAlias($id);
            }
            $definition = $container->getDefinition($id);
            $class = $definition->getClass() ?? $id;
            if ($class === ContentAreaPublisher::class) {
                return $classes;
            }
            $classes[] = $class;
            $inner = $definition->getArguments()['$inner'] ?? null;
            if (!$inner instanceof Reference) {
                return $classes;
            }
            $id = (string) $inner;
        }
    }

    private function loadServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $configDir = \dirname(__DIR__, 2) . '/config';

        (new PhpFileLoader($container, new FileLocator($configDir)))->load('services.php');

        return $container;
    }
}

final class HostPublisher implements ContentAreaPublisherInterface
{
    public function __construct(private readonly ?ContentAreaPublisherInterface $inner)
    {
    }

    public function publish(ContentArea $area, ?PublishContext $context = null): void
    {
        $this->inner?->publish($area, $context);
    }

    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
    {
        $this->inner?->discardDraft($area, $context);
    }
}
