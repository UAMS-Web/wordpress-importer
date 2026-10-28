<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * `NavigationMenuSelector` on a file-sourced run: it lists the export's navigation menus, asks
 * whether one occupies the primary position and which to import, and links the operator to
 * the site's menu-locations screen (#100). The API-sourced run is covered by
 * `NavigationMenuApiSourceTest`.
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('nav_selector_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    app()['env'] = 'testing';

    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * A selector whose platform is chosen, rather than read from the machine.
 */
final class NavigationMenuSelectorTestSelector extends NavigationMenuSelector
{
    public function __construct(private readonly bool $prompts = false) {}

    protected function isPromptsSupported(): bool
    {
        return $this->prompts;
    }
}

/**
 * One `wp:term` element, in the shape getTerms() reads.
 */
function navigationMenuSelectorTerm(string $id, string $slug, string $name, string $taxonomy = 'nav_menu', bool $meta = false): string
{
    return '<wp:term>'
        .($id === '' ? '' : '<wp:term_id>'.$id.'</wp:term_id>')
        .'<wp:term_taxonomy>'.$taxonomy.'</wp:term_taxonomy>'
        .($slug === '' ? '' : '<wp:term_slug>'.$slug.'</wp:term_slug>')
        .'<wp:term_name><![CDATA['.$name.']]></wp:term_name>'
        .($meta ? '<wp:termmeta><wp:meta_key>k</wp:meta_key><wp:meta_value>v</wp:meta_value></wp:termmeta>' : '')
        .'</wp:term>';
}

/**
 * A parser over one WXR part carrying the given terms.
 */
function navigationMenuSelectorParser(string $directory, string $terms, string $baseBlogUrl = 'https://example.test/'): XmlParser
{
    $path = $directory.\DIRECTORY_SEPARATOR.'part-1.xml';

    \file_put_contents(
        $path,
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel>'
        .$terms
        .'</channel>'
        .'</rss>'
    );

    $parser = new XmlParser;
    $parser->setFilePaths([$path]);
    $parser->setBaseBlogUrl($baseBlogUrl);

    return $parser;
}

/**
 * Run one of the selector's questions inside a real Command::run(), which routes prompts to
 * the input's stream.
 *
 * @param  Closure(NavigationMenuSelector, ArrayInput, BufferedOutput, Command): mixed  $ask  The question
 * @param  ?string  $answers  The scripted answers, or null for a run that is not interactive
 * @param  bool  $prompts  Whether Laravel Prompts are supported
 * @param  ?array<mixed>  $keys  The keys the real Laravel Prompts answer with, or null for the fallback a test run uses
 * @param  bool  $testing  Whether the app reports it is running unit tests, which answers every question with its default
 * @return array{mixed, string} What the question answered, and the decorated output
 */
function navigationMenuSelectorRun(Closure $ask, ?string $answers, bool $prompts = false, ?array $keys = null, bool $testing = false): array
{
    // Keys come from datasets, which carry no element type
    $keys = $keys === null ? null : \array_values(\array_filter($keys, \is_string(...)));

    $input = new ArrayInput([]);

    if ($answers === null) {
        $input->setInteractive(false);
    } else {
        $stream = fopen('php://memory', 'r+');
        assert(is_resource($stream));
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
    }

    $command = new class($ask, $prompts, $keys, $testing) extends Command
    {
        protected $signature = 'test:navigation-menu-selector';

        public mixed $answer = null;

        /**
         * @param  ?list<string>  $keys
         */
        public function __construct(
            private readonly Closure $ask,
            private readonly bool $prompts,
            private readonly ?array $keys,
            private readonly bool $testing
        ) {
            parent::__construct();
        }

        public function handle(): int
        {
            $selector = new NavigationMenuSelectorTestSelector($this->prompts);
            $output = $this->output->getOutput();
            assert($output instanceof BufferedOutput);
            $input = $this->input;
            assert($input instanceof ArrayInput);

            // Only the call itself runs outside the testing environment, so the rest of the
            // run is the test run it is
            app()['env'] = $this->testing ? 'testing' : 'production';

            // Command::run() turns the fallback on, and fallbackWhen() only ever turns it on,
            // so it is turned off directly for the call and turned back on after
            $fallback = new ReflectionProperty(Prompt::class, 'shouldFallback');

            if ($this->keys !== null) {
                $fallback->setValue(null, false);
                Prompt::fake($this->keys);
            }

            try {
                $this->answer = ($this->ask)($selector, $input, $output, $this);
            } finally {
                app()['env'] = 'testing';
                $fallback->setValue(null, true);
            }

            return self::SUCCESS;
        }
    };

    $buffer = new BufferedOutput(decorated: true);
    $command->setLaravel(app());
    $command->run($input, $buffer);

    return [$command->answer, $buffer->fetch()];
}

/**
 * Ask which menu to import from the given parser.
 *
 * @return Closure(NavigationMenuSelector, ArrayInput, BufferedOutput, Command): ?string
 */
function navigationMenuSelectorChoose(XmlParser $parser): Closure
{
    return fn (NavigationMenuSelector $selector, ArrayInput $input, BufferedOutput $output, Command $command): ?string => $selector->selectNavigationMenu($parser, $input, $output, $command);
}

/**
 * Ask whether a menu occupies the primary position, for the given parser.
 *
 * @return Closure(NavigationMenuSelector, ArrayInput, BufferedOutput, Command): bool
 */
function navigationMenuSelectorConfirm(XmlParser $parser): Closure
{
    return fn (NavigationMenuSelector $selector, ArrayInput $input, BufferedOutput $output, Command $command): bool => $selector->confirmActivePrimaryNavigation($parser, $input, $output, $command);
}

/**
 * Strip the console's escape sequences from decorated output.
 */
function navigationMenuSelectorPlain(string $output): string
{
    return (string) \preg_replace('/\e\]8;;[^\e]*\e\\\\|\e\[[0-9;]*m/', '', $output);
}

it('lists the navigation menus of the export by name, and none of its other terms', function (): void {
    $parser = navigationMenuSelectorParser($this->workingDirectory, implode('', [
        navigationMenuSelectorTerm('2', 'main', '<b>Main</b> Menu'),
        navigationMenuSelectorTerm('1', 'footer', 'Footer'),
        navigationMenuSelectorTerm('3', 'news', 'News', 'category'),
        navigationMenuSelectorTerm('', 'no-id', 'No Id'),
        navigationMenuSelectorTerm('4', '', 'No Slug'),
        navigationMenuSelectorTerm('5', 'no-name', '<i></i>'),
    ]));

    [$slug, $out] = navigationMenuSelectorRun(navigationMenuSelectorChoose($parser), null);

    expect($slug)->toBe('footer')
        ->and(navigationMenuSelectorPlain($out))->toBe("\nAvailable Navigation Menus\n\n\u{2022} Footer (slug: footer)\n\u{2022} Main Menu (slug: main)\n\n");
});

it('orders the menus by their names as bytes', function (): void {
    $parser = navigationMenuSelectorParser($this->workingDirectory, implode('', [
        navigationMenuSelectorTerm('1', 'lower-b', 'b'),
        navigationMenuSelectorTerm('2', 'lower-a', 'a'),
        navigationMenuSelectorTerm('3', 'upper-a', 'A'),
    ]));

    [, $out] = navigationMenuSelectorRun(navigationMenuSelectorChoose($parser), null);

    expect(navigationMenuSelectorPlain($out))->toContain("\u{2022} A (slug: upper-a)\n\u{2022} a (slug: lower-a)\n\u{2022} b (slug: lower-b)\n");
});

it('lists a menu exported twice once, preferring the copy that carries term meta', function (bool $firstMeta, bool $secondMeta, string $kept): void {
    $parser = navigationMenuSelectorParser($this->workingDirectory, implode('', [
        navigationMenuSelectorTerm('7', 'first', 'Menu', meta: $firstMeta),
        navigationMenuSelectorTerm('7', 'second', 'Menu', meta: $secondMeta),
    ]));

    [$slug, $out] = navigationMenuSelectorRun(navigationMenuSelectorChoose($parser), null);

    expect($slug)->toBe($kept)
        ->and(substr_count(navigationMenuSelectorPlain($out), "\u{2022} "))->toBe(1);
})->with([
    'only the second has meta' => [false, true, 'second'],
    'both have meta' => [true, true, 'first'],
    'neither has meta' => [false, false, 'first'],
    'only the first has meta' => [true, false, 'first'],
]);

it('says the export has no navigation menus, and chooses none', function (): void {
    $parser = navigationMenuSelectorParser($this->workingDirectory, navigationMenuSelectorTerm('3', 'news', 'News', 'category'));

    [$slug, $out] = navigationMenuSelectorRun(navigationMenuSelectorChoose($parser), "\n");

    expect($slug)->toBeNull()
        ->and(navigationMenuSelectorPlain($out))->toBe("No navigation menus found in the WordPress export\n");
});

it('chooses the first menu without asking when the run is not interactive, or is a test run', function (?string $answers, bool $testing): void {
    $parser = navigationMenuSelectorParser($this->workingDirectory, navigationMenuSelectorTerm('1', 'footer', 'Footer').navigationMenuSelectorTerm('2', 'main', 'Main'));

    [$slug, $out] = navigationMenuSelectorRun(navigationMenuSelectorChoose($parser), $answers, testing: $testing);

    expect($slug)->toBe('footer')
        ->and($out)->not->toContain('Which navigation menu would you like to import?');
})->with([
    'not interactive' => [null, false],
    'a test run' => ["Main (main)\n", true],
]);

it('asks which menu to import, and imports the one chosen', function (bool $prompts, string $answers, ?array $keys): void {
    $parser = navigationMenuSelectorParser($this->workingDirectory, navigationMenuSelectorTerm('1', 'footer', 'Footer').navigationMenuSelectorTerm('2', 'main', 'Main'));

    [$slug, $out] = navigationMenuSelectorRun(navigationMenuSelectorChoose($parser), $answers, $prompts, $keys);

    expect($slug)->toBe('main')
        ->and($prompts ? 'asked' : navigationMenuSelectorPlain($out))->toContain($prompts ? 'asked' : 'Which navigation menu would you like to import?');
})->with([
    'Laravel Prompts' => [true, '', [Key::DOWN, Key::ENTER]],
    'its own question' => [false, "Main (main)\n", null],
]);

it('takes the first menu for an empty answer', function (bool $prompts, string $answers, ?array $keys): void {
    $parser = navigationMenuSelectorParser($this->workingDirectory, navigationMenuSelectorTerm('1', 'footer', 'Footer').navigationMenuSelectorTerm('2', 'main', 'Main'));

    expect(navigationMenuSelectorRun(navigationMenuSelectorChoose($parser), $answers, $prompts, $keys)[0])->toBe('footer');
})->with([
    'Laravel Prompts' => [true, '', [Key::ENTER]],
    'its own question' => [false, "\n", null],
]);

it('explains the primary navigation position and links to the login page that answers', function (): void {
    Http::fake(['https://example.test/uams-login/' => Http::response('', 200)]);
    $parser = navigationMenuSelectorParser($this->workingDirectory, '');

    [$active, $out] = navigationMenuSelectorRun(navigationMenuSelectorConfirm($parser), null);

    expect($active)->toBeFalse()
        ->and($out)->toBe(implode("\n", [
            '',
            "\e[32mNavigation Menu Import\e[39m",
            '',
            'Before importing navigation menu items, we need to confirm if there is an active menu in the primary navigation position.',
            '',
            'This position is labeled differently depending on the WordPress theme.',
            '  - UAMS 2016: "White Bar"',
            '  - UAMS 2020: "Primary Navigation Menu"',
            '',
            "Log in at \e]8;;https://example.test/uams-login/\e\\https://example.test/uams-login/\e]8;;\e\\.",
            '',
            "Once logged in, you can check the menu locations at \e]8;;https://example.test/wp-admin/nav-menus.php?action=locations\e\\https://example.test/wp-admin/nav-menus.php?action=locations\e]8;;\e\\.",
            '',
            '',
        ]));
});

it('links to the WordPress login, with a redirect to the menu locations, when the UAMS login does not answer', function (Closure $response): void {
    Http::fake(['https://example.test/uams-login/' => $response]);
    $parser = navigationMenuSelectorParser($this->workingDirectory, '');

    [, $out] = navigationMenuSelectorRun(navigationMenuSelectorConfirm($parser), null);

    $url = 'https://example.test/wp-login.php?redirect_to=https%3A%2F%2Fexample.test%2Fwp-admin%2Fnav-menus.php%3Faction%3Dlocations&reauth=1';

    expect($out)->toContain("You can check the menu locations at \e]8;;{$url}\e\\{$url}\e]8;;\e\\\n");
})->with([
    'a page that is not there' => [fn (): Closure => fn () => Http::response('', 404)],
    'a redirect' => [fn (): Closure => fn () => Http::response('', 302)],
    'no connection' => [fn (): Closure => fn () => throw new ConnectionException('refused')],
]);

it('links to the classifieds login on Inside UAMS, without asking whether the UAMS login answers', function (): void {
    Http::fake();
    $parser = navigationMenuSelectorParser($this->workingDirectory, '', 'https://inside.uams.edu/tri');

    [, $out] = navigationMenuSelectorRun(navigationMenuSelectorConfirm($parser), null);

    expect($out)->toContain("Log in at \e]8;;https://inside.uams.edu/tri/classifieds/\e\\https://inside.uams.edu/tri/classifieds/\e]8;;\e\\.\n\nOnce logged in, you can check the menu locations at \e]8;;https://inside.uams.edu/tri/wp-admin/nav-menus.php?action=locations\e\\https://inside.uams.edu/tri/wp-admin/nav-menus.php?action=locations\e]8;;\e\\.\n");

    Http::assertNothingSent();
});

it('answers no without asking when the run is not interactive, or is a test run', function (?string $answers, bool $testing): void {
    Http::fake(['*' => Http::response('', 404)]);
    $parser = navigationMenuSelectorParser($this->workingDirectory, '');

    [$active, $out] = navigationMenuSelectorRun(navigationMenuSelectorConfirm($parser), $answers, testing: $testing);

    expect($active)->toBeFalse()
        ->and($out)->not->toContain('Is there an active menu in the primary navigation position?');
})->with([
    'not interactive' => [null, false],
    'a test run' => ["yes\n", true],
]);

it('asks whether a menu occupies the primary position', function (bool $prompts, string $answers, ?array $keys, bool $active): void {
    Http::fake(['*' => Http::response('', 404)]);
    $parser = navigationMenuSelectorParser($this->workingDirectory, '');

    expect(navigationMenuSelectorRun(navigationMenuSelectorConfirm($parser), $answers, $prompts, $keys)[0])->toBe($active);
})->with([
    'Laravel Prompts, yes' => [true, '', ['y', Key::ENTER], true],
    'Laravel Prompts, the default' => [true, '', [Key::ENTER], false],
    'its own question, yes' => [false, "yes\n", null, true],
    'its own question, the default' => [false, "\n", null, false],
]);

it('asks the UAMS login page of the site whether it answers', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    $parser = navigationMenuSelectorParser($this->workingDirectory, '');

    navigationMenuSelectorRun(navigationMenuSelectorConfirm($parser), null);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/uams-login/');
});

