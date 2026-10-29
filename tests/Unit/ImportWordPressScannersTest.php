<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * Tests the `ImportWordPress` command's content scanners: the scans that name the pages
 * holding mixed Classic and Gutenberg content, Details blocks, Freeform blocks, or knowledge
 * base articles without a topic, the Gutenberg block removal the Classic scan rests on, and the
 * checks for TablePress content and for an ACF block.
 *
 * Each scan reads the items of a WXR export through the command's parser, so each case builds
 * its own export. Nothing else of the command is touched, so it is built without its
 * constructor and given only the parser, and its private methods are called through reflection.
 *
 * @command  php vendor/bin/pest tests/Unit/ImportWordPressScannersTest.php
 */

/**
 * The command, built without its constructor and reading the given items through its parser.
 *
 * Each item is an array of the fields an export item carries, any of them left out:
 * `title`, `link`, `id` (the `wp:post_id`), `content` (the `content:encoded`), and
 * `topics`, the `kbtopic` category slugs.
 *
 * @param  list<array<string, mixed>>  $items  The items, all of the type `page` and `kb`
 */
function iwsCommand(array $items = []): ImportWordPress
{
    $xml = '';

    foreach ($items as $item) {
        $topics = '';

        foreach (is_array($item['topics'] ?? null) ? $item['topics'] : [] as $topic) {
            $topics .= '<category domain="kbtopic" nicename="'.(is_string($topic) ? $topic : '').'"><![CDATA[Topic]]></category>';
        }

        $field = static fn (string $key, string $element): string => is_string($item[$key] ?? null)
            ? "<{$element}><![CDATA[{$item[$key]}]]></{$element}>"
            : '';

        $xml .= '<item>'.$field('title', 'title').$field('link', 'link').$field('id', 'wp:post_id').$field('content', 'content:encoded')
            .'<wp:post_type><![CDATA['.(is_string($item['type'] ?? null) ? $item['type'] : 'page').']]></wp:post_type>'
            .'<wp:status><![CDATA[publish]]></wp:status>'
            .'<category domain="category" nicename="news"><![CDATA[News]]></category>'.$topics.'</item>';
    }

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_iws_');
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel>'.$xml.'</channel></rss>');

    $parser = new XmlParser;
    $parser->parse($path);
    unlink($path);

    $command = (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(ImportWordPress::class, 'xmlParser'))->setValue($command, $parser);

    return $command;
}

/**
 * Call one of the command's private methods.
 */
function iwsCall(ImportWordPress $command, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(ImportWordPress::class, $method))->invoke($command, ...$arguments);
}

/**
 * Content mixing a Gutenberg block with Classic text.
 */
function iwsMixed(): string
{
    return '<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->Classic text';
}

it('names each page that mixes Gutenberg blocks with Classic content', function (): void {
    $command = iwsCommand([
        ['title' => 'R&amp;D &quot;Lab&quot; &apos;East&apos;', 'link' => 'https://old.test/rd/', 'id' => '7', 'content' => iwsMixed()],
        ['title' => '', 'link' => 'https://old.test/untitled/', 'id' => '8', 'content' => iwsMixed()],
        ['title' => 'Blocks only', 'id' => '9', 'content' => '<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->'],
        ['title' => 'Classic only', 'id' => '10', 'content' => '<p>Classic text</p>'],
        ['title' => 'Empty', 'id' => '11', 'content' => ''],
        ['title' => 'No content', 'id' => '12'],
    ]);

    expect(iwsCall($command, 'scanForClassicBlocks', 'page'))->toBe([
        ['title' => 'R&D "Lab" \'East\'', 'id' => '7', 'link' => 'https://old.test/rd/'],
        ['title' => 'Untitled', 'id' => '8', 'link' => 'https://old.test/untitled/'],
    ]);
});

it('names nothing for content types whose content is not Bard', function (string $type, string $method): void {
    $command = iwsCommand([[
        'type' => $type,
        'title' => 'Page',
        'id' => '7',
        'content' => '<!-- wp:details --><!-- wp:freeform -->'.iwsMixed(),
    ]]);

    expect(iwsCall($command, $method, $type))->toBe([]);
})->with(['tablepress_table', 'uamspeople'])->with(['scanForClassicBlocks', 'scanForDetailsBlocks', 'scanForFreeformBlocks']);

it('names each page holding a Details block', function (): void {
    $command = iwsCommand([
        ['title' => 'Q&amp;A', 'link' => 'https://old.test/qa/', 'id' => '7', 'content' => '<!--wp:details --><details>x</details><!-- /wp:details -->'],
        ['title' => '', 'id' => '8', 'content' => '<!-- wp:details {"showContent":true} -->x<!-- /wp:details -->'],
        ['title' => 'Detailsish', 'id' => '9', 'content' => '<!-- wp:detailsblock -->x'],
        ['title' => 'Mentions it', 'id' => '10', 'content' => 'wp:details'],
        ['title' => 'Empty', 'id' => '11', 'content' => ''],
    ]);

    expect(iwsCall($command, 'scanForDetailsBlocks', 'page'))->toBe([
        ['title' => 'Q&A', 'id' => '7', 'link' => 'https://old.test/qa/'],
        ['title' => 'Untitled', 'id' => '8', 'link' => ''],
    ]);
});

