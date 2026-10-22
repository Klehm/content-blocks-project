<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Sylius\Image\LiipImageUrlResolver;

final class ShopAssetsTest extends IntegrationTestCase
{
    public function testEveryShopPageLinksThePluginStylesheet(): void
    {
        $this->fixtures->channel();

        $crawler = $this->client->request('GET', '/en_US/');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('head link[rel="stylesheet"][href^="/_content-blocks-sylius/public/shop?v="]');
        self::assertCount(1, $link);

        $this->client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/css; charset=UTF-8');
        // The slider's look belongs to the plugin, not to the core.
        self::assertStringContainsString('.cb-slider__dot', (string) $this->client->getResponse()->getContent());
    }

    public function testTheBuilderImagesGoThroughLiipImagine(): void
    {
        self::assertInstanceOf(LiipImageUrlResolver::class, static::getContainer()->get(ImageUrlResolverInterface::class));
    }
}
