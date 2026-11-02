<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter;

use App\Http\Middleware\ProtectEntry;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Statamic\Providers\AddonServiceProvider;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\DataManager as FormidableFormsDataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\DataManager as GravityFormsDataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager as NinjaFormsDataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;

/**
 * Registers the WordPress importer command, the `protect.entry` middleware
 * alias, and container bindings.
 *
 * The addon does not attach any middleware to a route group. It exposes the
 * `protect.entry` alias for the app-owned `App\Http\Middleware\ProtectEntry`;
 * a consuming app that wants frontend entry password protection must attach
 * that middleware to its own frontend group — e.g. in `bootstrap/app.php`:
 * `$middleware->appendToGroup('statamic.web', ProtectEntry::class)`.
 */
class ServiceProvider extends AddonServiceProvider
{
    /**
     * Console commands provided by this addon.
     *
     * @var list<class-string<Command>>
     */
    // PCOV records no line for a property default, so the mutation plugin never runs this
    // item's mutant; removing it by hand fails ServiceProviderMiddlewareTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    protected $commands = [
        ImportWordPress::class,
    ];

    /**
     * Register addon services and named middleware.
     */
    public function register(): void
    {
        // Register the ProtectEntry middleware as a named middleware alias
        $this->app->make(Router::class)->aliasMiddleware(
            'protect.entry',
            ProtectEntry::class
        );

        // Register SiteManager as a singleton
        $this->app->singleton(SiteManager::class);

        // Register CollectionStructureManager as a singleton
        $this->app->singleton(CollectionStructureManager::class);

        // Register the MinervaKB settings Loader as a singleton. The import command
        // configures its source once the run's `--source` is known, and `Selector` receives
        // it by injection — without a shared binding those would be different instances and
        // the configuration would silently not apply (wordpress-importer-archive#586).
        $this->app->singleton(MinervaKbSettingsLoader::class);

        // Register the Gravity Forms sourcing seam as a singleton, for the same reason and
        // with the same hazard (wordpress-importer-archive#809). The command configures its source once `--source` is
        // known and `GravityForms\Selector` receives it by injection; two instances would
        // mean the selector reading a file while the command believed it had pointed it at
        // the API. `wordpress-importer-archive#810` registered Ninja's below and `wordpress-importer-archive#811` Formidable's — Formidable
        // differs by more than the slug, wrapping the XML reader and translating the API
        // payload, but the binding is the same shape.
        $this->app->singleton(GravityFormsLoader::class);

        // Ninja Forms gets its own singleton rather than sharing Gravity's (wordpress-importer-archive#810). One
        // binding could only carry one endpoint slug, and the command configures each
        // loader's source separately — so the plugin subclass is the name they agree on.
        $this->app->singleton(NinjaFormsLoader::class);

        // Formidable's loader wraps the Formidable-specific XML reader rather than the
        // shared JSON one; the file path is unchanged and only the API path translates (wordpress-importer-archive#811).
        $this->app->singleton(FormidableFormsLoader::class);

        // Each plugin's DataManager is a singleton so the coordinator, the user-interaction
        // handler and the form-creation coordinator — which all take it by injection —
        // receive one instance and read what the others wrote (wordpress-importer-archive#1103). Before this the
        // store was a static on BaseDataManager, which made every manager of every plugin
        // one slot: the sharing worked, invisibly, and a second plugin's load overwrote
        // the first's. The store is now per instance, so this binding is the ONLY thing
        // that makes two consumers agree, and it is scoped to one plugin. Reset between
        // imports in one process is FormPluginCoordinator::clearAllCaches().
        $this->app->singleton(GravityFormsDataManager::class);
        $this->app->singleton(FormidableFormsDataManager::class);
        $this->app->singleton(NinjaFormsDataManager::class);
    }
}
