<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Controller;

use ContentBlocks\Media\Controller\AssetController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AssetControllerTest extends TestCase
{
    public function testItServesThePickerScriptAndStylesheet(): void
    {
        $controller = new AssetController();

        $js = $controller->serve(new Request(), 'js');
        $css = $controller->serve(new Request(), 'css');

        $this->assertStringContainsString('cb:media:browse', (string) $js->getContent());
        $this->assertStringStartsWith('text/javascript', (string) $js->headers->get('Content-Type'));
        $this->assertStringContainsString('.cb-media', (string) $css->getContent());
        $this->assertStringStartsWith('text/css', (string) $css->headers->get('Content-Type'));
    }

    public function testTheVersionFollowsTheContent(): void
    {
        $this->assertNotSame(AssetController::version('js'), AssetController::version('css'));
        $this->assertSame(12, \strlen(AssetController::version('js')));
    }
}
