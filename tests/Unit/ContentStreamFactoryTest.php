<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentSource;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\EmptyContentSourceException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;

/**
 * Pins the guard that replaced six independent silent short-circuits (issue wordpress-importer-archive#662).
 *
 * Each of the six entry creators used to open its streaming pass with its own
 * variant of `if ($filePaths === []) { return []; }` — and `XmlStreamParser`
 * accepts an empty path list without complaint, so a source serving nothing
 * imported nothing and reported success. That is this repo's signature defect,
 * not a hypothetical one: a truncation that imported 103 of 1826 items, an
 * availability filter that matched nothing, an extractor that returned an empty
 * list for all 870 blocks.
 *
 * Pure PHP: the factory is a static guard, the interface is a single method, and
 * `XmlStreamParser`'s constructor neither opens nor validates the paths it is
 * given. Nothing here touches the container or the filesystem.
 *
 * @command  php vendor/bin/pest tests/Unit/ContentStreamFactoryTest.php
 */
it('refuses a source that can serve no export parts', function (): void {
    $empty = new class implements ContentSource
    {
        /**
         * Serve nothing, as an unparsed or unreachable source does.
         *
         * @return list<string> Always empty
         */
        public function getFilePaths(): array
        {
            return [];
        }
    };

    expect(fn () => ContentStreamFactory::open($empty, 'ProbeCreator::createEntries'))
        ->toThrow(EmptyContentSourceException::class);
});

it('names the caller in the refusal so the failure is attributable', function (): void {
    // The six call sites are otherwise indistinguishable in a stack trace taken
    // from inside a static factory.
    $empty = new class implements ContentSource
    {
        /**
         * Serve nothing.
         *
         * @return list<string> Always empty
         */
        public function getFilePaths(): array
        {
            return [];
        }
    };

    expect(fn () => ContentStreamFactory::open($empty, 'ProbeCreator::createEntries'))
        ->toThrow(EmptyContentSourceException::class, 'ProbeCreator::createEntries');
});

it('opens a parser spanning every part a source serves, in order', function (): void {
    $source = new class implements ContentSource
    {
        /**
         * Serve two ordered parts.
         *
         * @return list<string> The part paths
         */
        public function getFilePaths(): array
        {
            return ['/tmp/export.000.xml', '/tmp/export.001.xml'];
        }
    };

    $parser = ContentStreamFactory::open($source, 'ProbeCreator::createEntries');

    // Order is load-bearing: split exports are read front to back. The parser's
    // TYPE is guaranteed by the factory's return declaration, so asserting it
    // would assert nothing — what matters is that every part reached it intact.
    expect($parser->getFilePaths())->toBe(['/tmp/export.000.xml', '/tmp/export.001.xml']);
});

it('lets XmlParser satisfy the contract without adapting it', function (): void {
    // The WXR source and the API-backed source are the same type: wordpress-importer-archive#587 chose to
    // materialize real part files rather than synthesize nodes, so both reach the
    // creators as an XmlParser over real paths.
    // Checked through `class_implements()` rather than `toBeInstanceOf()`: the
    // type system already knows the relationship, so an instanceof assertion is
    // satisfied at analysis time and proves nothing at run time. This fails if
    // the `implements` clause is ever dropped.
    expect(\class_implements(XmlParser::class))
        ->toHaveKey(ContentSource::class);
});

it('shows what the guard prevents', function (): void {
    // Constructing the stream parser directly over no paths — the shape every
    // creator carried — is accepted without complaint and yields nothing. This is
    // the behaviour the factory refuses, asserted so the reason the guard exists
    // cannot quietly stop being true.
    $bare = new XmlStreamParser([]);

    expect($bare->getFilePaths())->toBe([])
        ->and(\iterator_to_array($bare->streamAllItems()))->toBe([]);
});

it('lets no entry creator construct the stream parser itself', function (): void {
    // ---
    // The factory in isolation is inert; adoption is the whole ticket. Every case
    // above passes with all six creators reverted to
    // `new XmlStreamParser($xmlParser->getFilePaths())`, so without this the guard
    // is certified as covered while the property that makes it matter is unpinned
    // — wordpress-importer-archive#662's exact defect, reintroducible with a fully green gate.
    //
    // Scanned over the whole directory rather than a fixed list: a seventh creator
    // that streamed would otherwise defeat the check by simply not being named.
    // ---
    $creators = \glob(__DIR__.'/../../src/Services/WordPressImport/Entries/Creators/*.php');

    // `glob()` reports failure as `false`. An unreadable creators directory is a
    // broken assumption rather than an empty result, and silently iterating
    // nothing is precisely the shape this test exists to catch.
    if ($creators === false) {
        throw new RuntimeException('The entry-creator directory could not be read.');
    }

    expect($creators)->not->toBe([]);

    $constructDirectly = [];

    foreach ($creators as $path) {
        $source = \file_get_contents($path);

        if ($source === false) {
            continue;
        }

        if (\str_contains($source, 'new XmlStreamParser(')) {
            $constructDirectly[] = \basename($path, '.php');
        }
    }

    expect($constructDirectly)->toBe([]);
});

it('routes each streaming creator through the factory', function (string $creator): void {
    // The other half: the six that stream must actually call it. Asserted per
    // creator rather than as an exact set, so a future creator adopting the
    // factory does not fail a test about these six.
    $path = __DIR__."/../../src/Services/WordPressImport/Entries/Creators/{$creator}.php";

    expect($path)->toBeReadableFile();

    $source = (string) \file_get_contents($path);

    expect($source)->toContain('ContentStreamFactory::open(');
})->with([
    'PostEntryCreator',
    'PageEntryCreator',
    'PeopleEntryCreator',
    'KbEntryCreator',
    'TablePressEntryCreator',
    'LogoListItemEntryCreator',
]);
