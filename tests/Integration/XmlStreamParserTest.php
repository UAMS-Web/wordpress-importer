<?php

declare(strict_types=1);

/**
 * Tests `XmlStreamParser` item by item: which items each stream yields, how a
 * part that cannot be opened or an item that cannot be parsed is handled, and
 * when CDATA is preserved (#99). Routed to the light Statamic band because the
 * skipped-item path logs through the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/XmlStreamParserTest.php
 */

use Illuminate\Support\Facades\Log;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    // Start every test without an entry or form in the processing context
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();

    // Create an isolated scratch directory for this test's fixtures
    $this->streamDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('xml_stream_', true);
    mkdir($this->streamDirectory);
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();

    // Remove every fixture file, then the scratch directory itself
    $entries = glob($this->streamDirectory.DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        unlink($entry);
    }

    rmdir($this->streamDirectory);
});

/**
 * Write a WXR document whose channel holds the given items.
 *
 * @param  string  $directory  The scratch directory to write into
 * @param  string  $name  The file name
 * @param  string  $items  The raw `<item>` markup for the channel
 * @param  string  $prolog  Markup between the XML declaration and `<rss>`
 * @return string The written path
 */
function xmlStreamWrite(
    string $directory,
    string $name,
    string $items,
    string $prolog = ''
): string {
    // Wrap the items in a minimal WXR document
    $document = '<?xml version="1.0" encoding="UTF-8"?>'.$prolog
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
        .$items
        .'</channel></rss>';

    // Write it into the scratch directory
    $path = $directory.DIRECTORY_SEPARATOR.$name;
    file_put_contents($path, $document);

    return $path;
}

/**
 * Build one WXR item.
 *
 * @param  string  $title  The item title
 * @param  string  $type  The `wp:post_type`
 * @param  string  $status  The `wp:status`
 * @param  string  $extra  Further markup inside the item
 * @return string The item markup
 */
function xmlStreamItem(
    string $title,
    string $type = 'post',
    string $status = 'publish',
    string $extra = ''
): string {
    return '<item><title>'.$title.'</title><wp:post_type>'.$type.'</wp:post_type>'
        .'<wp:status>'.$status.'</wp:status>'.$extra.'</item>';
}

/**
 * Drain a stream into the titles it yielded, collecting any PHP warnings raised.
 *
 * @param  iterable<int, SimpleXMLElement>  $stream  The stream to drain
 * @return array{0: list<string>, 1: list<string>} The titles, then the warnings
 */
function xmlStreamTitles(
    iterable $stream
): array {
    $titles = [];
    $warnings = [];

    // Report libxml errors as PHP warnings whatever an earlier test left the
    // mode at, so the recorded warnings do not depend on test order
    $internalErrors = libxml_use_internal_errors(false);

    // Record warnings rather than letting them fail the test
    set_error_handler(
        function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        },
        E_WARNING
    );

    try {
        foreach ($stream as $item) {
            $titles[] = (string) $item->title;
        }
    } finally {
        restore_error_handler();
        libxml_use_internal_errors($internalErrors);
    }

    return [$titles, $warnings];
}

it('keeps only string paths, reindexed in order', function (): void {
    $parser = new XmlStreamParser([3 => 'first.xml', 4 => 7, 9 => 'second.xml', 10 => null]);

    expect($parser->getFilePaths())->toBe(['first.xml', 'second.xml'])
        ->and($parser->getFilePath())->toBe('first.xml');
});

it('reports an empty first path when no string path was given', function (): void {
    $parser = new XmlStreamParser([5, null]);

    expect($parser->getFilePaths())->toBe([])
        ->and($parser->getFilePath())->toBe('');
});

it('yields only items of the requested type whose status is not skipped', function (): void {
    $path = xmlStreamWrite($this->streamDirectory, 'mixed.xml', implode('', [
        xmlStreamItem('Published post'),
        xmlStreamItem('A page', 'page'),
        xmlStreamItem('Trashed post', 'post', 'trash'),
        xmlStreamItem('Virtual post', 'post', 'virtual'),
        xmlStreamItem('Draft post', 'post', 'draft'),
    ]));

    [$titles, $warnings] = xmlStreamTitles((new XmlStreamParser($path))->streamItems('post'));

    expect($titles)->toBe(['Published post', 'Draft post'])
        ->and($warnings)->toBe([]);
});

