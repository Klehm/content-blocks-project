<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentSlot;
use ContentBlocks\Sylius\Slot\SlotType;

final class AdminTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures->channel();
        $this->client->loginUser($this->fixtures->admin(), 'admin');
    }

    public function testTheMenuLeadsToThePagesAndTheHomepages(): void
    {
        $crawler = $this->client->request('GET', '/admin/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/content-blocks/content-pages/"]'));
        self::assertCount(1, $crawler->filter('a[href="/admin/content-blocks/homepage"]'));
    }

    public function testThePagesGridListsThePages(): void
    {
        $this->fixtures->page('about', ['en_US' => 'About us'], $this->channel());
        $this->clear();

        $crawler = $this->client->request('GET', '/admin/content-blocks/content-pages/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('About us', $crawler->filter('table')->text());
    }

    public function testAPageIsCreatedFromTheForm(): void
    {
        $crawler = $this->client->request('GET', '/admin/content-blocks/content-pages/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller~="cb-builder-launcher"]'));

        $form = $crawler->filter('form[name="content_blocks_sylius_content_page"]')->form();
        $form['content_blocks_sylius_content_page[code]'] = 'delivery';
        $form['content_blocks_sylius_content_page[enabled]']->tick();
        $form['content_blocks_sylius_content_page[channels][0]']->tick();
        $form['content_blocks_sylius_content_page[translations][en_US][title]'] = 'Delivery & returns';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $page = $this->em->getRepository(ContentPage::class)->findOneBy(['code' => 'delivery']);
        self::assertInstanceOf(ContentPage::class, $page);
        self::assertNotNull($page->getContentArea());
        self::assertSame('delivery-returns', $page->getSlugIn('en_US'));
        self::assertNull($page->getSlugIn('fr_FR'), 'A locale left blank is no translation.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompletePages(): iterable
    {
        yield 'no code' => ['', 'Delivery'];
        yield 'no title' => ['delivery', ''];
        yield 'a code with spaces' => ['the delivery', 'Delivery'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompletePages')]
    public function testAPageNeedsAValidCodeAndATitle(string $code, string $title): void
    {
        $crawler = $this->client->request('GET', '/admin/content-blocks/content-pages/new');
        $form = $crawler->filter('form[name="content_blocks_sylius_content_page"]')->form();
        $form['content_blocks_sylius_content_page[code]'] = $code;
        $form['content_blocks_sylius_content_page[translations][en_US][title]'] = $title;
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(ContentPage::class)->findAll());
    }

    public function testASlugIsUniquePerLocale(): void
    {
        $this->fixtures->page('about', ['en_US' => 'About us'], $this->channel());
        $this->clear();
        $crawler = $this->client->request('GET', '/admin/content-blocks/content-pages/new');
        $form = $crawler->filter('form[name="content_blocks_sylius_content_page"]')->form();
        $form['content_blocks_sylius_content_page[code]'] = 'about_again';
        $form['content_blocks_sylius_content_page[translations][en_US][title]'] = 'About us';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheEditFormCarriesTheBuilder(): void
    {
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $this->channel());
        $this->clear();

        $crawler = $this->client->request('GET', sprintf('/admin/content-blocks/content-pages/%d/edit', $page->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-content-blocks-builder] [data-controller~="cb-builder-launcher"]'));
        self::assertCount(1, $crawler->filter('[data-test-content-blocks-builder] .card-header a[href="/en_US/pages/about-us"]'));
    }

    public function testOpeningASlotWritesNothingUntilAsked(): void
    {
        $crawler = $this->client->request('GET', '/admin/content-blocks/slots/homepage/WEB');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->em->getRepository(ContentSlot::class)->findAll());

        $this->client->submit($crawler->filter('[data-test-content-blocks-slot] form')->form());
        self::assertResponseRedirects('/admin/content-blocks/slots/homepage/WEB');

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $this->em->getRepository(ContentSlot::class)->findAll());
        self::assertCount(1, $crawler->filter('[data-test-content-blocks-slot] [data-controller~="cb-builder-launcher"]'));
    }

    public function testASlotNeedsAValidCsrfToken(): void
    {
        $this->client->request('POST', '/admin/content-blocks/slots/homepage/WEB', ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(ContentSlot::class)->findAll());
    }

    public function testASlotOfAnUnknownSubjectIsNotFound(): void
    {
        $this->client->request('GET', '/admin/content-blocks/slots/product/NOPE');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheHomepageScreenListsTheChannels(): void
    {
        $slot = $this->fixtures->slot(SlotType::HOMEPAGE, 'WEB');
        $this->fixtures->block($slot->getContentArea(), 'title', ['text' => 'Hello']);
        $this->clear();

        $crawler = $this->client->request('GET', '/admin/content-blocks/homepage');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-test-content-blocks-homepages] tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('Builder content published', $row->text());
        self::assertCount(1, $row->filter('a[href="/admin/content-blocks/slots/homepage/WEB"]'));
    }

    public function testTheProductEditPageLinksToItsContent(): void
    {
        $product = $this->fixtures->product($this->channel(), 'MUG', 'Blue mug', 1999);
        $this->clear();

        $crawler = $this->client->request('GET', sprintf('/admin/products/%d/edit', $product->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/admin/content-blocks/slots/product/MUG',
            $crawler->filter('[data-test-content-blocks-product]')->attr('href'),
        );
    }

    public function testTheAdminAutocompleteAnswersWithCodes(): void
    {
        $this->fixtures->product($this->channel(), 'MUG', 'Blue mug', 1999);
        $this->clear();

        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $this->channel());
        $area = $page->getContentArea();
        \assert($area !== null);
        $block = $this->fixtures->block($area, 'product', ['product' => '', 'addToCart' => false]);
        $this->clear();
        $crawler = $this->client->request('GET', sprintf('/admin/content-blocks/editor/block/%d/edit', $block->getId()));
        $url = (string) $crawler->filter('select[name$="[product]"]')->attr('data-symfony--ux-autocomplete--autocomplete-url-value');

        $this->client->request('GET', $url . '&query=Blue');

        self::assertResponseIsSuccessful();
        $results = json_decode((string) $this->client->getResponse()->getContent(), true)['results'] ?? [];
        self::assertSame(['MUG'], array_column($results, 'value'));
    }

    public function testTheBuilderSidebarEditsACatalogBlock(): void
    {
        $this->fixtures->product($this->channel(), 'MUG', 'Blue mug', 1999);
        $page = $this->fixtures->page('about', ['en_US' => 'About us'], $this->channel());
        $area = $page->getContentArea();
        \assert($area !== null);
        $block = $this->fixtures->block($area, 'product', ['product' => 'MUG', 'addToCart' => false]);
        $this->clear();

        $crawler = $this->client->request('GET', sprintf('/admin/content-blocks/editor/block/%d/edit', $block->getId()));

        self::assertResponseIsSuccessful();
        $live = (string) $crawler->filter('[data-live-name-value="ContentBlocks:Block"]')->attr('data-live-url-value');
        self::assertStringStartsWith('/admin/', $live, 'Sylius apps mount no ux_live_component route.');
        $option = $crawler->filter('select[name$="[product]"] option[selected]');
        self::assertSame('MUG', $option->attr('value'));
        self::assertStringContainsString('Blue mug', $option->text());
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/admin/content-blocks/homepage');

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function channel(): \Sylius\Component\Core\Model\ChannelInterface
    {
        $channel = static::getContainer()->get('sylius.repository.channel')->findOneBy(['code' => 'WEB']);
        \assert($channel instanceof \Sylius\Component\Core\Model\ChannelInterface);

        return $channel;
    }
}
