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
use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\ServiceProvider;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\DataManager as FormidableFormsDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\DataManager as GravityFormsDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager as NinjaFormsDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;

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

it('shares one instance of each service the command and its collaborators must agree on', function (string $service): void {
    // Build an application whose router the addon can alias onto
    $app = new Application;
    $app->instance('router', new Router(new Dispatcher($app), $app));

    (new ServiceProvider($app))->register();

    // A binding that is not shared hands each consumer its own instance, so a source the
    // command configures would never reach the selector that reads it (#102)
    expect($app->isShared($service))->toBeTrue();
})->with([
    SiteManager::class,
    CollectionStructureManager::class,
    MinervaKbSettingsLoader::class,
    GravityFormsLoader::class,
    NinjaFormsLoader::class,
    FormidableFormsLoader::class,
    GravityFormsDataManager::class,
    FormidableFormsDataManager::class,
    NinjaFormsDataManager::class,
]);

it('registers the import command', function (): void {
    // Statamic registers the commands this list names, so the list is what is asserted
    $defaults = (new ReflectionClass(ServiceProvider::class))->getDefaultProperties();

    expect($defaults['commands'] ?? null)->toBe([ImportWordPress::class]);
});
