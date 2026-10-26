<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Answering the navigation-menu prompts from the migration API (issue wordpress-importer-archive#1145).
 *
 * A WXR export carries no `wp_options` row, so a file-sourced run had to ask the operator to
 * read the WordPress menu-locations screen. An API-sourced run can read the assignment
 * directly, and must: `--key-from-stdin` exists so such a run needs no terminal, and either
 * prompt left standing defeats it.
 *
 * The location slugs asserted here are measured rather than chosen — `uams-2020` registers
 * `primary` and `uams-2016` registers `white-bar`, read from 29 site options payloads.
 *
 * @command  php vendor/bin/pest tests/Integration/NavigationMenuApiSourceTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('nav_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * Fake the options endpoint serving an arbitrary set of `wp_options` rows.
 *
 * @param  array<string, mixed>  $options  The rows to serve
 */
function fakeNavigationOptions(array $options): void
{
    Http::fake([
        '*/options*' => Http::response([
            'options' => $options,
            'acf' => [],
            'denylist' => [],
            'denied' => ['total' => 0],
        ], 200),
    ]);
}

/**
 * A selector pointed at a faked migration API.
 */
function apiSourcedSelector(): NavigationMenuSelector
{
    $selector = new NavigationMenuSelector;
    $selector->setMigrationApiSource(new Client('https://example.test', 'shhh'), 50);

    return $selector;
}

/**
 * A parser over a WXR part carrying the given `nav_menu` terms.
 *
 * @param  string  $directory  The directory to write the part into
 * @param  array<int, array{id: int, slug: string, name: string}>  $menus  The menus to write
 */
function navigationParser(string $directory, array $menus): XmlParser
{
    $terms = '';

    // Build one wp:term element per menu, in the shape getTerms() reads.
    foreach ($menus as $menu) {
        $terms .= '<wp:term>'
            .'<wp:term_id>'.$menu['id'].'</wp:term_id>'
            .'<wp:term_taxonomy>nav_menu</wp:term_taxonomy>'
            .'<wp:term_slug>'.$menu['slug'].'</wp:term_slug>'
            .'<wp:term_name>'.$menu['name'].'</wp:term_name>'
            .'</wp:term>';
    }

    $path = $directory.\DIRECTORY_SEPARATOR.'part-1.xml';

    \file_put_contents(
        $path,
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel>'
        .'<wp:base_blog_url>https://example.test</wp:base_blog_url>'
        .$terms
        .'</channel>'
        .'</rss>'
    );

    $parser = new XmlParser;
    $parser->setFilePaths([$path]);

    // The file-sourced branch builds a menu-locations link from this; the API branch returns
    // before touching the parser at all.
    $parser->setBaseBlogUrl('https://example.test');

    return $parser;
}

it('answers no active menu when the primary location is unassigned', function (): void {
    // The measured shape of a site that has never assigned a menu: WordPress serializes the
    // empty map as an empty array rather than an empty object.
    fakeNavigationOptions([
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => []],
    ]);

    $output = new BufferedOutput;

    $answer = apiSourcedSelector()->confirmActivePrimaryNavigation(
        navigationParser($this->workingDirectory, []),
        new ArrayInput([]),
        $output,
        new Command
    );

    expect($answer)->toBeFalse()
        ->and($output->fetch())->toContain('No menu is assigned to the primary navigation position.');
});

it('answers yes and reports the location and menu id when one is assigned', function (): void {
    fakeNavigationOptions([
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => 878, 'quick-links' => 0]],
    ]);

    $output = new BufferedOutput;

    $answer = apiSourcedSelector()->confirmActivePrimaryNavigation(
        navigationParser($this->workingDirectory, []),
        new ArrayInput([]),
        $output,
        new Command
    );

    expect($answer)->toBeTrue()
        ->and($output->fetch())->toContain("location 'primary' is assigned menu 878");
});

it('treats a location mapped to zero as unassigned', function (): void {
    // A registered location with nothing in it. Measured on a real site, and the reason the
    // rule is "non-zero menu id" rather than "the key is present".
    fakeNavigationOptions([
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => 0]],
    ]);

    $answer = apiSourcedSelector()->confirmActivePrimaryNavigation(
        navigationParser($this->workingDirectory, []),
        new ArrayInput([]),
        new BufferedOutput,
        new Command
    );

    expect($answer)->toBeFalse();
});

it('reads the 2016 theme under its own location slug', function (): void {
    fakeNavigationOptions([
        'stylesheet' => 'uams-2016',
        'theme_mods_uams-2016' => ['nav_menu_locations' => ['white-bar' => 2]],
    ]);

    $output = new BufferedOutput;

    $answer = apiSourcedSelector()->confirmActivePrimaryNavigation(
        navigationParser($this->workingDirectory, []),
        new ArrayInput([]),
        $output,
        new Command
    );

    expect($answer)->toBeTrue()
        ->and($output->fetch())->toContain("location 'white-bar' is assigned menu 2");
});

it('ignores a theme_mods row belonging to a theme the site no longer runs', function (): void {
    // Measured: one site runs uams-2020 with nothing assigned while still carrying a
    // uams-2016 row that names a menu. Reading that row would answer for a dead theme.
    fakeNavigationOptions([
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => []],
        'theme_mods_uams-2016' => ['nav_menu_locations' => ['white-bar' => 2]],
    ]);

    $answer = apiSourcedSelector()->confirmActivePrimaryNavigation(
        navigationParser($this->workingDirectory, []),
        new ArrayInput([]),
        new BufferedOutput,
        new Command
    );

    expect($answer)->toBeFalse();
});

it('selects the assigned menu without prompting', function (): void {
    fakeNavigationOptions([
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => 878]],
    ]);

    $output = new BufferedOutput;

    $slug = apiSourcedSelector()->selectNavigationMenu(
        navigationParser($this->workingDirectory, [
            ['id' => 12, 'slug' => 'footer-menu', 'name' => 'Footer Menu'],
            ['id' => 878, 'slug' => 'main-menu', 'name' => 'Main Menu'],
        ]),
        new ArrayInput([]),
        $output,
        new Command
    );

    $written = $output->fetch();

    expect($slug)->toBe('main-menu')
        ->and($written)->toContain("Importing 'Main Menu' (slug: main-menu)")
        ->and($written)->not->toContain('Which navigation menu would you like to import?');
});

it('skips when the assigned menu is absent from the export', function (): void {
    fakeNavigationOptions([
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => 878]],
    ]);

    $output = new BufferedOutput;

    $slug = apiSourcedSelector()->selectNavigationMenu(
        navigationParser($this->workingDirectory, [
            ['id' => 12, 'slug' => 'footer-menu', 'name' => 'Footer Menu'],
        ]),
        new ArrayInput([]),
        $output,
        new Command
    );

    expect($slug)->toBeNull()
        ->and($output->fetch())->toContain('is not present in the export');
});

it('keeps the operator prompt and its guidance on a file-sourced run', function (): void {
    // The constraint the prompt exists for is real for WXR, so this path must not change.
    $selector = new NavigationMenuSelector;
    $selector->clearMigrationApiSource();

    $output = new BufferedOutput;

    $answer = $selector->confirmActivePrimaryNavigation(
        navigationParser($this->workingDirectory, []),
        new ArrayInput([]),
        $output,
        new Command
    );

    $written = $output->fetch();

    expect($selector->hasMigrationApiSource())->toBeFalse()
        ->and($answer)->toBeFalse()
        ->and($written)->toContain('UAMS 2016: "White Bar"')
        ->and($written)->toContain('nav-menus.php');
});
