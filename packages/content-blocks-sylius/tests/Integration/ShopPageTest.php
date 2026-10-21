<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

final class ShopPageTest extends IntegrationTestCase
{
    public function testAnEnabledPageShowsItsPublishedContent(): void
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $this->fixtures->block($this->area($page), 'title', ['text' => 'Who we are', 'tag' => 'h1']);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/pages/about-us');

        self::assertResponseIsSuccessful();
        self::assertSame('Who we are', trim($crawler->filter('.cb-sylius-page h1')->text()));
        self::assertStringContainsString('About us', $crawler->filter('title')->text());
    }

    public function testADraftIsNotShownToVisitors(): void
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $channel);
        $this->fixtures->block($this->area($page), 'title', ['text' => 'Not yet', 'tag' => 'h1'], publish: false);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/pages/about-us');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Not yet', $crawler->filter('.cb-sylius-page')->text());
    }

    public function testEachLocaleHasItsOwnSlug(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->page('about', ['en_US' => 'About us', 'fr_FR' => 'A propos'], $channel);
        $this->clear();

        $this->client->request('GET', '/fr_FR/pages/a-propos');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/fr_FR/pages/about-us');
        self::assertResponseStatusCodeSame(404);
    }

    public function testADisabledPageIsNotFound(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->page('about', ['en_US' => 'About us'], $channel, enabled: false);
        $this->clear();

        $this->client->request('GET', '/en_US/pages/about-us');

        self::assertResponseStatusCodeSame(404);
    }

    public function testADisabledPageIsShownToAnAdministrator(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->page('about', ['en_US' => 'About us'], $channel, enabled: false);
        $this->client->loginUser($this->fixtures->admin(), 'admin');
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/pages/about-us');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex"]'));
    }

    public function testAPageOfAnotherChannelIsNotFound(): void
    {
        $this->fixtures->channel();
        $other = $this->fixtures->channel('OTHER', 'other.example.com');
        $this->fixtures->page('about', ['en_US' => 'About us'], $other);
        $this->clear();

        $this->client->request('GET', '/en_US/pages/about-us');

        self::assertResponseStatusCodeSame(404);
    }

    private function area(\ContentBlocks\Sylius\Entity\ContentPage $page): \ContentBlocks\Entity\ContentArea
    {
        $area = $page->getContentArea();
        \assert($area !== null);

        return $area;
    }
}