it('yields every item whose status is not skipped from the unfiltered stream', function (): void {
    $path = xmlStreamWrite($this->streamDirectory, 'mixed.xml', implode('', [
        xmlStreamItem('Published post'),
        xmlStreamItem('A page', 'page'),
        xmlStreamItem('Trashed post', 'post', 'trash'),
        xmlStreamItem('Virtual page', 'page', 'virtual'),
        xmlStreamItem('Draft attachment', 'attachment', 'draft'),
    ]));

    [$titles, $warnings] = xmlStreamTitles((new XmlStreamParser($path))->streamAllItems());

    expect($titles)->toBe(['Published post', 'A page', 'Draft attachment'])
        ->and($warnings)->toBe([]);
});

it('reads type and status from the wp namespace rather than unprefixed elements', function (): void {
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'shadowed.xml',
        '<item><title>Namespaced post</title><post_type>page</post_type><status>trash</status>'
        .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item>'
        .'<item><title>Namespaced trash</title><post_type>post</post_type><status>publish</status>'
        .'<wp:post_type>post</wp:post_type><wp:status>trash</wp:status></item>'
    );
    $parser = new XmlStreamParser($path);

    expect(xmlStreamTitles($parser->streamItems('post'))[0])->toBe(['Namespaced post'])
        ->and(xmlStreamTitles($parser->streamItems('page'))[0])->toBe([])
        ->and(xmlStreamTitles($parser->streamAllItems())[0])->toBe(['Namespaced post']);
});

it('reads unprefixed type and status from a document without the wp namespace', function (): void {
    $path = $this->streamDirectory.DIRECTORY_SEPARATOR.'plain.xml';
    file_put_contents(
        $path,
        '<?xml version="1.0"?><rss><channel>'
        .'<item><title>Plain post</title><post_type>post</post_type><status>publish</status></item>'
        .'<item><title>Plain trash</title><post_type>post</post_type><status>trash</status></item>'
        .'<item><title>Plain page</title><post_type>page</post_type><status>publish</status></item>'
        .'</channel></rss>'
    );
    $parser = new XmlStreamParser($path);

    expect(xmlStreamTitles($parser->streamItems('post'))[0])->toBe(['Plain post'])
        ->and(xmlStreamTitles($parser->streamAllItems())[0])->toBe(['Plain post', 'Plain page']);
});

it('counts only item elements, not their children or other elements', function (): void {
    // Each sibling would be yielded if any element other than <item> were read:
    // the entry even carries a matching post type
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'siblings.xml',
        '<title>Channel title</title><wp:item>Not an item</wp:item>'
        .'<wp:entry><title>Not an item either</title><wp:post_type>post</wp:post_type></wp:entry>'
        .xmlStreamItem('Only item')
    );
    $parser = new XmlStreamParser($path);

    expect(xmlStreamTitles($parser->streamAllItems())[0])->toBe(['Only item'])
        ->and(xmlStreamTitles($parser->streamItems('post'))[0])->toBe(['Only item']);
});

it('skips a part that cannot be opened and carries on with the next', function (): void {
    $missing = $this->streamDirectory.DIRECTORY_SEPARATOR.'missing.xml';
    $present = xmlStreamWrite($this->streamDirectory, 'present.xml', xmlStreamItem('After the gap'));
    $parser = new XmlStreamParser([$missing, $present]);

    [$filtered, $filteredWarnings] = xmlStreamTitles($parser->streamItems('post'));
    [$all, $allWarnings] = xmlStreamTitles($parser->streamAllItems());

    expect($filtered)->toBe(['After the gap'])
        ->and($all)->toBe(['After the gap'])
        ->and($filteredWarnings)->toBe(['XMLReader::open(): Unable to open source data'])
        ->and($allWarnings)->toBe(['XMLReader::open(): Unable to open source data']);
});

