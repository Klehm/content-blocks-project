<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// The kit no longer ships controllers — the upload endpoint moved to the
// main package (`ContentBlocks\Controller\UploadController`, imported by
// the main bundle's routes). Kept as a no-op so hosts importing
// `@ContentBlocksKitBundle/config/routes.php` don't break.
return static function (RoutingConfigurator $routes): void {
};
