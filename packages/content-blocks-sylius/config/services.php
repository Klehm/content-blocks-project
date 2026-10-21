<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use ContentBlocks\I18n\ContentBlocksI18nBundle;
use ContentBlocks\I18n\Locale\LocalizedPageUrlResolverInterface;
use ContentBlocks\I18n\Locale\TargetLocalesProviderInterface;
use ContentBlocks\I18n\Workbench\WorkbenchBackUrlResolverInterface;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Sylius\Block\AddToCartBlock;
use ContentBlocks\Sylius\Block\ProductBlock;
use ContentBlocks\Sylius\Block\ProductListBlock;
use ContentBlocks\Sylius\Block\ProductPriceBlock;
use ContentBlocks\Sylius\Block\TaxonListBlock;
use ContentBlocks\Sylius\Catalog\Catalog;
use ContentBlocks\Sylius\Controller\Admin\HomepageController;
use ContentBlocks\Sylius\Controller\Admin\SlotController;
use ContentBlocks\Sylius\Controller\Shop\PageController;
use ContentBlocks\Sylius\Form\Type\CatalogCodeType;
use ContentBlocks\Sylius\Form\Type\ContentPageTranslationType;
use ContentBlocks\Sylius\Form\Type\ContentPageType;
use ContentBlocks\Sylius\I18n\AdminBackUrlResolver;
use ContentBlocks\Sylius\I18n\ShopLocalizedPageUrlResolver;
use ContentBlocks\Sylius\I18n\ShopTargetLocalesProvider;
use ContentBlocks\Sylius\Kit\BootstrapKitTemplateLoader;
use ContentBlocks\Sylius\Locale\ShopLocales;
use ContentBlocks\Sylius\Menu\AdminMenuListener;
use ContentBlocks\Sylius\Owner\AreaOwnerLocator;
use ContentBlocks\Sylius\Preview\ShopContentAreaUrlResolver;
use ContentBlocks\Sylius\Replace\ShopContentAreaProvider;
use ContentBlocks\Sylius\Repository\ContentPageRepository;
use ContentBlocks\Sylius\Repository\ContentSlotRepository;
use ContentBlocks\Sylius\Routing\AdminUrls;
use ContentBlocks\Sylius\Routing\ShopUrls;
use ContentBlocks\Sylius\Security\AdminAccessChecker;
use ContentBlocks\Sylius\Security\AdminVisitor;
use ContentBlocks\Sylius\Slot\HomepageHookRenderer;
use ContentBlocks\Sylius\Slot\SlotRenderer;
use ContentBlocks\Sylius\Twig\AdminExtension;
use ContentBlocks\Sylius\Twig\CatalogExtension;
use ContentBlocks\Sylius\Twig\SlotExtension;
use Sylius\Resource\Translation\Provider\TranslationLocaleProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(ContentPageRepository::class);
    $services->set(ContentSlotRepository::class);

    $services->set(ContentPageType::class)
        ->args([
            '%content_blocks_sylius.model.content_page.class%',
            ['Default', 'sylius'],
            service(TranslationLocaleProviderInterface::class),
        ]);
    $services->set(CatalogCodeType::class);
    $services->set(ContentPageTranslationType::class)
        ->args(['%content_blocks_sylius.model.content_page_translation.class%', ['Default', 'sylius']]);

    $services->set(AdminVisitor::class)->args(['$security' => service('security.helper')]);
    $services->set(AdminAccessChecker::class);
    $services->alias(AccessCheckerInterface::class, AdminAccessChecker::class);

    $services->set(AreaOwnerLocator::class);
    $services->set(ShopUrls::class)
        ->args([
            '$products' => service('sylius.repository.product'),
            '$taxons' => service('sylius.repository.taxon'),
        ]);
    $services->set(AdminUrls::class);

    $services->set(ShopLocales::class)
        ->args([service('sylius.repository.channel'), param('locale')]);
    $services->set(ShopContentAreaUrlResolver::class);
    $services->alias(ContentAreaUrlResolverInterface::class, ShopContentAreaUrlResolver::class);

    $services->set(ShopContentAreaProvider::class);
    $services->alias(ContentAreaProviderInterface::class, ShopContentAreaProvider::class);

    $services->set(SlotRenderer::class)
        ->args(['$enabledTypes' => param('content_blocks_sylius.slots')]);
    $services->set(HomepageHookRenderer::class)
        ->decorate('sylius_twig_hooks.renderer.hook', priority: 1024)
        ->args([
            '$inner' => service('.inner'),
            '$channels' => service('sylius.context.channel'),
        ]);
    $services->set(SlotExtension::class)
        ->args([
            '$taxons' => service('sylius.repository.taxon'),
            '$locale' => service('sylius.context.locale'),
        ]);

    $services->set(Catalog::class)
        ->args([
            '$products' => service('sylius.repository.product'),
            '$taxons' => service('sylius.repository.taxon'),
            '$channels' => service('sylius.context.channel'),
            '$locale' => service('sylius.context.locale'),
            '$variants' => service('sylius.resolver.product_variant'),
        ]);
    $services->set(CatalogExtension::class);
    $services->set(AdminExtension::class);

    $services->set(ProductBlock::class);
    $services->set(ProductListBlock::class);
    $services->set(ProductPriceBlock::class);
    $services->set(AddToCartBlock::class);
    $services->set(TaxonListBlock::class);

    $services->set(BootstrapKitTemplateLoader::class)
        ->args([
            '$markup' => param('content_blocks_sylius.kit_markup'),
            '$templatesDir' => \dirname(__DIR__) . '/templates/kit/bootstrap',
            '$overridesDir' => param('twig.default_path'),
        ])
        ->tag('twig.loader', ['priority' => 100]);

    $services->set(AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main', 'method' => 'addContentMenu']);

    // Not autoconfigured, or `routing.controllers` would mount them unprefixed.
    foreach ([PageController::class, SlotController::class, HomepageController::class] as $controller) {
        $services->set($controller)
            ->autoconfigure(false)
            ->tag('controller.service_arguments');
    }
    $services->get(PageController::class)->args([
        '$channels' => service('sylius.context.channel'),
        '$locale' => service('sylius.context.locale'),
    ]);
    $services->get(HomepageController::class)->args(['$channels' => service('sylius.repository.channel')]);
    $services->get(SlotController::class)->args([
        '$products' => service('sylius.repository.product'),
        '$taxons' => service('sylius.repository.taxon'),
        '$channels' => service('sylius.repository.channel'),
    ]);

    // klehm/content-blocks-i18n is optional.
    if (\in_array(ContentBlocksI18nBundle::class, $builder->getParameter('kernel.bundles'), true)) {
        $services->set(ShopTargetLocalesProvider::class)
            ->args(['$sourceLocale' => param('content_blocks_i18n.source_locale')]);
        $services->alias(TargetLocalesProviderInterface::class, ShopTargetLocalesProvider::class);
        $services->set(ShopLocalizedPageUrlResolver::class);
        $services->alias(LocalizedPageUrlResolverInterface::class, ShopLocalizedPageUrlResolver::class);
        $services->set(AdminBackUrlResolver::class);
        $services->alias(WorkbenchBackUrlResolverInterface::class, AdminBackUrlResolver::class);
    }
};