it('names each page holding a Freeform block', function (): void {
    $command = iwsCommand([
        ['title' => 'A&amp;B', 'link' => 'https://old.test/ab/', 'id' => '7', 'content' => '<!--wp:freeform -->x<!-- /wp:freeform -->'],
        ['title' => '', 'id' => '8', 'content' => '<!-- wp:freeform {"x":1} -->x'],
        ['title' => 'Freeformish', 'id' => '9', 'content' => '<!-- wp:freeforms -->x'],
        ['title' => 'Mentions it', 'id' => '10', 'content' => 'wp:freeform'],
        ['title' => 'Empty', 'id' => '11', 'content' => ''],
    ]);

    expect(iwsCall($command, 'scanForFreeformBlocks', 'page'))->toBe([
        ['title' => 'A&B', 'id' => '7', 'link' => 'https://old.test/ab/'],
        ['title' => 'Untitled', 'id' => '8', 'link' => ''],
    ]);
});

it('names each knowledge base article without a topic', function (): void {
    $command = iwsCommand([
        ['type' => 'kb', 'title' => 'How &amp; why', 'link' => 'https://old.test/kb/how/', 'id' => '7'],
        ['type' => 'kb', 'title' => '', 'id' => '8'],
        ['type' => 'kb', 'title' => 'Filed', 'id' => '9', 'topics' => ['billing']],
    ]);

    expect(iwsCall($command, 'scanForKbWithoutTopics', 'kb'))->toBe([
        ['title' => 'How & why', 'id' => '7', 'link' => 'https://old.test/kb/how/'],
        ['title' => 'Untitled', 'id' => '8', 'link' => ''],
    ]);
});

it('finds TablePress content in a shortcode or a block, in any item', function (string $content, bool $found): void {
    $command = iwsCommand([
        ['title' => 'First', 'id' => '7', 'content' => '<p>No table</p>'],
        ['title' => 'Second', 'id' => '8', 'content' => $content],
    ]);

    expect(iwsCall($command, 'hasTablePressContent', 'page'))->toBe($found);
})->with([
    'a shortcode' => ['[table id=3 /]', true],
    'a shortcode in capitals, spaced' => ['[TABLE  ID=3 /]', true],
    'an encoded shortcode' => ['&#91;table id=3 /&#93;', true],
    'a shortcode encoded by name' => ['&lsqb;table id=3 /&rsqb;', true],
    'a block' => ['<!-- wp:tablepress/table {"id":"3"} /-->', true],
    'a block written tightly' => ['<!--wp:tablepress/table /-->', true],
    'a lookalike block' => ['<!-- wp:tablepress/tables /-->', false],
    'a shortcode with no id' => ['[table name=3 /]', false],
    'nothing' => ['', false],
]);

it('finds no TablePress content in tables themselves', function (): void {
    $command = iwsCommand([['type' => 'tablepress_table', 'title' => 'Table', 'id' => '7', 'content' => '[table id=3 /]']]);

    expect(iwsCall($command, 'hasTablePressContent', 'tablepress_table'))->toBeFalse();
});

it('finds an ACF block by name, in any item', function (string $content, bool $found): void {
    $command = iwsCommand([
        ['title' => 'First', 'id' => '7', 'content' => '<p>No block</p>'],
        ['title' => 'Second', 'id' => '8', 'content' => $content],
    ]);

    expect(iwsCall($command, 'hasAcfBlockType', 'page', 'logo-list'))->toBe($found);
})->with([
    'the block' => ['<!-- wp:acf/logo-list {"id":"b"} /-->', true],
    'written tightly' => ['<!--wp:acf/logo-list {"id":"b"} /-->', true],
    'another block' => ['<!-- wp:acf/fad-providers {"id":"b"} /-->', false],
    'a longer name' => ['<!-- wp:acf/logo-list-items {"id":"b"} /-->', false],
    'nothing after the name' => ['<!-- wp:acf/logo-list', false],
]);

it('says whether content holds anything outside its Gutenberg blocks', function (string $content, bool $freeform): void {
    expect(iwsCall(iwsCommand(), 'hasFreeformContentOutsideBlocks', $content))->toBe($freeform);
})->with([
    'blocks and whitespace' => ["  <!-- wp:spacer /-->\n\t<!-- wp:p -->a<!-- /wp:p -->  ", false],
    'text after a block' => ['<!-- wp:p -->a<!-- /wp:p -->tail', true],
    'nothing' => ['', false],
]);

