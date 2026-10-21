<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentPageTranslation;
use ContentBlocks\Sylius\Entity\ContentSlot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Resource\Factory\FactoryInterface;

/** The smallest shop the tests need, built through Sylius's own factories. */
final class ShopFixtures
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function channel(string $code = 'WEB', string $hostname = 'localhost'): ChannelInterface
    {
        $locales = [];
        foreach (['en_US', 'fr_FR'] as $localeCode) {
            $locale = $this->em->getRepository($this->modelClass('locale'))->findOneBy(['code' => $localeCode]);
            if (!$locale instanceof LocaleInterface) {
                $locale = $this->create('locale');
                \assert($locale instanceof LocaleInterface);
                $locale->setCode($localeCode);
                $this->em->persist($locale);
            }
            $locales[] = $locale;
        }

        $currency = $this->em->getRepository($this->modelClass('currency'))->findOneBy(['code' => 'USD']);
        if (!$currency instanceof CurrencyInterface) {
            $currency = $this->create('currency');
            \assert($currency instanceof CurrencyInterface);
            $currency->setCode('USD');
            $this->em->persist($currency);
        }

        $channel = $this->create('channel');
        \assert($channel instanceof ChannelInterface);
        $channel->setCode($code);
        $channel->setName('Channel ' . $code);
        $channel->setHostname($hostname);
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locales[0]);
        foreach ($locales as $locale) {
            $channel->addLocale($locale);
        }
        $this->em->persist($channel);
        $this->em->flush();

        return $channel;
    }

    public function product(
        ChannelInterface $channel,
        string $code,
        string $name,
        int $price,
        ?TaxonInterface $taxon = null,
        bool $enabled = true,
    ): ProductInterface {
        $product = $this->container->get('sylius.factory.product')->createWithVariant();
        \assert($product instanceof ProductInterface);
        $product->setCode($code);
        $product->setEnabled($enabled);
        foreach (['en_US', 'fr_FR'] as $locale) {
            $product->setCurrentLocale($locale);
            $product->setFallbackLocale($locale);
            $product->setName($locale === 'en_US' ? $name : $name . ' FR');
            $product->setSlug(strtolower($code) . ($locale === 'en_US' ? '' : '-fr'));
        }
        $product->setCurrentLocale('en_US');
        $product->addChannel($channel);

        $variant = $product->getVariants()->first();
        \assert($variant instanceof ProductVariantInterface);
        $variant->setCode($code . '_VARIANT');
        $variant->setCurrentLocale('en_US');
        $variant->setName($name);
        $variant->setTracked(false);
        $pricing = $this->create('channel_pricing');
        \assert($pricing instanceof ChannelPricingInterface);
        $pricing->setChannelCode((string) $channel->getCode());
        $pricing->setPrice($price);
        $pricing->setOriginalPrice($price);
        $variant->addChannelPricing($pricing);

        if ($taxon !== null) {
            $productTaxon = $this->create('product_taxon');
            \assert($productTaxon instanceof ProductTaxonInterface);
            $productTaxon->setProduct($product);
            $productTaxon->setTaxon($taxon);
            $product->addProductTaxon($productTaxon);
        }

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    public function taxon(string $code, string $name, ?TaxonInterface $parent = null): TaxonInterface
    {
        $taxon = $this->create('taxon');
        \assert($taxon instanceof TaxonInterface);
        $taxon->setCode($code);
        foreach (['en_US', 'fr_FR'] as $locale) {
            $taxon->setCurrentLocale($locale);
            $taxon->setFallbackLocale($locale);
            $taxon->setName($name);
            $taxon->setSlug(strtolower($code));
        }
        $taxon->setCurrentLocale('en_US');
        if ($parent !== null) {
            $parent->addChild($taxon);
        }
        $this->em->persist($taxon);
        $this->em->flush();

        return $taxon;
    }

    public function admin(): AdminUserInterface
    {
        $admin = $this->create('admin_user');
        \assert($admin instanceof AdminUserInterface);
        $admin->setEmail('admin@example.com');
        $admin->setUsername('admin');
        $admin->setPlainPassword('admin');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $admin->addRole('ROLE_ADMINISTRATION_ACCESS');
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    /** @param array<string, string> $titles locale => title */
    public function page(string $code, array $titles, ChannelInterface $channel, bool $enabled = true): ContentPage
    {
        $page = new ContentPage();
        $page->setCode($code);
        $page->setEnabled($enabled);
        $page->addChannel($channel);
        foreach ($titles as $locale => $title) {
            $translation = new ContentPageTranslation();
            $translation->setLocale($locale);
            $translation->setTitle($title);
            $translation->setSlug(strtolower(str_replace(' ', '-', $title)));
            $page->addTranslation($translation);
        }
        $page->setContentArea(new ContentArea());
        $this->em->persist($page);
        $this->em->flush();

        return $page;
    }

    public function slot(string $type, string $subject): ContentSlot
    {
        $slot = new ContentSlot($type, $subject);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    /**
     * One section with one block, published unless asked otherwise.
     *
     * @param array<string, mixed> $data
     */
    public function block(ContentArea $area, string $type, array $data, bool $publish = true): Block
    {
        $section = new Section();
        $section->setContentArea($area);
        $section->setLayout('full');
        $area->addSection($section);
        $column = new Column();
        $column->setSection($section);
        $column->setPreset('col-12');
        $section->addColumn($column);
        $block = new Block();
        $block->setColumn($column);
        $block->setType($type);
        $block->setDraftData($data);
        $column->addBlock($block);
        $this->em->persist($section);
        $this->em->flush();
        if ($publish) {
            $publisher = $this->container->get(ContentAreaPublisherInterface::class);
            \assert($publisher instanceof ContentAreaPublisherInterface);
            $publisher->publish($area);
            $this->em->flush();
        }

        return $block;
    }

    private function create(string $resource): object
    {
        $factory = $this->container->get('sylius.factory.' . $resource);
        \assert($factory instanceof FactoryInterface);

        return $factory->createNew();
    }

    /** @return class-string */
    private function modelClass(string $resource): string
    {
        $class = $this->container->getParameter('sylius.model.' . $resource . '.class');
        \assert(\is_string($class) && class_exists($class));

        return $class;
    }
}
