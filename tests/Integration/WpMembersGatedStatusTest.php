<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins how a WP-Members-gated item maps its status (wordpress-importer-archive#1240).
 *
 * WP-Members stores `_wpmem_block` postmeta: `1` blocks an item for logged-out visitors, `2`
 * hides it, and `0` or no value leaves it open. `UAMS-Web/uams-statamic#2114` decided that a
 * front-end account gates only per-person content, so these pages do not migrate behind login.
 * Importing one as `published` would make it public before its owner has decided how it
 * migrates, so a published gated item imports as `private` and the import names it.
 *
 * @command  php vendor/bin/pest tests/Integration/WpMembersGatedStatusTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make('pages');
    $collection->sites(['default']);
    $collection->save();

    $blueprint = BlueprintFacade::make('pages');
    $blueprint->setNamespace('collections.pages');
    $blueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'status_and_visibility', 'field' => ['type' => 'select']],
            ['handle' => 'password_protected', 'field' => ['type' => 'toggle']],
        ],
    ]);
    $blueprint->save();

    ProcessingContext::setWordPressPageLink('https://inside.uams.edu/clinicaleducation/epiccare-link-elearning/');
});

afterEach(function (): void {
    // Blueprints are not Stache items, so they are removed by hand
    $blueprint = BlueprintFacade::find('collections.pages.pages');

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }
});

it('imports a published item WP-Members blocks as private, and names it', function (string $block): void {
    $logSpy = wpmem1240SpyLog();
    $output = new BufferedOutput;
    $data = [];

    (new WpMembers1240Probe)->map('publish', $block, $data, $output);

    expect($data['status_and_visibility'])->toBe('private')
        ->and($output->fetch())->toContain(
            'Imported as private: WP-Members gates https://inside.uams.edu/clinicaleducation/epiccare-link-elearning/ (_wpmem_block='.$block.').'
        );

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, "WP-Members gates this item (_wpmem_block='{$block}'), importing it as private rather than published")
                && \str_contains($message, 'https://inside.uams.edu/clinicaleducation/epiccare-link-elearning/')
        ),
        Mockery::on(
            static fn (mixed $context): bool => \is_array($context)
                && ($context['wpmem_block'] ?? null) === $block
        ),
    ]);
})->with([
    'blocked for logged-out visitors' => '1',
    'hidden' => '2',
]);

it('reads a padded _wpmem_block value as the value it pads', function (): void {
    $data = [];

    (new WpMembers1240Probe)->map('publish', ' 1 ', $data, new BufferedOutput);

    expect($data['status_and_visibility'])->toBe('private');
});

it('names the item generically when no page link is known', function (?string $link): void {
    ProcessingContext::clearWordPressPageLink();

    if ($link !== null) {
        ProcessingContext::setWordPressPageLink($link);
    }

    $output = new BufferedOutput;
    $data = [];

    (new WpMembers1240Probe)->map('publish', '1', $data, $output);

    expect($output->fetch())->toContain('Imported as private: WP-Members gates this item (_wpmem_block=1).');
})->with([
    'no link' => [null],
    'empty link' => [''],
]);

it('keeps a published item published where WP-Members leaves it open', function (string $block): void {
    $logSpy = wpmem1240SpyLog();
    $output = new BufferedOutput;
    $data = [];

    (new WpMembers1240Probe)->map('publish', $block, $data, $output);

    expect($data['status_and_visibility'])->toBe('published')
        ->and($output->fetch())->not->toContain('Imported as private');

    $logSpy->shouldNotHaveReceived('warning');
})->with([
    'open' => '0',
    'no value' => '',
    'unrecognized' => '3',
]);

it('keeps the mapped status of an item that is not published', function (string $status, string $expected): void {
    $output = new BufferedOutput;
    $data = [];

    (new WpMembers1240Probe)->map($status, '1', $data, $output);

    expect($data['status_and_visibility'])->toBe($expected)
        ->and($output->fetch())->not->toContain('Imported as private');
})->with([
    'draft' => ['draft', 'draft'],
    'pending' => ['pending', 'pending'],
    'private' => ['private', 'private'],
]);

