<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\Sylius\Slot\SlotType;

final class NativePagesTest extends IntegrationTestCase
{
    public function testTheHomepageIsNativeWithoutASlot(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->product($channel, 'MUG', 'Blue mug', 1999);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-latest-products]'));
    }

    public function testPublishedContentReplacesTheNativeHomepage(): void
    {
        $channel = $this->fixtures->channel();
        $slot = $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $this->fixtures->block($slot->getContentArea(), 'title', ['text' => 'Spring sale', 'tag' => 'h1']);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/');

        self::assertResponseIsSuccessful();
        self::assertSame('Spring sale', trim($crawler->filter('.cb-block--title h1')->text()));
        self::assertCount(0, $crawler->filter('[data-test-latest-products]'));
    }

    public function testAnUnpublishedSlotLeavesTheNativeHomepage(): void
    {
        $this->fixtures->channel();
        $slot = $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $this->fixtures->block($slot->getContentArea(), 'title', ['text' => 'Draft only'], publish: false);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/');

        self::assertCount(1, $crawler->filter('[data-test-latest-products]'));
        self::assertStringNotContainsString('Draft only', $crawler->text());
    }

    public function testEachChannelHasItsOwnHomepage(): void
    {
        $this->fixtures->channel();
        $this->fixtures->channel('OTHER', 'other.example.com');
        $slot = $this->fixtures->slot(SlotType::HOMEPAGE, 'OTHER');
        $this->fixtures->block($slot->getContentArea(), 'title', ['text' => 'Other channel']);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/');

        self::assertStringNotContainsString('Other channel', $crawler->text());
        self::assertCount(1, $crawler->filter('[data-test-latest-products]'));
    }

    public function testProductContentShowsBelowTheProductDetails(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->product($channel, 'MUG', 'Blue mug', 1999);
        $slot = $this->fixtures->slot(SlotType::PRODUCT, 'MUG');
        $this->fixtures->block($slot->getContentArea(), 'text', ['content' => 'Made in Portugal']);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/products/mug');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Made in Portugal', $crawler->filter('.cb-sylius-slot--product')->text());
    }

    public function testAProductWithoutContentHasNoSlotMarkup(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->product($channel, 'MUG', 'Blue mug', 1999);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/products/mug');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.cb-sylius-slot'));
    }

    public function testTaxonContentShowsAboveItsProducts(): void
    {
        $channel = $this->fixtures->channel();
        $taxon = $this->fixtures->taxon('MUGS', 'Mugs');
        $this->fixtures->product($channel, 'MUG', 'Blue mug', 1999, $taxon);
        $slot = $this->fixtures->slot(SlotType::TAXON, 'MUGS');
        $this->fixtures->block($slot->getContentArea(), 'text', ['content' => 'Our mugs are dishwasher safe']);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/taxons/mugs');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('dishwasher safe', $crawler->filter('.cb-sylius-slot--taxon')->text());
    }
}
