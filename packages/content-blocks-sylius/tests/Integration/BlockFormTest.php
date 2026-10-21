<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use ContentBlocks\BlockType\BlockTypeRegistry;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;

/** The catalog blocks keep codes in their data, whatever the form shows. */
final class BlockFormTest extends IntegrationTestCase
{
    public function testTheCatalogBlocksAreRegisteredInTheShopGroup(): void
    {
        $registry = static::getContainer()->get(BlockTypeRegistry::class);
        \assert($registry instanceof BlockTypeRegistry);

        foreach (['product', 'product_list', 'product_price', 'add_to_cart', 'taxon_list'] as $type) {
            self::assertTrue($registry->has($type), $type);
            self::assertSame('commerce', $registry->getCategory($type), $type);
        }
    }

    public function testAProductIsStoredByCode(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->product($channel, 'MUG', 'Blue mug', 1999);
        $form = $this->form('product', ['product' => '', 'addToCart' => false]);

        $form->submit(['product' => 'MUG', 'addToCart' => '1']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(['product' => 'MUG', 'addToCart' => true], $form->getData());
    }

    public function testStoredCodesComeBackAsChoices(): void
    {
        $channel = $this->fixtures->channel();
        $this->fixtures->product($channel, 'A', 'Product A', 100);
        $this->fixtures->product($channel, 'B', 'Product B', 100);
        $data = ['source' => 'manual', 'products' => ['B', 'A'], 'taxon' => '', 'limit' => 4, 'layout' => 'grid', 'columns' => 4];

        $view = $this->form('product_list', $data)->createView();

        self::assertSame(['B', 'A'], $view['products']->vars['value']);
    }

    public function testAnUnknownCodeIsRefused(): void
    {
        $form = $this->form('product', ['product' => '', 'addToCart' => false]);

        $form->submit(['product' => 'NOPE', 'addToCart' => '0']);

        self::assertFalse($form->get('product')->isValid());
    }

    public function testTheFieldSearchesTheAdminAutocomplete(): void
    {
        $view = $this->form('taxon_list', ['source' => 'children', 'parent' => '', 'taxons' => [], 'columns' => 4])->createView();

        $url = (string) ($view['parent']->vars['attr']['data-symfony--ux-autocomplete--autocomplete-url-value'] ?? '');
        self::assertStringStartsWith('/admin/ajax/autocomplete/sylius_admin_taxon?extra_options=', $url);
    }

    public function testAnOutOfRangeChoiceIsRefused(): void
    {
        $form = $this->form('product_list', ['source' => 'latest', 'products' => [], 'taxon' => '', 'limit' => 4, 'layout' => 'grid', 'columns' => 4]);

        $form->submit(['source' => 'latest', 'limit' => '99', 'layout' => 'masonry', 'columns' => '4']);

        self::assertFalse($form->isValid());
    }

    /** @param array<string, mixed> $data */
    private function form(string $type, array $data): \Symfony\Component\Form\FormInterface
    {
        $registry = static::getContainer()->get(BlockTypeRegistry::class);
        \assert($registry instanceof BlockTypeRegistry);
        $factory = static::getContainer()->get('form.factory');
        \assert($factory instanceof FormFactoryInterface);

        $builder = $factory->createNamedBuilder('block', FormType::class, $data, ['csrf_protection' => false]);
        $registry->get($type)->buildForm($builder, $data);

        return $builder->getForm();
    }
}
