<?php

declare(strict_types=1);

use App\Services\BardContentSanitizer;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Statamic\Entries\Entry as ConcreteEntry;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationDesignator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationMap;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\CompilesCleanly;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

// The sanitizer is the host app's; standalone, the package's stub stands in for it
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

/**
 * Tests the `ImportWordPress` command's steps after the import: sanitizing the Bard content
 * written to entry files, importing the primary navigation menu, marking the site's navigation
 * as manual, and designating the site's department organization.
 *
 * Entries, collections and global sets are real, kept off disk; the sanitizer, the navigation
 * selector and the navigation importer are recording stand-ins. The command is a subclass that
 * supplies the department crosswalk, since the recorded one ships empty, and it is built
 * without its constructor and given only what each step reads.
 *
 * @command  php vendor/bin/pest tests/Integration/ImportWordPressPostImportTest.php
 */

/**
 * The command, with a department crosswalk the test supplies.
 */
final class IwpiCommand extends ImportWordPress
{
    /**
     * The crosswalk the designation applies.
     */
    public DepartmentOrganizationMap $map;

    /**
     * {@inheritDoc}
     */
    protected function departmentOrganizationMap(): DepartmentOrganizationMap
    {
        return $this->map;
    }
}

/**
 * A sanitizer that flags raw files holding a marker, and appends one to what it sanitizes.
 */
final class IwpiSanitizer extends BardContentSanitizer
{
    /**
     * {@inheritDoc}
     */
    public function containsYamlSeparatorsInRawFile(string $fileContent): bool
    {
        return str_contains($fileContent, 'SEPARATOR');
    }

    /**
     * {@inheritDoc}
     */
    public function containsUnquotedNbspTextInRawFile(string $fileContent): bool
    {
        return str_contains($fileContent, 'NBSP');
    }

    /**
     * {@inheritDoc}
     */
    public function sanitizeRawFileContent(string $fileContent): string
    {
        return $fileContent."# sanitized\n";
    }
}

/**
 * A navigation selector that records its calls and answers as told.
 */
final class IwpiSelector extends NavigationMenuSelector
{
    /**
     * Each call, as the method name and the arguments it was given.
     *
     * @var list<array{string, XmlParser, InputInterface, OutputInterface, Command}>
     */
    public array $calls = [];

    /**
     * Whether a menu is active in the primary navigation position.
     */
    public bool $active = true;

    /**
     * The menu slug selected, or null for none.
     */
    public ?string $menu = 'primary';

    /**
     * {@inheritDoc}
     */
    public function confirmActivePrimaryNavigation(XmlParser $xmlParser, InputInterface $input, OutputInterface $output, Command $command): bool
    {
        $this->calls[] = ['confirmActivePrimaryNavigation', $xmlParser, $input, $output, $command];

        return $this->active;
    }

    /**
     * {@inheritDoc}
     */
    public function selectNavigationMenu(XmlParser $xmlParser, InputInterface $input, OutputInterface $output, Command $command): ?string
    {
        $this->calls[] = ['selectNavigationMenu', $xmlParser, $input, $output, $command];

        return $this->menu;
    }
}

/**
 * A navigation importer that records what it is asked to import.
 */
final class IwpiImporter extends NavigationMenuImporter
{
    /**
     * Each import, as the parser, the menu slug, the site and the output.
     *
     * @var list<array{XmlParser, string, string, OutputInterface}>
     */
    public array $imports = [];

    /**
     * {@inheritDoc}
     */
    public function importNavigationMenu(XmlParser $xmlParser, string $menuSlug, string $site, OutputInterface $output): void
    {
        $this->imports[] = [$xmlParser, $menuSlug, $site, $output];
    }
}

// A `continue` mutant of a `break` in this class's switches only compiles with a
// warning; fail on it whichever test file loads the class first
beforeEach(function (): void {
    CompilesCleanly::assert(ImportWordPress::class);
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);

    foreach (['pages', 'posts', 'organizations'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->save();
    }

    SiteResolver::clearSelectedSite();
});

afterEach(function (): void {
    SiteResolver::clearSelectedSite();
});

/**
 * The command, built without its constructor, given the stand-ins each step reads, an input
 * and an output, and the buffer behind that output.
 *
 * @return array{IwpiCommand, BufferedOutput}
 */
