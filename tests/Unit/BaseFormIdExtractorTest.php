<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseFormIdExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * The shared extraction in `BaseFormIdExtractor`, driven through a minimal subclass so the
 * patterns are the test's own: block and shortcode IDs merge into one de-duplicated list,
 * a shortcode's ID is its first capture group, a pattern that fails to run yields nothing,
 * and `getFormIdsUsedWithPageSlugs()` keeps every page a form appears on.
 *
 * @command  php vendor/bin/pest tests/Unit/BaseFormIdExtractorTest.php
 */

/**
 * A form ID extractor whose block and shortcode patterns are given to it.
 */
final class BaseFormIdExtractorProbe extends BaseFormIdExtractor
{
    /**
     * Create the probe.
     *
     * @param  string  $blockPattern  The block pattern, capturing the block's JSON
     * @param  string  $shortcodePattern  The shortcode pattern, capturing the ID first
     */
    public function __construct(
        private string $blockPattern = '/<!--\s*wp:probe\s+({.*?})\s*\/?-->/',
        private string $shortcodePattern = '/\[probe(?:\s+id="(\d+)")?\]/'
    ) {
        parent::__construct('Probe');
    }

    /**
     * {@inheritDoc}
     */
    protected function getBlockPattern(): string
    {
        return $this->blockPattern;
    }

    /**
     * {@inheritDoc}
     */
    protected function getShortcodePattern(): string
    {
        return $this->shortcodePattern;
    }

    /**
     * {@inheritDoc}
     */
    protected function getFormIdAttribute(): string
    {
        return 'formId';
    }
}

/**
 * Run a callback, turning any PHP warning or notice it raises into a failure.
 *
 * @template T
 *
 * @param  callable(): T  $callback  The code under test
 * @return T What the callback returned
 */
function baseFormIdExtractorStrict(callable $callback): mixed
{
    \set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        return $callback();
    } finally {
        \restore_error_handler();
    }
}

/**
 * Build a parser whose pages are the given slug and content pairs.
 *
 * @param  list<array{string, string}>  $pages  Each page's slug and content
 * @return XmlParser The parser
 */
function baseFormIdExtractorParser(array $pages): XmlParser
{
    $items = [];

    foreach ($pages as [$slug, $content]) {
        $items[] = new SimpleXMLElement(
            '<item xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">'
            .'<wp:post_name>'.\htmlspecialchars($slug).'</wp:post_name>'
            .'<content:encoded>'.\htmlspecialchars($content).'</content:encoded></item>'
        );
    }

    $parser = new XmlParser;
    $parser->setItems($items);

    return $parser;
}

it('merges block and shortcode IDs into one list without duplicates', function (): void {
    // A repeat ahead of a new ID leaves a gap in the keys, which the list must close
    $content = '<!-- wp:probe {"formId":3} /--> <!-- wp:probe {"formId":3} /--> [probe id="4"] [probe id="3"]'
        .' <!-- wp:probe {"formId":"4"} /-->';

    $ids = baseFormIdExtractorStrict(fn (): array => (new BaseFormIdExtractorProbe)->extractFormIds($content));

    // Uniqueness compares as strings, so a block's 3 and a shortcode's '3' are one form
    expect($ids)->toBe([3, '4']);
});

it('takes a shortcode ID from its first capture group, and none when that group is unset', function (): void {
    $ids = baseFormIdExtractorStrict(
        fn (): array => (new BaseFormIdExtractorProbe)->extractFormIds('[probe] [probe id="12"]')
    );

    expect($ids)->toBe(['12']);
});

it('passes over a block whose JSON does not decode or lacks a usable form ID', function (): void {
    $content = '<!-- wp:probe {"other":1} /--> <!-- wp:probe {"formId":[1]} /--> <!-- wp:probe {5} /-->'
        .' <!-- wp:probe {"formId":7} /-->';

    $ids = baseFormIdExtractorStrict(fn (): array => (new BaseFormIdExtractorProbe)->extractFormIds($content));

    expect($ids)->toBe([7]);
});

it('yields no IDs, and reads no matches, when a pattern fails to run', function (): void {
    $warnings = [];
    \set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $ids = (new BaseFormIdExtractorProbe('/(/', '/(/'))->extractFormIds('[probe id="1"]');
    } finally {
        \restore_error_handler();
    }

    // One compilation warning per pattern, and nothing from reading a failed match
    expect($ids)->toBe([])
        ->and($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('preg_match_all')
        ->and($warnings[1])->toContain('preg_match_all');
});

it('lists every page a form is used on, once each, keeping slugs that only compare equal loosely', function (): void {
    $parser = baseFormIdExtractorParser([
        ['contact', '[probe id="1"]'],
        ['1', '[probe id="1"] [probe id="2"]'],
        ['01', '[probe id="1"]'],
        ['contact', '[probe id="1"]'],
        ['', '[probe id="3"]'],
    ]);

    $used = baseFormIdExtractorStrict(
        fn (): array => (new BaseFormIdExtractorProbe)->getFormIdsUsedWithPageSlugs($parser)
    );

    expect($used)->toBe([
        1 => ['contact', '1', '01'],
        2 => ['1'],
        3 => [],
    ]);
});