/**
 * A selector reading a faked migration API that serves the given options payload.
 *
 * @param  array<mixed>  $payload  The options payload served
 */
function navigationMenuSelectorFromApi(array $payload): NavigationMenuSelector
{
    Http::fake(['*/options*' => Http::response($payload + ['acf' => [], 'denylist' => [], 'denied' => ['total' => 0]], 200)]);

    $selector = new NavigationMenuSelector;
    $selector->setMigrationApiSource(new Client('https://example.test', 'shhh'), 50);

    return $selector;
}

/**
 * Ask whether a menu occupies the primary position, with decorated output.
 *
 * @return array{bool, string} The answer, and the decorated output
 */
function navigationMenuSelectorConfirmFromApi(NavigationMenuSelector $selector, XmlParser $parser): array
{
    $output = new BufferedOutput(decorated: true);
    $answer = $selector->confirmActivePrimaryNavigation($parser, new ArrayInput([]), $output, new Command);

    return [$answer, $output->fetch()];
}

it('says whether it reads from the migration API', function (): void {
    $selector = new NavigationMenuSelector;

    expect($selector->hasMigrationApiSource())->toBeFalse();

    $selector->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    expect($selector->hasMigrationApiSource())->toBeTrue();

    $selector->clearMigrationApiSource();

    expect($selector->hasMigrationApiSource())->toBeFalse();
});

