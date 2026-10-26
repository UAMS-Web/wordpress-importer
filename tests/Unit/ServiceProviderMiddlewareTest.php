<?php

declare(strict_types=1);

/**
 * Regression test for wordpress-importer-archive#327.
 *
 * The addon exposes the `protect.entry` middleware alias for consumers, but it
 * must not attach any middleware to a route group itself. The former
 * `$middlewareGroups = ['statamic.web' => [ProtectEntry::class]]` property read
 * as though the addon wired up frontend password protection when it never did;
 * attaching the app-owned `ProtectEntry` is the consuming app's responsibility.
 *
 * @command  php vendor/bin/pest tests/Unit/ServiceProviderMiddlewareTest.php
 */

use App\Http\Middleware\ProtectEntry;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use UAMSWeb\WordPressImporter\ServiceProvider;

it('aliases the protect.entry middleware to ProtectEntry on register', function (): void {
    // Build an application whose router the addon can alias onto
    $app = new Application;
    $router = new Router(new Dispatcher($app), $app);
    $app->instance('router', $router);

    // Register the addon's services and named middleware
    (new ServiceProvider($app))->register();

    // protect.entry is registered, aliased to ProtectEntry's class-string
    expect($router->getMiddleware()['protect.entry'] ?? null)->toBe(ProtectEntry::class);
});

it('attaches no middleware group', function (): void {
    // Read the middlewareGroups default the parent's bootMiddleware() iterates
    $defaults = (new ReflectionClass(ServiceProvider::class))->getDefaultProperties();

    // The addon declares no groups, so bootMiddleware() pushes nothing
    expect($defaults['middlewareGroups'] ?? null)->toBe([]);
});
