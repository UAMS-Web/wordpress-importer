<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel as FoundationConsoleKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Minimal Laravel application for tests that use {@see \Illuminate\Support\Facades\Http}.
 *
 * The host Statamic app's {@see TestCase} is not autoloaded when this
 * package is tested with PHPUnit from its own directory; this base class boots
 * enough of the framework for {@see Http::fake()} and related client APIs.
 */
abstract class LaravelHttpTestCase extends BaseTestCase
{
    /**
     * Build a small application with the HTTP client factory registered.
     */
    public function createApplication(): Application
    {
        $basePath = dirname(__DIR__);

        $app = new Application($basePath);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->instance(
            'app',
            $app
        );
        $app->instance(
            Application::class,
            $app
        );

        $app->singleton(
            HttpKernelContract::class,
            FoundationHttpKernel::class
        );
        $app->singleton(
            ConsoleKernelContract::class,
            FoundationConsoleKernel::class
        );

        $app->singleton(
            'events',
            fn (Application $application) => new Dispatcher($application)
        );

        $app->singleton(
            HttpFactory::class,
            fn () => new HttpFactory
        );

        $app->boot();

        return $app;
    }
}