it('reports the primary navigation it resolved from the migration API', function (): void {
    $selector = navigationMenuSelectorFromApi(['options' => [
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => '878']],
    ]]);

    expect(navigationMenuSelectorConfirmFromApi($selector, navigationMenuSelectorParser($this->workingDirectory, '')))->toBe([
        true,
        "\n\e[32mNavigation Menu Import\e[39m\n\nPrimary navigation resolved from the migration API: location 'primary' is assigned menu 878.\n",
    ]);
});

it('reads the options table once for both questions, and again for a new source', function (): void {
    $selector = navigationMenuSelectorFromApi(['options' => [
        'stylesheet' => 'uams-2020',
        'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => 2]],
    ]]);
    $parser = navigationMenuSelectorParser($this->workingDirectory, navigationMenuSelectorTerm('2', 'main', 'Main'));

    navigationMenuSelectorConfirmFromApi($selector, $parser);
    $slug = $selector->selectNavigationMenu($parser, new ArrayInput([]), new BufferedOutput, new Command);

    Http::assertSentCount(1);
    expect($slug)->toBe('main');

    $selector->setMigrationApiSource(new Client('https://example.test', 'shhh'), 50);
    navigationMenuSelectorConfirmFromApi($selector, $parser);

    Http::assertSentCount(2);
});

