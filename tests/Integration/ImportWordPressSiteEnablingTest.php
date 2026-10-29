<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Statamic\Events\GlobalSetSaved;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests the `ImportWordPress` command's site enabling: whether a collection or a global set is
 * enabled for the site being imported into, enabling each, and enabling a taxonomy's destination
 * with a warning when it does not exist.
 *
 * Collections and destinations go through the command's site manager, a recording stand-in
 * here; global sets are real, kept off disk. Nothing else of the command is touched, so it is
 * built without its constructor and given the site manager and an output.
 *
 * @command  php vendor/bin/pest tests/Integration/ImportWordPressSiteEnablingTest.php
 */

/**
 * A site manager that records the calls made on it and answers as told.
 */
final class IwseSiteManager extends SiteManager
{
    /**
     * Each call, as the method name and its arguments.
     *
     * @var list<array{string, string, string, ?OutputInterface}>
     */
    public array $calls = [];

    /**
     * What each check and each enabling answers.
     */
    public bool $answer = true;

    /**
     * {@inheritDoc}
     */
    public function isCollectionEnabledForSite(string $site, string $collectionHandle): bool
    {
        $this->calls[] = ['isCollectionEnabledForSite', $site, $collectionHandle, null];

        return $this->answer;
    }

    /**
     * {@inheritDoc}
     */
    public function enableCollectionForSite(string $site, string $collectionHandle, ?OutputInterface $output = null): bool
    {
        $this->calls[] = ['enableCollectionForSite', $site, $collectionHandle, $output];

        return $this->answer;
    }

    /**
     * {@inheritDoc}
     */
    public function enableDestinationForSite(string $site, string $handle, ?OutputInterface $output = null): bool
    {
        $this->calls[] = ['enableDestinationForSite', $site, $handle, $output];

        return $this->answer;
    }
}

beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);
});

/**
 * The command, built without its constructor, with the given site manager and an output, and
 * the buffer behind that output.
 *
 * @return array{ImportWordPress, BufferedOutput, OutputStyle}
 */
function iwseCommand(SiteManager $siteManager): array
{
    $command = (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(ImportWordPress::class, 'siteManager'))->setValue($command, $siteManager);
    $buffer = new BufferedOutput;
    $output = new OutputStyle(new ArrayInput([]), $buffer);
    $command->setOutput($output);

    return [$command, $buffer, $output];
}

/**
 * Call one of the command's private methods.
 */
function iwseCall(ImportWordPress $command, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(ImportWordPress::class, $method))->invoke($command, ...$arguments);
}

/**
 * Save a global set localized for the given sites, and answer it.
 *
 * @param  list<string>  $sites  The site handles
 */
function iwseGlobal(string $handle, array $sites): ConcreteGlobalSet
{
    $global = new ConcreteGlobalSet;
    $global->handle($handle);
    $global->title(ucfirst($handle));
    $global->sites($sites);
    $global->save();

    return $global;
}

/**
 * The sites a saved global set is localized for, read back.
 *
 * @return list<string>
 */
function iwseSites(string $handle): array
{
    $global = GlobalSet::findByHandle($handle);
    $sites = $global instanceof ConcreteGlobalSet ? $global->sites() : null;

    return $sites instanceof Collection ? array_values(array_filter($sites->all(), 'is_string')) : [];
}

it('asks the site manager whether a collection is enabled for a site', function (bool $answer): void {
    $siteManager = new IwseSiteManager;
    $siteManager->answer = $answer;
    [$command] = iwseCommand($siteManager);

    expect(iwseCall($command, 'isCollectionEnabledForSite', 'other', 'pages'))->toBe($answer)
        ->and($siteManager->calls)->toBe([['isCollectionEnabledForSite', 'other', 'pages', null]]);
})->with([true, false]);

it('has the site manager enable a collection for a site, reporting to the command\'s output', function (): void {
    $siteManager = new IwseSiteManager;
    [$command, , $output] = iwseCommand($siteManager);

    iwseCall($command, 'enableCollectionForSite', 'other', 'pages');

    expect($siteManager->calls)->toBe([['enableCollectionForSite', 'other', 'pages', $output]]);
});

it('says a global set is enabled for a site only when it is localized there', function (string $sites, bool $enabled): void {
    $sites = explode(',', $sites);
    iwseGlobal('redirects', $sites);
    [$command] = iwseCommand(new IwseSiteManager);

    expect(iwseCall($command, 'isGlobalEnabledForSite', 'other', 'redirects'))->toBe($enabled);
})->with([
    'localized there' => ['default,other', true],
    'localized elsewhere' => ['default', false],
]);

it('says a global set that does not exist is enabled for no site', function (): void {
    [$command] = iwseCommand(new IwseSiteManager);

    expect(iwseCall($command, 'isGlobalEnabledForSite', 'default', 'redirects'))->toBeFalse();
});

it('enables a global set for a site by adding the site to it and saving it', function (): void {
    iwseGlobal('redirects', ['default']);
    [$command] = iwseCommand(new IwseSiteManager);
    $saved = 0;
    Event::listen(GlobalSetSaved::class, function () use (&$saved): void {
        $saved++;
    });

    iwseCall($command, 'enableGlobalForSite', 'other', 'redirects');

    $global = GlobalSet::findByHandle('redirects');

    expect($saved)->toBe(1)
        ->and(iwseSites('redirects'))->toBe(['default', 'other'])
        ->and($global?->in('other'))->not->toBeNull();
});

it('leaves a global set already enabled for the site unsaved', function (): void {
    iwseGlobal('redirects', ['default', 'other']);
    [$command] = iwseCommand(new IwseSiteManager);
    $saved = 0;
    Event::listen(GlobalSetSaved::class, function () use (&$saved): void {
        $saved++;
    });

    iwseCall($command, 'enableGlobalForSite', 'other', 'redirects');

    expect($saved)->toBe(0)
        ->and(iwseSites('redirects'))->toBe(['default', 'other']);
});

it('enables no global set that does not exist', function (): void {
    [$command] = iwseCommand(new IwseSiteManager);

    iwseCall($command, 'enableGlobalForSite', 'other', 'redirects');

    expect(GlobalSet::findByHandle('redirects'))->toBeNull();
});

it('enables a taxonomy\'s destination for a site, saying nothing when it exists', function (): void {
    $siteManager = new IwseSiteManager;
    [$command, $buffer, $output] = iwseCommand($siteManager);

    expect(iwseCall($command, 'enableTaxonomyIfNeeded', 'other', 'people_groups', 'uamspeople'))->toBeTrue()
        ->and($siteManager->calls)->toBe([['enableDestinationForSite', 'other', 'people_groups', $output]])
        ->and($buffer->fetch())->toBe('');
});

it('warns, and answers false, when a taxonomy\'s destination exists in neither shape', function (): void {
    $siteManager = new IwseSiteManager;
    $siteManager->answer = false;
    [$command, $buffer] = iwseCommand($siteManager);

    expect(iwseCall($command, 'enableTaxonomyIfNeeded', 'other', 'people_groups', 'uamspeople'))->toBeFalse()
        ->and($buffer->fetch())->toBe(
            "'people_groups' does not exist in Statamic as a taxonomy or a collection.".PHP_EOL
            ."Please create and configure 'people_groups' before importing 'uamspeople' content.".PHP_EOL
        );
});
