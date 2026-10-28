<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\DomCrawler\Crawler;

final class CatalogBlocksTest extends IntegrationTestCase
{
    private ChannelInterface $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = $this->fixtures->channel();
    }

    public function testTheProductBlockShowsTheShopsProductCard(): void
    {
        $this->fixtures->product($this->channel, 'MUG', 'Blue mug', 1999);

        $page = $this->render('product', ['product' => 'MUG', 'addToCart' => false]);

        self::assertStringContainsString('Blue mug', $page->filter('.cb-sylius-product')->text());
        self::assertStringContainsString('$19.99', $page->filter('.cb-sylius-product')->text());
        self::assertCount(1, $page->filter('.cb-sylius-product a[href="/en_US/products/mug"]'));
        self::assertCount(0, $page->filter('.cb-sylius-product form'));
    }

    public function testTheProductBlockCanCarryTheAddToCartForm(): void
    {
        $this->fixtures->product($this->channel, 'MUG', 'Blue mug', 1999);

        $page = $this->render('product', ['product' => 'MUG', 'addToCart' => true]);

        self::assertCount(1, $page->filter('.cb-sylius-product form [data-test-button="add-to-cart-button"]'));
        self::assertCount(1, $page->filter('.cb-sylius-product form input[name$="[quantity]"]'));
    }

    public function testTheAddToCartBlockRendersTheShopsForm(): void
    {
        $this->fixtures->product($this->channel, 'MUG', 'Blue mug', 1999);

        $page = $this->render('add_to_cart', ['product' => 'MUG']);

        $form = $page->filter('.cb-sylius-add-to-cart form');
        self::assertCount(1, $form);
        self::assertSame('addToCart', $form->attr('data-live-action-param'));
        self::assertCount(1, $form->filter('[data-test-button="add-to-cart-button"]'));
    }

    public function testAProductOfAnotherChannelOrDisabledIsLeftOut(): void
    {
        $other = $this->fixtures->channel('OTHER', 'other.example.com');
        $this->fixtures->product($other, 'ELSEWHERE', 'Sold elsewhere', 500);
        $this->fixtures->product($this->channel, 'OFF', 'Disabled one', 500, null, false);

        $page = $this->render('product_list', [
            'source' => 'manual',
            'products' => ['ELSEWHERE', 'OFF'],
            'layout' => 'grid',
            'columns' => 4,
        ]);

        self::assertCount(0, $page->filter('.cb-sylius-products'));
        self::assertStringNotContainsString('Sold elsewhere', $page->text());
        self::assertStringNotContainsString('Disabled one', $page->text());
    }

    public function testChosenProductsKeepTheirOrder(): void
    {
        $this->fixtures->product($this->channel, 'A', 'Product A', 100);
        $this->fixtures->product($this->channel, 'B', 'Product B', 200);

        $page = $this->render('product_list', [
            'source' => 'manual',
            'products' => ['B', 'A'],
            'layout' => 'grid',
            'columns' => 3,
        ]);

        $names = $page->filter('.cb-sylius-products [data-test-product-name]')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['Product B', 'Product A'], $names);
        self::assertStringContainsString('row-cols-md-3', (string) $page->filter('.cb-sylius-products')->attr('class'));
    }

    public function testATaxonSourceListsItsProductsAsACarousel(): void
    {
        $mugs = $this->fixtures->taxon('MUGS', 'Mugs');
        $this->fixtures->product($this->channel, 'IN', 'In the taxon', 100, $mugs);
        $this->fixtures->product($this->channel, 'OUT', 'Not in it', 100);

        $page = $this->render('product_list', [
            'source' => 'taxon',
            'taxon' => 'MUGS',
            'limit' => 4,
            'layout' => 'carousel',
            'columns' => 4,
        ]);

        $list = $page->filter('.cb-sylius-products--carousel');
        self::assertCount(1, $list);
        self::assertStringContainsString('In the taxon', $list->text());
        self::assertStringNotContainsString('Not in it', $list->text());
        // The core slider gives it arrows and dots: its markup and its script.
        self::assertCount(1, $list->filter('[data-cb-slider] > [data-cb-slider-track]'));
        self::assertCount(1, $page->filter('script[type="module"][src*="/_content-blocks/public/slider"]'));
    }

    public function testAGridLoadsNoSliderScript(): void
    {
        $this->fixtures->product($this->channel, 'MUG', 'Blue mug', 1999);

        $page = $this->render('product_list', ['source' => 'latest', 'limit' => 4, 'layout' => 'grid', 'columns' => 4]);

        self::assertCount(0, $page->filter('[data-cb-slider]'));
        self::assertCount(0, $page->filter('script[src*="/_content-blocks/public/slider"]'));
    }

    public function testTheLatestSourceHonoursTheLimit(): void
    {
        foreach (['P1', 'P2', 'P3'] as $code) {
            $this->fixtures->product($this->channel, $code, 'Product ' . $code, 100);
        }

        $page = $this->render('product_list', ['source' => 'latest', 'limit' => 2, 'layout' => 'grid', 'columns' => 4]);

        self::assertCount(2, $page->filter('.cb-sylius-products [data-test-product-name]'));
    }

    public function testThePriceBlockShowsTheChannelPrice(): void
    {
        $this->fixtures->product($this->channel, 'MUG', 'Blue mug', 1999);

        $page = $this->render('product_price', ['product' => 'MUG', 'size' => 'lg']);

        self::assertSame('$19.99', trim($page->filter('.cb-sylius-price [data-test-product-price]')->text()));
    }

    public function testTheTaxonListLinksTheEnabledChildren(): void
    {
        $root = $this->fixtures->taxon('CATALOG', 'Catalog');
        $this->fixtures->taxon('MUGS', 'Mugs', $root);
        $this->fixtures->taxon('CAPS', 'Caps', $root);

        $page = $this->render('taxon_list', ['source' => 'children', 'parent' => 'CATALOG', 'columns' => 4]);

        $links = $page->filter('.cb-sylius-taxons a')->each(
            static fn (Crawler $node): string => (string) $node->attr('href'),
        );
        sort($links);
        self::assertSame(['/en_US/taxons/caps', '/en_US/taxons/mugs'], $links);
    }

    public function testAnEmptyBlockShowsNothingToAVisitor(): void
    {
        $page = $this->render('product', ['product' => 'NOPE', 'addToCart' => false]);

        self::assertCount(1, $page->filter('.cb-block--product'));
        self::assertSame('', trim($page->filter('.cb-block--product')->text()));
    }

    /** @param array<string, mixed> $data */
    private function render(string $type, array $data): Crawler
    {
        $page = $this->fixtures->page('blocks', ['en_US' => 'Blocks'], $this->channel);
        $area = $page->getContentArea();
        \assert($area !== null);
        $this->fixtures->block($area, $type, $data);
        $this->clear();

        $crawler = $this->client->request('GET', '/en_US/pages/blocks');
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