it('answers no, and says why, where the options payload cannot settle the position', function (array $payload, string $said): void {
    [$active, $out] = navigationMenuSelectorConfirmFromApi(navigationMenuSelectorFromApi($payload), navigationMenuSelectorParser($this->workingDirectory, ''));

    expect($active)->toBeFalse()
        ->and($out)->toBe(($said === '' ? '' : "\e[33m{$said}\e[39m\n")."\n\e[32mNavigation Menu Import\e[39m\n\nNo menu is assigned to the primary navigation position.\n");
})->with([
    'no options map' => [['options' => 'none'], 'The options payload carried no options map; treating the primary navigation position as empty'],
    'no live theme' => [['options' => []], 'The options payload named no live theme; treating the primary navigation position as empty'],
    'an empty theme name' => [['options' => ['stylesheet' => '']], 'The options payload named no live theme; treating the primary navigation position as empty'],
    'a theme name that is not text' => [['options' => ['stylesheet' => 5]], 'The options payload named no live theme; treating the primary navigation position as empty'],
    'a theme with no known location' => [['options' => ['stylesheet' => 'twentytwenty']], "Theme 'twentytwenty' registers no known primary navigation location; treating the position as empty"],
    'no theme mods' => [['options' => ['stylesheet' => 'uams-2020']], ''],
    'theme mods that do not decode' => [['options' => ['stylesheet' => 'uams-2020', 'theme_mods_uams-2020' => '{not json']], ''],
    'locations that are not a map' => [['options' => ['stylesheet' => 'uams-2020', 'theme_mods_uams-2020' => ['nav_menu_locations' => 'primary']]], ''],
    'a menu id that is not a number' => [['options' => ['stylesheet' => 'uams-2020', 'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => 'main']]]], ''],
    'a menu id that only starts with a number' => [['options' => ['stylesheet' => 'uams-2020', 'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => '12abc']]]], ''],
    'an unknown theme with an unnamed location' => [['options' => ['stylesheet' => 'twentytwenty', 'theme_mods_twentytwenty' => ['nav_menu_locations' => ['' => 5]]]], "Theme 'twentytwenty' registers no known primary navigation location; treating the position as empty"],
    'a menu id of zero, as text' => [['options' => ['stylesheet' => 'uams-2020', 'theme_mods_uams-2020' => ['nav_menu_locations' => ['primary' => '0']]]], ''],
]);

it('reads theme mods served as a JSON string', function (): void {
    $selector = navigationMenuSelectorFromApi(['options' => [
        'stylesheet' => 'uams-2016',
        'theme_mods_uams-2016' => '{"nav_menu_locations":{"white-bar":12}}',
    ]]);

    [$active, $out] = navigationMenuSelectorConfirmFromApi($selector, navigationMenuSelectorParser($this->workingDirectory, ''));

    expect($active)->toBeTrue()
        ->and($out)->toContain("location 'white-bar' is assigned menu 12.");
});

it('answers no, and says why, where the options table cannot be read', function (): void {
    Http::fake(['*/options*' => Http::response(['message' => 'down'], 500)]);
    $selector = new NavigationMenuSelector;
    $selector->setMigrationApiSource(new Client('https://example.test', 'shhh'), 50);

    [$active, $out] = navigationMenuSelectorConfirmFromApi($selector, navigationMenuSelectorParser($this->workingDirectory, ''));

    expect($active)->toBeFalse()
        ->and($out)->toStartWith("\e[33mCould not read the options table to resolve the primary navigation menu: ")
        ->and($out)->toContain("\e[39m\n\n\e[32mNavigation Menu Import\e[39m\n\nNo menu is assigned to the primary navigation position.\n");
});

it('chooses no menu, without listing any, where the migration API names none', function (): void {
    $selector = navigationMenuSelectorFromApi(['options' => ['stylesheet' => 'uams-2020', 'theme_mods_uams-2020' => ['nav_menu_locations' => []]]]);
    $output = new BufferedOutput;

    $slug = $selector->selectNavigationMenu(
        navigationMenuSelectorParser($this->workingDirectory, navigationMenuSelectorTerm('2', 'main', 'Main')),
        new ArrayInput([]),
        $output,
        new Command
    );

    expect($slug)->toBeNull()
        ->and($output->fetch())->toBe('');
});

it('asks the UAMS login page with a ten second timeout', function (): void {
    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response('', 404);
    });

    navigationMenuSelectorRun(navigationMenuSelectorConfirm(navigationMenuSelectorParser($this->workingDirectory, '')), null);

    expect($timeouts)->toBe([10]);
});
