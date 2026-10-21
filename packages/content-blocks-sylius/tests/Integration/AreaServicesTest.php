<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\LocalizedPageUrlResolverInterface;
use ContentBlocks\I18n\Locale\TargetLocalesProviderInterface;
use ContentBlocks\I18n\Workbench\WorkbenchBackUrlResolverInterface;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Sylius\I18n\ShopTargetLocalesProvider;
use ContentBlocks\Sylius\Security\AdminAccessChecker;
use ContentBlocks\Sylius\Slot\SlotType;

/** The ContentBlocks seams the plugin answers for the host. */
final class AreaServicesTest extends IntegrationTestCase
{
    public function testThePluginAnswersTheRequiredSeams(): void
    {
        self::assertInstanceOf(AdminAccessChecker::class, static::getContainer()->get(AccessCheckerInterface::class));
        self::assertInstanceOf(ShopTargetLocalesProvider::class, static::getContainer()->get(TargetLocalesProviderInterface::class));
    }

    public function testThePreviewUrlOfEachOwner(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->product($channel, 'MUG', 'Blue mug', 1999);
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $home = $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $product = $this->fixtures->slot(SlotType::PRODUCT, 'MUG');
        $resolver = static::getContainer()->get(ContentAreaUrlResolverInterface::class);
        \assert($resolver instanceof ContentAreaUrlResolverInterface);

        self::assertSame('/en_US/pages/about-us', $resolver->resolve($this->area($page->getContentArea())));
        self::assertSame('/en_US/', $resolver->resolve($home->getContentArea()));
        self::assertSame('/en_US/products/mug', $resolver->resolve($product->getContentArea()));
    }

    public function testAPageUntranslatedInTheDefaultLocaleIsPreviewedInAnother(): void
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['fr_FR' => 'A propos'], $channel);
        $resolver = static::getContainer()->get(ContentAreaUrlResolverInterface::class);
        \assert($resolver instanceof ContentAreaUrlResolverInterface);

        self::assertSame('/fr_FR/pages/a-propos', $resolver->resolve($this->area($page->getContentArea())));
    }

    public function testAnAreaTheHostOwnsIsNotGuessed(): void
    {
        $area = new ContentArea();
        $this->em->persist($area);
        $this->em->flush();
        $resolver = static::getContainer()->get(ContentAreaUrlResolverInterface::class);
        \assert($resolver instanceof ContentAreaUrlResolverInterface);

        $this->expectException(\LogicException::class);
        $resolver->resolve($area);
    }

    public function testLocalizedUrlsAreAbsoluteAndOnlyWhereTranslated(): void
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $resolver = static::getContainer()->get(LocalizedPageUrlResolverInterface::class);
        \assert($resolver instanceof LocalizedPageUrlResolverInterface);

        self::assertSame('http://localhost/en_US/pages/about-us', $resolver->resolve($this->area($page->getContentArea()), 'en_US'));
        self::assertNull($resolver->resolve($this->area($page->getContentArea()), 'fr_FR'));
    }

    public function testTheWorkbenchLeadsBackToTheAdminScreen(): void
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $slot = $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $resolver = static::getContainer()->get(WorkbenchBackUrlResolverInterface::class);
        \assert($resolver instanceof WorkbenchBackUrlResolverInterface);

        self::assertSame(
            sprintf('/admin/content-blocks/content-pages/%d/edit', $page->getId()),
            $resolver->resolve($this->area($page->getContentArea()), 'fr_FR'),
        );
        self::assertSame('/admin/content-blocks/slots/homepage/WEB', $resolver->resolve($slot->getContentArea(), 'fr_FR'));
    }

    public function testInsertContentListsPagesAndSlotsBySearch(): void
    {
        $channel = $this->fixtures->channel();
        $about = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $this->fixtures->page('delivery', ['en_US' => 'Delivery'], $channel);
        $home = $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $orphan = new ContentArea();
        $this->em->persist($orphan);
        $this->em->flush();
        $provider = static::getContainer()->get(ContentAreaProviderInterface::class);
        \assert($provider instanceof ContentAreaProviderInterface);

        $all = $provider->createQueryBuilder(null)->getQuery()->getResult();
        self::assertCount(3, $all);
        self::assertNotContains($orphan, $all);

        $found = $provider->createQueryBuilder('about')->getQuery()->getResult();
        self::assertSame([$about->getContentArea()], $found);
        self::assertStringStartsWith('Page · About us (about)', $provider->getLabel($this->area($about->getContentArea())));
        self::assertStringStartsWith('Homepage · WEB', $provider->getLabel($home->getContentArea()));
    }

    public function testTheShopLocalesAreTheTranslationTargets(): void
    {
        $this->fixtures->channel();
        $provider = static::getContainer()->get(TargetLocalesProviderInterface::class);
        \assert($provider instanceof TargetLocalesProviderInterface);

        self::assertSame(['fr_FR'], $provider->getTargetLocales());
    }

    private function area(?ContentArea $area): ContentArea
    {
        \assert($area !== null);

        return $area;
    }
}