function iwpiCommand(?IwpiSelector $selector = null, ?IwpiImporter $importer = null, ?DepartmentOrganizationMap $map = null): array
{
    $command = (new ReflectionClass(IwpiCommand::class))->newInstanceWithoutConstructor();
    $set = static function (string $property, object $value) use ($command): void {
        (new ReflectionProperty(ImportWordPress::class, $property))->setValue($command, $value);
    };
    $set('bardContentSanitizer', new IwpiSanitizer);
    $set('xmlParser', new XmlParser);
    $set('navigationMenuSelector', $selector ?? new IwpiSelector);
    $set('navigationMenuImporter', $importer ?? (new ReflectionClass(IwpiImporter::class))->newInstanceWithoutConstructor());
    $command->map = $map ?? new DepartmentOrganizationMap([], []);
    $input = new ArrayInput([]);
    $buffer = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $buffer));

    return [$command, $buffer];
}

/**
 * Call one of the command's private methods, and answer its output with plain line endings.
 */
function iwpiRun(ImportWordPress $command, BufferedOutput $buffer, string $method, mixed ...$arguments): string
{
    (new ReflectionMethod(ImportWordPress::class, $method))->invoke($command, ...$arguments);

    return str_replace(PHP_EOL, "\n", $buffer->fetch());
}

/**
 * Save an entry and answer the path of its file.
 *
 * @param  array<string, mixed>  $data  The entry's data
 */
function iwpiEntry(string $collection, string $slug, array $data = []): string
{
    $entry = EntryFacade::make();

    if (! $entry instanceof ConcreteEntry) {
        throw new LogicException('Expected an entry.');
    }

    $entry->collection($collection);
    $entry->locale('default');
    $entry->slug($slug);
    $entry->data(array_merge(['title' => $slug], $data));
    $entry->save();

    $path = $entry->path();

    if (! is_string($path)) {
        throw new LogicException('Expected an entry path.');
    }

    return $path;
}

/**
 * Save the multisite settings global set for the given sites.
 *
 * @param  list<string>  $sites  The site handles
 */
function iwpiSettings(array $sites = ['default']): ConcreteGlobalSet
{
    $global = new ConcreteGlobalSet;
    $global->handle(DepartmentOrganizationDesignator::GLOBAL_HANDLE);
    $global->sites($sites);
    $global->save();

    return $global;
}

/**
 * The multisite settings' values for a site, read back.
 *
 * @return array<mixed>
 */
function iwpiSettingsFor(string $site): array
{
    $variables = GlobalSet::findByHandle(DepartmentOrganizationDesignator::GLOBAL_HANDLE)?->in($site);

    $data = $variables instanceof Variables ? $variables->data() : null;

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * Collect what is logged as an error.
 *
 * @return ArrayObject<int, array{string, array<mixed>}>
 */
function iwpiErrors(): ArrayObject
{
    /** @var ArrayObject<int, array{string, array<mixed>}> $errors */
    $errors = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($errors): void {
        if ($logged->level === 'error') {
            $errors->append([$logged->message, $logged->context]);
        }
    });

    return $errors;
}

/**
 * Save an organization entry, with or without a schema subject.
 */
function iwpiOrganization(string $slug, bool $schemaSubject = true): string
{
    $entry = EntryFacade::make();

    if (! $entry instanceof ConcreteEntry) {
        throw new LogicException('Expected an entry.');
    }

    $entry->collection('organizations');
    $entry->slug($slug);
    $entry->data($schemaSubject ? ['title' => $slug, 'schema_subject' => 'Thing'] : ['title' => $slug]);
    $entry->save();

    $id = $entry->id();

    if (! is_string($id)) {
        throw new LogicException('Expected an entry id.');
    }

    return $id;
}

it('sanitizes each entry file that needs it, across every collection', function (): void {
    $separator = iwpiEntry('pages', 'separator', ['note' => 'SEPARATOR']);
    $nbsp = iwpiEntry('posts', 'nbsp', ['note' => 'NBSP']);
    $clean = iwpiEntry('pages', 'clean');
    $cleanBefore = (string) file_get_contents($clean);
    [$command, $buffer] = iwpiCommand();

    $output = iwpiRun($command, $buffer, 'runBardContentSanitization');

    expect($output)->toBe("Sanitizing Bard content in entries...\n  Sanitized 2 entries.\n")
        ->and((string) file_get_contents($separator))->toEndWith("# sanitized\n")
        ->and((string) file_get_contents($nbsp))->toEndWith("# sanitized\n")
        ->and((string) file_get_contents($clean))->toBe($cleanBefore);
});

it('says how many entries it sanitized, in the singular for one', function (): void {
    iwpiEntry('pages', 'separator', ['note' => 'SEPARATOR']);
    [$command, $buffer] = iwpiCommand();

    expect(iwpiRun($command, $buffer, 'runBardContentSanitization'))->toBe("Sanitizing Bard content in entries...\n  Sanitized 1 entry.\n");
});

