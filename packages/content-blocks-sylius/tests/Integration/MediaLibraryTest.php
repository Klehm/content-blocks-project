<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\Entity\ContentArea;

final class MediaLibraryTest extends IntegrationTestCase
{
    private ContentArea $area;

    protected function setUp(): void
    {
        parent::setUp();
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $area = $page->getContentArea();
        self::assertNotNull($area);
        $this->area = $area;
    }

    public function testTheBuilderOffersTheLibraryToAnAdministrator(): void
    {
        $this->client->loginUser($this->fixtures->admin(), 'admin');
        $page = $this->fixtures->page('faq', ['en_US' => 'FAQ'], $this->fixtures->channel('SHOP'));

        $crawler = $this->client->request('GET', sprintf('/admin/content-blocks/content-pages/%d/edit', $page->getId()));

        self::assertResponseIsSuccessful();
        $picker = $crawler->filter('dialog[data-cb-media]');
        self::assertCount(1, $picker);
        // A shop administrator manages the library, not only browses it.
        self::assertSame('1', $picker->attr('data-can-manage'));
        $urls = json_decode((string) $picker->attr('data-urls'), true);
        self::assertIsArray($urls);
        self::assertSame('/admin/content-blocks/media/list', $urls['list']);
    }

    public function testAnAdministratorBrowsesTheLibrary(): void
    {
        $this->client->loginUser($this->fixtures->admin(), 'admin');

        $this->client->request('GET', '/admin/content-blocks/media/list', ['area' => $this->area->getId()]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('items', $body);
    }

    public function testTheLibraryIsBehindTheAdminFirewall(): void
    {
        $this->client->request('GET', '/admin/content-blocks/media/list', ['area' => $this->area->getId()]);

        self::assertResponseRedirects();
        self::assertStringEndsWith('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
