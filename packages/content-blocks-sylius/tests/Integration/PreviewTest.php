<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\Sylius\Slot\SlotType;

/** The builder's iframe is a shop page: the admin logged in elsewhere. */
final class PreviewTest extends IntegrationTestCase
{
    public function testAnAdminPreviewsTheDraftOnTheShopFirewall(): void
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $area = $page->getContentArea();
        \assert($area !== null);
        $this->fixtures->block($area, 'title', ['text' => 'Draft title'], publish: false);
        $this->client->loginUser($this->fixtures->admin(), 'admin');
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/pages/about-us?cb_preview=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Draft title', $crawler->filter('.cb-sylius-page')->text());
        self::assertCount(1, $crawler->filter('[data-cb-block-id]'));
        $groups = json_decode($crawler->filter('#cb-block-categories')->text(), true);
        self::assertContains(['name' => 'commerce', 'label' => 'Shop'], $groups);
    }

    public function testAVisitorAskingForAPreviewGetsThePublicPage(): void
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $area = $page->getContentArea();
        \assert($area !== null);
        $this->fixtures->block($area, 'title', ['text' => 'Draft title'], publish: false);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/pages/about-us?cb_preview=1');

        self::assertStringNotContainsString('Draft title', $crawler->text());
        self::assertCount(0, $crawler->filter('[data-cb-block-id]'));
    }

    public function testAnEmptyHomepageDraftKeepsTheNativeSectionsUnderTheBuilder(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->product($channel, 'MUG', 'Blue mug', 1999);
        $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $this->client->loginUser($this->fixtures->admin(), 'admin');
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/?cb_preview=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-latest-products]'));
        self::assertCount(1, $crawler->filter('#cb-block-types'), 'The builder chrome is there to add a section.');
    }

    public function testAHomepageDraftWithContentReplacesTheNativeSectionsInPreview(): void
    {
        $channel = $this->fixtures->channel();
        $slot = $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $this->fixtures->block($slot->getContentArea(), 'title', ['text' => 'Coming soon'], publish: false);
        $this->client->loginUser($this->fixtures->admin(), 'admin');
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/?cb_preview=1');

        self::assertStringContainsString('Coming soon', $crawler->text());
        self::assertCount(0, $crawler->filter('[data-test-latest-products]'));
    }
}