it('skips an entry whose file is gone, and goes on to the next', function (): void {
    $gone = iwpiEntry('pages', 'aaa-gone', ['note' => 'SEPARATOR']);
    unlink($gone);
    $after = iwpiEntry('pages', 'zzz-after', ['note' => 'SEPARATOR']);
    [$command, $buffer] = iwpiCommand();

    expect(iwpiRun($command, $buffer, 'runBardContentSanitization'))->toBe("Sanitizing Bard content in entries...\n  Sanitized 1 entry.\n")
        ->and(file_exists($gone))->toBeFalse()
        ->and((string) file_get_contents($after))->toEndWith("# sanitized\n");
});

it('says so when no entry needed sanitizing, skipping one whose file is gone', function (): void {
    $gone = iwpiEntry('pages', 'gone', ['note' => 'SEPARATOR']);
    unlink($gone);
    iwpiEntry('pages', 'clean');
    [$command, $buffer] = iwpiCommand();

    expect(iwpiRun($command, $buffer, 'runBardContentSanitization'))->toBe("Sanitizing Bard content in entries...\n  No Bard content required sanitization.\n")
        ->and(file_exists($gone))->toBeFalse();
});

it('skips the navigation menu when no menu is active in the primary position', function (): void {
    iwpiSettings();
    $selector = new IwpiSelector;
    $selector->active = false;
    $importer = (new ReflectionClass(IwpiImporter::class))->newInstanceWithoutConstructor();
    [$command, $buffer] = iwpiCommand($selector, $importer);

    expect(iwpiRun($command, $buffer, 'handleNavigationMenuImport'))->toBe("Skipping navigation menu import - no active menu in primary navigation position\n")
        ->and(array_column($selector->calls, 0))->toBe(['confirmActivePrimaryNavigation'])
        ->and($importer->imports)->toBe([])
        ->and(iwpiSettingsFor('default'))->not->toHaveKey('manual_primary_navigation');
});

it('marks the navigation manual, then skips the import when no menu is selected', function (): void {
    iwpiSettings();
    $selector = new IwpiSelector;
    $selector->menu = null;
    $importer = (new ReflectionClass(IwpiImporter::class))->newInstanceWithoutConstructor();
    [$command, $buffer] = iwpiCommand($selector, $importer);

    expect(iwpiRun($command, $buffer, 'handleNavigationMenuImport'))->toBe(
        "Set manual_primary_navigation to true for site 'default'\n"
        ."No menu selected - skipping navigation menu import\n"
    )
        ->and(array_column($selector->calls, 0))->toBe(['confirmActivePrimaryNavigation', 'selectNavigationMenu'])
        ->and($importer->imports)->toBe([])
        ->and(iwpiSettingsFor('default'))->toBe(['manual_primary_navigation' => true]);
});

it('imports the selected menu into the selected site', function (): void {
    iwpiSettings(['default', 'other']);
    SiteResolver::setSelectedSite('other');
    $selector = new IwpiSelector;
    $importer = (new ReflectionClass(IwpiImporter::class))->newInstanceWithoutConstructor();
    [$command, $buffer] = iwpiCommand($selector, $importer);
    $parser = (new ReflectionProperty(ImportWordPress::class, 'xmlParser'))->getValue($command);
    $output = (new ReflectionProperty(Command::class, 'output'))->getValue($command);
    $input = (new ReflectionProperty(Command::class, 'input'))->getValue($command);

    expect(iwpiRun($command, $buffer, 'handleNavigationMenuImport'))->toBe("Set manual_primary_navigation to true for site 'other'\n")
        ->and($selector->calls)->toBe([
            ['confirmActivePrimaryNavigation', $parser, $input, $output, $command],
            ['selectNavigationMenu', $parser, $input, $output, $command],
        ])
        ->and($importer->imports)->toBe([[$parser, 'primary', 'other', $output]])
        ->and(iwpiSettingsFor('other'))->toBe(['manual_primary_navigation' => true]);
});

it('warns, and changes nothing, when the multisite settings cannot take the manual navigation', function (bool $exists, string $warning): void {
    if ($exists) {
        iwpiSettings(['default']);
    }

    [$command, $buffer] = iwpiCommand();

    expect(iwpiRun($command, $buffer, 'setManualPrimaryNavigation', 'other'))->toBe($warning."\n")
        ->and(iwpiSettingsFor('default'))->not->toHaveKey('manual_primary_navigation');
})->with([
    'missing' => [false, 'uams_multisite_settings Global not found - skipping manual_primary_navigation setting'],
    'not on the site' => [true, "uams_multisite_settings Global not available for site 'other' - skipping manual_primary_navigation setting"],
]);

