<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use Symfony\Component\DomCrawler\Crawler;

final class BootstrapMarkupTest extends IntegrationTestCase
{
    public function testAKitButtonRendersBootstrapClasses(): void
    {
        $page = $this->render('button', ['text' => 'Shop now', 'url' => '/en_US/', 'variant' => 'outline', 'size' => 'lg', 'align' => 'center']);

        $button = $page->filter('.cb-block--button a');
        self::assertSame('btn btn-outline-primary btn-lg', $button->attr('class'));
        self::assertStringContainsString('justify-content-center', (string) $page->filter('.cb-block--button > div')->attr('class'));
    }

    public function testAKitAlertMapsErrorToDanger(): void
    {
        $page = $this->render('alert', ['type' => 'error', 'title' => '', 'content' => 'Out of stock']);

        self::assertCount(1, $page->filter('.cb-block--alert .alert.alert-danger'));
    }

    public function testAKitTitleKeepsItsTagAndTakesTheSizeClass(): void
    {
        $page = $this->render('title', ['text' => 'Hello', 'tag' => 'h2', 'size' => 'h1']);

        self::assertSame('h1 mb-0', $page->filter('.cb-block--title h2')->attr('class'));
    }

    public function testABlockWithoutABootstrapVariantKeepsTheKitMarkup(): void
    {
        $page = $this->render('text', ['content' => 'Plain text']);

        self::assertStringContainsString('Plain text', $page->filter('.cb-block--text')->text());
        self::assertCount(1, $page->filter('.cb-block--text .cb-block-text'));
    }

    public function testTheShopLinksTheKitStylesheet(): void
    {
        $page = $this->render('text', ['content' => 'Plain text']);

        self::assertCount(1, $page->filter('head link[href^="/_content-blocks-kit/public/kit"]'));
    }

    /** @param array<string, mixed> $data */
    private function render(string $type, array $data): Crawler
    {
        $channel = $this->fixtures->channel();
        $page = $this->fixtures->page('markup', ['en_US' => 'Markup'], $channel);
        $area = $page->getContentArea();
        \assert($area !== null);
        $this->fixtures->block($area, $type, $data);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/pages/markup');
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
