<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

/*
 * FrankenPHP worker entry point for the sandbox.
 *
 * The kernel is booted once and then answers every request from the same
 * process, which is the whole point of worker mode — and the reason the
 * packages carry a `CrossRequestStateTest`: a service that caches something
 * request-scoped here serves it to the next visitor rather than to nobody.
 *
 * Run it with:
 *
 *     frankenphp php-server -l 127.0.0.1:8005 -r public/ \
 *         --worker /absolute/path/to/frankenphp-worker.php
 *
 * Ports 8001–8004 belong to the Playwright fixtures and the other sandboxes;
 * 8005 is free for this.
 *
 * Written out by hand rather than pulled from `runtime/frankenphp-symfony` so
 * the sandbox gains no dependency for a file this short — and so what happens
 * between two requests is visible on the page instead of behind a package.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$handled = 0;

$handler = static function () use ($kernel, &$handled): void {
    // Superglobals are repopulated by FrankenPHP before each call, so the
    // Request is rebuilt per request while everything behind it is reused.
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);

    // How many requests this process has already answered. Without it there is
    // no way to tell worker mode from classic mode by looking at a response —
    // and a smoke test that silently ran in classic mode proves nothing about
    // state surviving between requests, which is the only thing it tests.
    $response->headers->set('X-CB-Worker-Requests', (string) ++$handled);

    $response->send();

    // terminate() is what runs `services_resetter`, and therefore what clears
    // every service tagged `kernel.reset`. Dropping it is the single easiest
    // way to turn a correct worker into a leaking one.
    $kernel->terminate($request, $response);
};

// MAX_REQUESTS=n exits after n requests — how the smoke check below stops the
// worker without signalling it.
$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);

while ($maxRequests === 0 || $handled < $maxRequests) {
    if (!\frankenphp_handle_request($handler)) {
        break;
    }

    gc_collect_cycles();
}
