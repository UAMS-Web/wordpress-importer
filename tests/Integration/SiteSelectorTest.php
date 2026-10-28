<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Statamic\Facades\Site;
use Statamic\Yaml\ParseException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\SiteSelector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * `SiteSelector` lists the Statamic sites and those in `resources/sites.yaml`, asks which one
 * to import into, and accepts only a site it listed (#100).
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    // A base path of its own, so resources/sites.yaml is this test's file and no other's
    $this->originalBasePath = app()->basePath();
    $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('site-selector-', true);
    mkdir($this->basePath.DIRECTORY_SEPARATOR.'resources', 0777, true);
    app()->setBasePath($this->basePath);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'research' => ['name' => 'Research', 'locale' => 'en_US', 'url' => '/research/'],
    ]);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);

    $sites = $this->basePath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'sites.yaml';

    if (file_exists($sites)) {
        unlink($sites);
    }

    rmdir($this->basePath.DIRECTORY_SEPARATOR.'resources');
    rmdir($this->basePath);
});

/**
 * Write this test's resources/sites.yaml.
 */
function siteSelectorYaml(string $yaml): void
{
    file_put_contents(resource_path('sites.yaml'), $yaml);
}

/**
 * Run select() inside a real Command::run(), which routes its prompt to the input's stream.
 *
 * @param  ?string  $answers  The scripted answers, or null for a run that is not interactive
 * @return array{?string, string, ?Throwable} The selected handle, the run's output, and what select() threw
 */
function siteSelectorRun(?string $answers = null): array
{
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

    $command = new class extends Command
    {
        protected $signature = 'test:site-selector';

        public ?string $selected = null;

        public ?Throwable $thrown = null;

        public function handle(): int
        {
            try {
                $this->selected = (new SiteSelector)->select($this->input, $this->output, $this);
            } catch (Throwable $throwable) {
                $this->thrown = $throwable;
            }

            return self::SUCCESS;
        }
    };

    $buffer = new BufferedOutput;
    $command->setLaravel(app());
    $command->run($input, $buffer);

    return [$command->selected, $buffer->fetch(), $command->thrown];
}

it('lists each site with its name, dotted out to one column', function (): void {
    [, $out] = siteSelectorRun();

    expect($out)->toContain("Available sites in resources/sites.yaml:\n\n")
        ->and($out)->toContain("  [SITE HANDLE] ... [SITE NAME]\n")
        ->and($out)->toContain("  default ..... Default\n")
        ->and($out)->toContain("  research .... Research\n");
});

it('takes the default site when the run is not interactive', function (): void {
    expect(siteSelectorRun()[0])->toBe('default');
});

it('adds the sites of resources/sites.yaml, named from it or by their handle', function (): void {
    siteSelectorYaml(<<<'YAML'
        archive:
          name: Archive
        nursing:
          url: /nursing/
        blank:
          name: '   '
        notconfig: just a string
        7:
          name: Numbered
        YAML);

    [, $out] = siteSelectorRun();

    // notconfig, the longest handle, sets the column the names start in
    expect($out)->toContain("  archive ...... Archive\n")
        ->and($out)->toContain("  nursing ...... nursing\n")
        ->and($out)->toContain("  blank ........ blank\n")
        ->and($out)->toContain("  notconfig .... notconfig\n")
        ->and($out)->not->toContain('Numbered')
        ->and((new SiteSelector)->validate('nursing'))->toBeTrue()
        ->and((new SiteSelector)->validate('7'))->toBeFalse();
});

it('lists the sites in handle order, a handle in both places once', function (): void {
    siteSelectorYaml("research:\n  name: Research\naardvark:\n  name: Aardvark\n");

    [, $out] = siteSelectorRun();

    expect(substr_count($out, ' Research'))->toBe(1)
        ->and(strpos($out, 'aardvark'))->toBeLessThan((int) strpos($out, 'default'))
        ->and(strpos($out, 'default'))->toBeLessThan((int) strpos($out, 'research'));
});

it('ignores a sites.yaml that holds a list rather than a map of sites', function (): void {
    siteSelectorYaml("- archive\n- nursing\n");

    [, $out] = siteSelectorRun();

    expect($out)->toContain("  default ..... Default\n")
        ->and($out)->not->toContain('archive');
});

it('stops with the parse error when sites.yaml does not parse', function (): void {
    siteSelectorYaml("just a string\n");

    [$selected, , $thrown] = siteSelectorRun();

    expect($selected)->toBeNull()
        ->and($thrown)->toBeInstanceOf(ParseException::class);
});

it('validates a handle against the sites it lists', function (string $handle, bool $valid): void {
    expect((new SiteSelector)->validate($handle))->toBe($valid);
})->with([
    'a Statamic site' => ['research', true],
    'the default site' => ['default', true],
    'no site' => ['nowhere', false],
    'a site in another case' => ['Research', false],
]);

it('asks which site to import into, and returns the one chosen', function (): void {
    [$selected, $out] = siteSelectorRun("research\n");

    expect($selected)->toBe('research')
        ->and($out)->toContain('Which site would you like to import the content into?');
});

it('takes the default site for an empty answer', function (): void {
    expect(siteSelectorRun("\n")[0])->toBe('default');
});

it('trims the answer before checking it', function (): void {
    expect(siteSelectorRun("  research  \n")[0])->toBe('research');
});