it('warns and logs when saving the manual navigation fails', function (): void {
    iwpiSettings();
    $errors = iwpiErrors();
    Event::listen(GlobalVariablesSaving::class, static function (): never {
        throw new RuntimeException('disk full');
    });
    [$command, $buffer] = iwpiCommand();

    expect(iwpiRun($command, $buffer, 'setManualPrimaryNavigation', 'default'))->toBe("Failed to set manual_primary_navigation for site 'default': disk full\n")
        ->and($errors->getArrayCopy())->toBe([['ImportWordPress::setManualPrimaryNavigation failed', ['site' => 'default', 'error' => 'disk full']]]);
});

it('reads the department crosswalk recorded in the package', function (): void {
    $command = (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();

    expect((new ReflectionMethod(ImportWordPress::class, 'departmentOrganizationMap'))->invoke($command))
        ->toEqual(DepartmentOrganizationMap::recorded());
});

it('reports each outcome of designating the site\'s department organization', function (Closure $setUp, string $output): void {
    $map = $setUp();
    [$command, $buffer] = iwpiCommand(null, null, $map instanceof DepartmentOrganizationMap ? $map : null);

    expect(iwpiRun($command, $buffer, 'designateDepartmentOrganization', 'default'))->toBe($output);
})->with([
    'designated' => [
        static function (): DepartmentOrganizationMap {
            iwpiSettings();
            iwpiOrganization('college-of-medicine');

            return new DepartmentOrganizationMap(['default' => 'college-of-medicine'], []);
        },
        "Designated site 'default' as a news hub for 'college-of-medicine'.\n",
    ],
    'designated, with no schema subject' => [
        static function (): DepartmentOrganizationMap {
            iwpiSettings();
            iwpiOrganization('college-of-medicine', false);

            return new DepartmentOrganizationMap(['default' => 'college-of-medicine'], []);
        },
        "Designated site 'default' as a news hub for 'college-of-medicine'.\n"
        ."Organization 'college-of-medicine' has no schema_subject - 'multisite:audit-department-hubs' will report site 'default' until UAMS-Web/uams-statamic#197 authors it.\n",
    ],
    'already designated, with no schema subject' => [
        static function (): DepartmentOrganizationMap {
            $global = iwpiSettings();
            $variables = $global->in('default');
            assert($variables instanceof Variables);
            $variables->set('department_organization', iwpiOrganization('college-of-medicine', false));
            $variables->save();

            return new DepartmentOrganizationMap(['default' => 'college-of-medicine'], []);
        },
        "Site 'default' is already designated as a news hub for 'college-of-medicine'.\n"
        ."Organization 'college-of-medicine' has no schema_subject - 'multisite:audit-department-hubs' will report site 'default' until UAMS-Web/uams-statamic#197 authors it.\n",
    ],
    'deliberately unmapped' => [
        static fn (): DepartmentOrganizationMap => new DepartmentOrganizationMap([], ['default']),
        '',
    ],
    'not recorded' => [
        static fn (): DepartmentOrganizationMap => new DepartmentOrganizationMap([], []),
        "No department_organization designation is recorded for site 'default' - its news page will render as a plain listing.\n",
    ],
    'a dangling reference, with no schema subject asked about' => [
        static fn (): DepartmentOrganizationMap => new DepartmentOrganizationMap(['default' => 'gone'], []),
        "Site 'default' is designated for organization 'gone', which does not exist - designation not applied.\n",
    ],
    'no settings, with no schema subject' => [
        static function (): DepartmentOrganizationMap {
            iwpiOrganization('college-of-medicine', false);

            return new DepartmentOrganizationMap(['default' => 'college-of-medicine'], []);
        },
        "uams_multisite_settings Global not found - skipping department_organization designation\n",
    ],
    'settings elsewhere' => [
        static function (): DepartmentOrganizationMap {
            iwpiSettings(['other']);
            iwpiOrganization('college-of-medicine');

            return new DepartmentOrganizationMap(['default' => 'college-of-medicine'], []);
        },
        "uams_multisite_settings Global not available for site 'default' - skipping department_organization designation\n",
    ],
]);

it('warns and logs when designating the department organization fails', function (): void {
    iwpiSettings();
    iwpiOrganization('college-of-medicine');
    $errors = iwpiErrors();
    Event::listen(GlobalVariablesSaving::class, static function (): never {
        throw new RuntimeException('disk full');
    });
    [$command, $buffer] = iwpiCommand(null, null, new DepartmentOrganizationMap(['default' => 'college-of-medicine'], []));

    expect(iwpiRun($command, $buffer, 'designateDepartmentOrganization', 'default'))->toBe("Failed to set department_organization for site 'default': disk full\n")
        ->and($errors->getArrayCopy())->toBe([['ImportWordPress::designateDepartmentOrganization failed', ['site' => 'default', 'error' => 'disk full']]]);
});