it('stops a part at a malformed item without failing either stream', function (): void {
    $spy = Log::spy();
    $broken = xmlStreamWrite(
        $this->streamDirectory,
        'broken.xml',
        '<item><title>Unclosed<b></title></item>'.xmlStreamItem('After the break')
    );
    $next = xmlStreamWrite($this->streamDirectory, 'next.xml', xmlStreamItem('Next part'));
    $parser = new XmlStreamParser([$broken, $next]);

    [$filtered, $filteredWarnings] = xmlStreamTitles($parser->streamItems('post'));
    [$all, $allWarnings] = xmlStreamTitles($parser->streamAllItems());

    // The reader cannot expand the broken item, so the rest of that part is lost
    expect($filtered)->toBe(['Next part'])
        ->and($all)->toBe(['Next part'])
        ->and($filteredWarnings)->not->toBe([])
        ->and($allWarnings)->not->toBe([]);

    $spy->shouldNotHaveReceived('warning');
});

it('drops items the reader cannot expand past a late error, without recovering any', function (): void {
    $spy = Log::spy();

    // The padding puts the error beyond the reader's first buffer, so the reader
    // reaches each item and hands back an empty string for it
    $broken = xmlStreamWrite(
        $this->streamDirectory,
        'late.xml',
        xmlStreamItem(str_repeat('p', 40000)).'<item><title>Unclosed<b></title></item>'.xmlStreamItem('After the break')
    );
    $next = xmlStreamWrite($this->streamDirectory, 'next.xml', xmlStreamItem('Next part'));
    $parser = new XmlStreamParser([$broken, $next]);

    [$filtered] = xmlStreamTitles($parser->streamItems('post'));
    [$all] = xmlStreamTitles($parser->streamAllItems());

    expect($filtered)->toBe(['Next part'])
        ->and($all)->toBe(['Next part']);

    $spy->shouldNotHaveReceived('warning');
});

it('does not recover a block-comment item that cannot be parsed', function (): void {
    $spy = Log::spy();
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'blocks.xml',
        xmlStreamItem('Blocks &e;', 'post', 'publish', '<content><![CDATA[<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->]]></content>')
        .xmlStreamItem('Next item'),
        '<!DOCTYPE rss [<!ENTITY e "x">]>'
    );
    $parser = new XmlStreamParser($path);

    [$titles] = xmlStreamTitles($parser->streamItems('post'));

    expect($titles)->toBe(['Next item'])
        ->and(fn (): array => xmlStreamTitles($parser->streamAllItems()))
        ->toThrow(Exception::class, 'String could not be parsed as XML');
    $spy->shouldHaveReceived('warning');
});

it('logs and skips an item that cannot be parsed, then yields the next', function (): void {
    $spy = Log::spy();

    // The DTD entity resolves for the reader but not for the item parsed on its own
    $longTitle = str_repeat('x', 220);
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'entity.xml',
        xmlStreamItem($longTitle.'&e;').xmlStreamItem('Parsed item'),
        '<!DOCTYPE rss [<!ENTITY e "x">]>'
    );
    $itemXml = '<item xmlns:wp="http://wordpress.org/export/1.2/"><title>'.$longTitle.'&e;</title>'
        .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item>';

    [$titles] = xmlStreamTitles((new XmlStreamParser($path))->streamItems('post'));

    expect($titles)->toBe(['Parsed item']);
    $spy->shouldHaveReceived('warning', [
        'XmlStreamParser: Skipping malformed XML item',
        [
            'error' => 'String could not be parsed as XML',
            'xml_length' => strlen($itemXml),
            'xml_preview' => substr($itemXml, 0, 200),
        ],
    ]);
});

