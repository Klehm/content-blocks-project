<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Unit;

use ContentBlocks\Sylius\Kit\BootstrapKitTemplateLoader;
use PHPUnit\Framework\TestCase;

final class BootstrapKitTemplateLoaderTest extends TestCase
{
    private string $templates;
    private string $host;

    protected function setUp(): void
    {
        $this->templates = \dirname(__DIR__, 2) . '/templates/kit/bootstrap';
        $this->host = sys_get_temp_dir() . '/cb-sylius-loader-' . bin2hex(random_bytes(4));
        mkdir($this->host . '/bundles/ContentBlocksKitBundle/block/alert', 0777, true);
        touch($this->host . '/bundles/ContentBlocksKitBundle/block/alert/view.html.twig');
    }

    protected function tearDown(): void
    {
        unlink($this->host . '/bundles/ContentBlocksKitBundle/block/alert/view.html.twig');
        foreach (['/bundles/ContentBlocksKitBundle/block/alert', '/bundles/ContentBlocksKitBundle/block', '/bundles/ContentBlocksKitBundle', '/bundles', ''] as $dir) {
            rmdir($this->host . $dir);
        }
    }

    public function testItServesTheBootstrapVariantOfAKitView(): void
    {
        $loader = new BootstrapKitTemplateLoader('bootstrap', $this->templates, $this->host);

        self::assertTrue($loader->exists('@ContentBlocksKit/block/button/view.html.twig'));
        self::assertStringContainsString('btn btn-', $loader->getSourceContext('@ContentBlocksKit/block/button/view.html.twig')->getCode());
    }

    public function testAHostOverrideStillWins(): void
    {
        $loader = new BootstrapKitTemplateLoader('bootstrap', $this->templates, $this->host);

        self::assertFalse($loader->exists('@ContentBlocksKit/block/alert/view.html.twig'));
    }

    public function testItStepsAsideWithoutAVariantOrWithTheKitMarkup(): void
    {
        $bootstrap = new BootstrapKitTemplateLoader('bootstrap', $this->templates, $this->host);
        $kit = new BootstrapKitTemplateLoader('kit', $this->templates, $this->host);

        self::assertFalse($bootstrap->exists('@ContentBlocksKit/block/tabs/view.html.twig'));
        self::assertFalse($bootstrap->exists('@SyliusShop/homepage/index.html.twig'));
        self::assertFalse($bootstrap->exists('@ContentBlocksKit/../../etc/passwd'));
        self::assertFalse($kit->exists('@ContentBlocksKit/block/button/view.html.twig'));
    }

    /** Every variant replaces a view the kit really has. */
    public function testEachVariantShadowsAKitView(): void
    {
        $kit = \dirname(__DIR__, 2) . '/vendor/klehm/content-blocks-kit/templates';
        foreach (glob($this->templates . '/block/*/view.html.twig') ?: [] as $variant) {
            $relative = substr($variant, \strlen($this->templates) + 1);
            self::assertFileExists($kit . '/' . $relative);
        }
    }
}