it('removes every Gutenberg block, nested or not, and the comments left over', function (string $content, string $remaining): void {
    expect(iwsCall(iwsCommand(), 'removeAllGutenbergBlocks', $content))->toBe($remaining);
})->with([
    'no blocks' => ['<p>Classic</p>', '<p>Classic</p>'],
    'a self-closing block' => ['a<!-- wp:spacer {"h":"1"} /-->b', 'ab'],
    'a block with its content' => ['a<!-- wp:p --><p>x</p><!-- /wp:p -->b', 'ab'],
    'two blocks apart' => ['a<!-- wp:p -->x<!-- /wp:p -->b<!-- wp:p -->y<!-- /wp:p -->c', 'abc'],
    'a block spanning lines' => ["a<!-- wp:p -->\nx\n<!-- /wp:p -->b", 'ab'],
    'an opening comment left unmatched' => ['a<!-- wp:p -->b', 'ab'],
    'a closing comment left unmatched' => ['a<!-- /wp:p -->b', 'ab'],
    'a comment the tag pattern cannot read' => ['a<!-- wp:html {"x":"<br>"} -->b<!-- /wp:html -->c', 'abc'],
    'text before an unreadable self-closing comment' => ['Classic text<!-- wp:image {"alt":"<b>"} /-->', 'Classic text'],
    'text before a nested block' => ['head<!-- wp:group --><!-- wp:p -->a<!-- /wp:p --><!-- /wp:group -->', 'head'],
]);

/**
 * Today's behavior, which #274 records as a defect. A block that holds or overlaps a later one
 * is cut by the length measured on the original content, after the later block is gone, so the
 * cut runs past its end and takes what follows with it. And the pattern that clears self-closing
 * comments the tag pass cannot read runs back to an unclosed opening comment, taking the text
 * between.
 */
it('removes the text after a nested or overlapping block with it', function (string $content, string $remaining): void {
    expect(iwsCall(iwsCommand(), 'removeAllGutenbergBlocks', $content))->toBe($remaining);
})->with([
    'a block inside' => ['<!-- wp:group --><!-- wp:p -->a<!-- /wp:p --><!-- /wp:group -->TAIL TEXT', ''],
    'a self-closing block inside' => ['<!-- wp:group --><!-- wp:spacer /--><!-- /wp:group -->TAIL TEXT', ''],
    'blocks closed out of order' => ['a<!-- wp:x --><!-- wp:y -->m<!-- /wp:x -->b<!-- /wp:y -->c', 'a'],
    'text before an unreadable self-closing comment, after an unclosed one' => ['<!-- wp:p -->Classic text<!-- wp:image {"alt":"<b>"} /-->', ''],
]);

it('finds where each Gutenberg block starts and how long it runs', function (string $content, array $positions): void {
    expect(iwsCall(iwsCommand(), 'findGutenbergBlockPositions', $content))->toBe($positions);
})->with([
    'none' => ['<p>x</p>', []],
    'a self-closing block' => ['ab<!-- wp:spacer /-->', [['start' => 2, 'length' => 19, 'type' => 'self-closing']]],
    'a self-closing block written tightly' => ['<!--wp:spacer/-->', [['start' => 0, 'length' => 17, 'type' => 'self-closing']]],
    'a block with attributes' => [
        'a<!-- wp:heading {"level":2} -->T<!-- /wp:heading -->',
        [['start' => 1, 'length' => 52, 'type' => 'opening-closing']],
    ],
    'nested blocks, inner first' => [
        '<!-- wp:g --><!-- wp:p -->a<!-- /wp:p --><!-- /wp:g -->',
        [['start' => 13, 'length' => 28, 'type' => 'opening-closing'], ['start' => 0, 'length' => 55, 'type' => 'opening-closing']],
    ],
    'the nearest opening of the same type' => [
        '<!-- wp:p -->a<!-- wp:p -->b<!-- /wp:p -->',
        [['start' => 14, 'length' => 28, 'type' => 'opening-closing'], ['start' => 0, 'length' => 0, 'type' => 'unmatched-opening']],
    ],
    'a closing tag with no opening' => ['x<!-- /wp:p -->', []],
    'an opening tag left open, after a closed one' => [
        '<!-- wp:a -->x<!-- /wp:a --><!-- wp:b -->',
        [['start' => 0, 'length' => 28, 'type' => 'opening-closing'], ['start' => 28, 'length' => 0, 'type' => 'unmatched-opening']],
    ],
    'a closing tag past an open one of another type' => [
        '<!-- wp:a --><!-- wp:b -->x<!-- /wp:a -->',
        [['start' => 0, 'length' => 41, 'type' => 'opening-closing'], ['start' => 13, 'length' => 0, 'type' => 'unmatched-opening']],
    ],
]);