it('says which sites exist when the answer names none, and asks again', function (): void {
    [$selected, $out] = siteSelectorRun("nowhere\nresearch\n");

    expect($selected)->toBe('research')
        ->and($out)->toContain("Site handle 'nowhere' does not exist. Available sites: default, research");
});

it('frames the list with a blank line above and below', function (): void {
    [, $out] = siteSelectorRun();

    expect($out)->toStartWith("\nAvailable sites in resources/sites.yaml:\n\n")
        ->and($out)->toContain("  research .... Research\n\n");
});

it('pads the header and each name to the column 5 past the longest handle', function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'communityhealth' => ['name' => 'Community Health', 'locale' => 'en_US', 'url' => '/ch/'],
        'rs' => ['name' => 'A name far longer than any handle', 'locale' => 'en_US', 'url' => '/rs/'],
    ]);

    [, $out] = siteSelectorRun();

    // communityhealth is 15 characters, so names start 20 in and the header gets 6 dots
    expect($out)->toContain("  [SITE HANDLE] ...... [SITE NAME]\n")
        ->and($out)->toContain("  communityhealth .... Community Health\n")
        ->and($out)->toContain("  default ............ Default\n")
        ->and($out)->toContain("  rs ................. A name far longer than any handle\n");
});

it('lists two sites that share a name, and accepts both', function (): void {
    siteSelectorYaml("annex:\n  name: Research\n");

    [, $out] = siteSelectorRun();

    expect($out)->toContain("  annex ....... Research\n")
        ->and($out)->toContain("  research .... Research\n")
        ->and((new SiteSelector)->validate('annex'))->toBeTrue()
        ->and((new SiteSelector)->validate('research'))->toBeTrue();
});

it('compares a handle that reads as a number as the string it is', function (): void {
    siteSelectorYaml("'1e3':\n  name: Numeric\n");

    expect((new SiteSelector)->validate('1e3'))->toBeTrue()
        ->and((new SiteSelector)->validate('1000'))->toBeFalse();
});

it('warns when no site is configured, and finds none to import into', function (): void {
    Site::setSites([]);

    [$selected, $out, $thrown] = siteSelectorRun();

    expect($out)->toContain('No sites found in configuration.')
        ->and($out)->not->toContain('Available sites in resources/sites.yaml:')
        ->and($selected)->toBeNull()
        ->and($thrown?->getMessage())->toBe('Unable to resolve a Statamic site handle.');
});

it('defaults to the first sites.yaml handle when Statamic has no site', function (): void {
    Site::setSites([]);
    siteSelectorYaml("nursing:\n  name: Nursing\narchive:\n  name: Archive\n");

    expect(siteSelectorRun()[0])->toBe('archive');
});

it('rejects an answer naming no site when only sites.yaml lists sites', function (): void {
    Site::setSites([]);
    siteSelectorYaml("archive:\n  name: Archive\n");

    [$selected, $out] = siteSelectorRun("nowhere\narchive\n");

    expect($selected)->toBe('archive')
        ->and($out)->toContain("Site handle 'nowhere' does not exist. Available sites: archive");
});

it('says a handle is required for a blank answer with no default, and that no site exists', function (): void {
    Site::setSites([]);

    // No site anywhere, so there is no default and no answer the validator accepts; the
    // run ends when the scripted answers do
    [$selected, $out, $thrown] = siteSelectorRun("\nnowhere\n");

    expect($selected)->toBeNull()
        ->and($thrown)->not->toBeNull()
        ->and($out)->toContain('A site handle is required.')
        ->and($out)->toContain("Site handle 'nowhere' does not exist. No sites found in configuration.");
});

it('defaults to the site Statamic marks as its default, not the first handle', function (): void {
    Site::setSites([
        'main' => ['name' => 'Main', 'locale' => 'en_US', 'url' => '/'],
        'archive' => ['name' => 'Archive', 'locale' => 'en_US', 'url' => '/archive/'],
    ]);

    expect(siteSelectorRun()[0])->toBe('main');
});

it('leaves out a Statamic site with a blank name, and lists the sites after it', function (): void {
    Site::setSites([
        'unnamed' => ['name' => '', 'locale' => 'en_US', 'url' => '/u/'],
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'research' => ['name' => 'Research', 'locale' => 'en_US', 'url' => '/research/'],
    ]);

    [, $out] = siteSelectorRun();

    expect($out)->not->toContain('unnamed')
        ->and($out)->toContain("  default ..... Default\n")
        ->and($out)->toContain("  research .... Research\n");
});

it('skips a sites.yaml entry with no usable handle, and reads the entries after it', function (): void {
    siteSelectorYaml(<<<'YAML'
        7:
          name: Numbered
        '':
          name: Unnamed
        archive:
          name: Archive
        YAML);

    [, $out] = siteSelectorRun();

    expect($out)->toContain("  archive ..... Archive\n")
        ->and($out)->not->toContain('Numbered')
        ->and($out)->not->toContain('Unnamed');
});

it('names a sites.yaml site by its handle when its name is not text, and reads a number as text', function (): void {
    siteSelectorYaml("listed:\n  name: [a, b]\nnumbered:\n  name: 42\n");

    [, $out] = siteSelectorRun();

    expect($out)->toContain("  listed ...... listed\n")
        ->and($out)->toContain("  numbered .... 42\n");
});