it('reads _wpmem_block from a WXR item, and nothing where it is absent', function (): void {
    $gated = new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value>12</wp:meta_value></wp:postmeta>'
        .'<wp:postmeta><wp:meta_key>_wpmem_block</wp:meta_key><wp:meta_value> 2 </wp:meta_value></wp:postmeta>'
        .'</item>'
    );
    $open = new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value>12</wp:meta_value></wp:postmeta>'
        .'</item>'
    );

    expect((new WpMembers1240Probe)->read($gated))->toBe('2')
        ->and((new WpMembers1240Probe)->read($open))->toBe('');
});

it('passes each item\'s _wpmem_block wherever a creator maps status', function (): void {
    $creators = \glob(\dirname(__DIR__, 2).'/src/Services/WordPressImport/Entries/Creators/*.php');
    \assert(\is_array($creators));

    // Every call site, so a creator added later is held to the same wiring
    $calls = [];
    foreach ($creators as $path) {
        $source = (string) \file_get_contents($path);
        \preg_match_all('/->mapStatusAndVisibility\((.*?)\);/s', $source, $matches);

        foreach ($matches[1] as $arguments) {
            $calls[\basename($path)][] = $arguments;
        }
    }

    // Pages, posts, people, logo list items and TablePress tables map status today
    expect(\array_keys($calls))->toContain(
        'LogoListItemEntryCreator.php',
        'PageEntryCreator.php',
        'PeopleEntryCreator.php',
        'PostEntryCreator.php',
        'TablePressEntryCreator.php'
    );

    foreach ($calls as $file => $sites) {
        foreach ($sites as $arguments) {
            expect(\str_contains($arguments, 'wpmemBlock: $this->readWpMembersBlock('))
                ->toBeTrue("{$file} maps status without passing _wpmem_block");
        }
    }
});

/**
 * Spy on the Log facade.
 */
function wpmem1240SpyLog(): LegacyMockInterface
{
    return Log::spy();
}

/**
 * Exposes `mapStatusAndVisibility()` and `readWpMembersBlock()` so they can be driven directly.
 */
final class WpMembers1240Probe extends BaseEntryCreator
{
    /**
     * Map a WordPress status through the real `mapStatusAndVisibility()`.
     *
     * @param  string  $status  The wp:status value
     * @param  string  $wpmemBlock  The `_wpmem_block` postmeta value
     * @param  array<int|string, mixed>  &$data  The entry data array to populate
     * @param  OutputInterface  $output  Console output interface
     */
    public function map(
        string $status,
        string $wpmemBlock,
        array &$data,
        OutputInterface $output
    ): void {
        $this->mapStatusAndVisibility(
            $status,
            '',
            '2026-09-28 12:00:00',
            $data,
            'pages',
            $output,
            wpmemBlock: $wpmemBlock
        );
    }

    /**
     * Read `_wpmem_block` through the real `readWpMembersBlock()`.
     *
     * @param  SimpleXMLElement  $item  The WordPress item
     * @return string The meta value
     */
    public function read(
        SimpleXMLElement $item
    ): string {
        return $this->readWpMembersBlock($item);
    }

    /**
     * Not exercised here.
     *
     * @param  string  $site  The Statamic site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  mixed  $assetImporter  Unused
     * @param  OutputInterface  $output  Console output
     * @param  mixed  $metadataMapper  Unused
     * @param  mixed  $contentProcessor  Unused
     * @param  ?array<int|string, mixed>  $minervaKbSettings  Unused
     * @return array<int|string, mixed> Always empty
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        $assetImporter,
        OutputInterface $output,
        $metadataMapper,
        $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        return [];
    }

    /**
     * Not exercised here.
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {}
}