it('names the entry being processed when it logs a skipped item', function (): void {
    $spy = Log::spy();
    ProcessingContext::setWordPressPageLink('https://example.org/a-page/');
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'entity.xml',
        xmlStreamItem('Bad &e;'),
        '<!DOCTYPE rss [<!ENTITY e "x">]>'
    );
    $itemXml = '<item xmlns:wp="http://wordpress.org/export/1.2/"><title>Bad &e;</title>'
        .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item>';

    xmlStreamTitles((new XmlStreamParser($path))->streamItems('post'));

    $spy->shouldHaveReceived('warning', [
        'XmlStreamParser: Skipping malformed XML item (from entry: https://example.org/a-page/)',
        [
            'error' => 'String could not be parsed as XML',
            'xml_length' => strlen($itemXml),
            'xml_preview' => $itemXml,
            'entry_url' => 'https://example.org/a-page/',
        ],
    ]);
});

it('warns once about a part that cannot be opened, from the stream and not the count (#182)', function (): void {
    $missing = $this->streamDirectory.DIRECTORY_SEPARATOR.'missing.xml';
    $present = xmlStreamWrite($this->streamDirectory, 'present.xml', xmlStreamItem('After the gap'));
    $parser = new XmlStreamParser([$missing, $present]);
    $warnings = [];
    $internalErrors = libxml_use_internal_errors(false);

    // Record warnings as the framework's handler sees them, which skips a silenced one
    set_error_handler(
        function (int $severity, string $message) use (&$warnings): bool {
            if ((error_reporting() & $severity) !== 0) {
                $warnings[] = $message;
            }

            return true;
        },
        E_WARNING
    );

    try {
        $count = $parser->countItems('post');
        $warningsFromCount = $warnings;
        $titles = [];

        foreach ($parser->streamItems('post') as $item) {
            $titles[] = (string) $item->title;
        }
    } finally {
        restore_error_handler();
        libxml_use_internal_errors($internalErrors);
    }

    expect($count)->toBe(1)
        ->and($warningsFromCount)->toBe([])
        ->and($titles)->toBe(['After the gap'])
        ->and($warnings)->toBe(['XMLReader::open(): Unable to open source data']);
});

it('counts what the filtered stream yields, without logging the items it skips', function (): void {
    // Only the stream logs the item it cannot parse, so one warning in all
    Log::shouldReceive('warning')->once();
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'count.xml',
        xmlStreamItem('Published')
        .xmlStreamItem('Draft', 'post', 'draft')
        .xmlStreamItem('Trashed', 'post', 'trash')
        .xmlStreamItem('A page', 'page')
        .xmlStreamItem('Bad &e;'),
        '<!DOCTYPE rss [<!ENTITY e "x">]>'
    );
    $parser = new XmlStreamParser($path);

    expect($parser->countItems('post'))->toBe(2)
        ->and($parser->countItems('page'))->toBe(1);

    [$titles] = xmlStreamTitles($parser->streamItems('post'));

    expect($titles)->toBe(['Published', 'Draft']);
});

it('ends the unfiltered stream with an exception on an item that cannot be parsed', function (): void {
    // Pins current behavior: unlike the filtered stream, this one does not catch
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'entity.xml',
        xmlStreamItem('Bad &e;').xmlStreamItem('Never reached'),
        '<!DOCTYPE rss [<!ENTITY e "x">]>'
    );

    expect(fn (): array => xmlStreamTitles((new XmlStreamParser($path))->streamAllItems()))
        ->toThrow(Exception::class, 'String could not be parsed as XML');
});

it('keeps CDATA sections only in items that carry block comments', function (): void {
    $path = xmlStreamWrite(
        $this->streamDirectory,
        'cdata.xml',
        xmlStreamItem('Blocks', 'post', 'publish', '<content><![CDATA[<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->]]></content>')
        .xmlStreamItem('Classic', 'post', 'publish', '<content><![CDATA[<p>b</p>]]></content>')
    );
    $parser = new XmlStreamParser($path);

    $filtered = iterator_to_array($parser->streamItems('post'), false);
    $all = iterator_to_array($parser->streamAllItems(), false);

    foreach ([$filtered, $all] as $items) {
        expect($items)->toHaveCount(2)
            ->and((string) $items[0]->asXML())->toContain('<![CDATA[<!-- wp:paragraph -->')
            ->and((string) $items[1]->asXML())->not->toContain('<![CDATA[')
            ->and((string) $items[1]->content)->toBe('<p>b</p>');
    }
});
