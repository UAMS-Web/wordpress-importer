<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;

/**
 * Tests the `ImportWordPress` command's mappings and its pure helpers: the content type and
 * taxonomy mappings and the lookup over them, the memory-limit conversions, and the filesize
 * measurement and report.
 *
 * None of these touches the command's injected collaborators, so the command is built without
 * its constructor, and the private helpers are called through reflection.
 *
 * @command  php vendor/bin/pest tests/Unit/ImportWordPressHelpersTest.php
 */

/**
 * The command, built without its constructor.
 */
function iwhCommand(): ImportWordPress
{
    return (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();
}

/**
 * Call one of the command's private helpers.
 */
function iwhCall(ImportWordPress $command, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(ImportWordPress::class, $method))->invoke($command, ...$arguments);
}

/**
 * Give the command an output it can write to, and answer the buffer behind it.
 */
function iwhOutput(ImportWordPress $command): BufferedOutput
{
    $buffer = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return $buffer;
}

it('imports pages, posts, people, redirects, knowledge bases, logo items, classifieds and menus', function (): void {
    expect((new ReflectionProperty(ImportWordPress::class, 'supportedContentTypes'))->getDefaultValue())->toBe([
        'page',
        'post',
        'uamspeople',
        'tshowcase',
        'redirect_rule',
        'kb',
        'logo-list-item',
        'advert',
        'nav_menu_item',
    ]);
});

it('maps each content type it imports to its destination', function (): void {
    expect(ImportWordPress::getDefaultMappings())->toBe([
        'page' => ['type' => 'collection', 'handle' => 'pages', 'name' => 'Pages'],
        'post' => ['type' => 'collection', 'handle' => 'posts', 'name' => 'Posts'],
        'uamspeople' => ['type' => 'collection', 'handle' => 'people', 'name' => 'People'],
        'tshowcase' => ['type' => 'collection', 'handle' => 'people', 'name' => 'People'],
        'redirect_rule' => ['type' => 'global', 'handle' => 'redirects', 'name' => 'Redirects'],
        'tablepress_table' => ['type' => 'collection', 'handle' => 'tables', 'name' => 'Tables'],
        'kb' => ['type' => 'collection', 'handle' => 'knowledge_bases', 'name' => 'Knowledge Base Articles'],
        'logo-list-item' => ['type' => 'collection', 'handle' => 'logo_list_items', 'name' => 'Logo List Items'],
        'advert' => ['type' => 'collection', 'handle' => 'classifieds', 'name' => 'Classifieds'],
        'wp_block' => ['type' => 'collection', 'handle' => 'reusable_content', 'name' => 'Reusable Blocks'],
        'nav_menu_item' => ['type' => 'navigation', 'handle' => 'primary_navigation', 'name' => 'Navigation Menu Items'],
    ]);
});

it('maps each WordPress taxonomy to its Statamic taxonomy', function (): void {
    expect(ImportWordPress::getTaxonomyMappings())->toBe([
        'category' => ['type' => 'taxonomy', 'handle' => 'post_categories', 'name' => 'Post Categories'],
        'post_tag' => ['type' => 'taxonomy', 'handle' => 'post_tags', 'name' => 'Post Tags'],
        'logo-list-category' => ['type' => 'taxonomy', 'handle' => 'logos_item_categories', 'name' => 'Logos, Item Categories'],
        'logo-list-label' => ['type' => 'taxonomy', 'handle' => 'logos_item_labels', 'name' => 'Logos, Item Labels'],
        'uamspeople-categories' => ['type' => 'taxonomy', 'handle' => 'people_groups', 'name' => 'People, Groups'],
        'tshowcase-categories' => ['type' => 'taxonomy', 'handle' => 'people_groups', 'name' => 'People, Groups'],
        'kbtag' => ['type' => 'taxonomy', 'handle' => 'knowledge_base_tags', 'name' => 'Knowledge Base, Tags'],
        'mkb_faq_category' => ['type' => 'taxonomy', 'handle' => 'faq_categories', 'name' => 'FAQ, Categories'],
        'advert_category' => ['type' => 'taxonomy', 'handle' => 'classified_categories', 'name' => 'Classifieds, Categories'],
    ]);
});

it('looks a WordPress handle up among the mappings of its kind', function (string $handle, string $type, ?string $expected): void {
    expect(ImportWordPress::mapWordPressToStatamicHandle($handle, $type))->toBe($expected);
})->with([
    'a taxonomy' => ['category', 'taxonomy', 'post_categories'],
    'a collection' => ['page', 'collection', 'pages'],
    'a global, which is no collection' => ['redirect_rule', 'collection', null],
    'a navigation, which is no collection' => ['nav_menu_item', 'collection', null],
    'a content type, which is no taxonomy' => ['page', 'taxonomy', null],
    'an unknown handle' => ['unknown', 'collection', null],
]);

it('refuses a kind of mapping it does not hold', function (): void {
    expect(fn () => ImportWordPress::mapWordPressToStatamicHandle('page', 'global'))
        ->toThrow(InvalidArgumentException::class, "Unsupported mapping type 'global'. Supported types are: taxonomy, collection");
});

it('converts a memory limit to bytes', function (string $limit, int $bytes): void {
    expect(iwhCall(iwhCommand(), 'convertToBytes', $limit))->toBe($bytes);
})->with([
    'unlimited' => ['-1', PHP_INT_MAX],
    'kilobytes' => ['512K', 512 * 1024],
    'megabytes' => ['128M', 128 * 1024 * 1024],
    'megabytes in lower case' => ['128m', 128 * 1024 * 1024],
    'gigabytes' => ['2G', 2 * 1024 * 1024 * 1024],
    'bytes, with no unit' => ['1024', 1024],
    'nothing' => ['', 0],
    'no number before the unit' => ['xM', 0],
]);

it('suggests a memory limit rounded up to the next 128MB', function (float $recommended, string $suggested): void {
    expect(iwhCall(iwhCommand(), 'getSuggestedMemory', $recommended))->toBe($suggested);
})->with([
    'under one step' => [100.0, '128M'],
    'exactly one step' => [128.0, '128M'],
    'just over one step' => [128.5, '256M'],
    'nothing' => [0.0, '0M'],
]);

it('formats a filesize in decimal units', function (): void {
    expect(iwhCall(iwhCommand(), 'formatFilesize', 987654321048))->toBe([
        'bytes' => 987654321048,
        'kb' => 987654321.05,
        'mb' => 987654.32,
        'gb' => 987.65,
    ]);
});

it('measures the files under a directory, at every depth', function (): void {
    $root = sys_get_temp_dir().'/iwh-size-'.bin2hex(random_bytes(6));
    mkdir($root.'/nested/deeper', 0777, true);
    file_put_contents($root.'/a.txt', 'abc');
    file_put_contents($root.'/nested/b.txt', 'abcde');
    file_put_contents($root.'/nested/deeper/c.txt', 'abcdefg');
    symlink($root.'/nested', $root.'/link-to-nested');

    try {
        expect(iwhCall(iwhCommand(), 'calculateDirectoryFilesize', $root))->toBe(15);
    } finally {
        // Windows removes a link to a directory with `rmdir()`
        PHP_OS_FAMILY === 'Windows' ? rmdir($root.'/link-to-nested') : unlink($root.'/link-to-nested');
        unlink($root.'/nested/deeper/c.txt');
        unlink($root.'/nested/b.txt');
        unlink($root.'/a.txt');
        rmdir($root.'/nested/deeper');
        rmdir($root.'/nested');
        rmdir($root);
    }
});

it('measures a directory that does not exist as empty', function (): void {
    expect(iwhCall(iwhCommand(), 'calculateDirectoryFilesize', sys_get_temp_dir().'/iwh-missing-'.bin2hex(random_bytes(6))))->toBe(0);
});

it('reports how much the import grew the app, in each unit', function (): void {
    $command = iwhCommand();
    (new ReflectionProperty(ImportWordPress::class, 'initialFilesize'))->setValue($command, 1000);
    $buffer = iwhOutput($command);

    iwhCall($command, 'displayFilesizeDifference', 2501000);

    expect($buffer->fetch())->toBe(
        PHP_EOL
        .'Filesize difference after import:'.PHP_EOL
        .'  Bytes: 2500000'.PHP_EOL
        .'  Kilobytes: 2500 KB'.PHP_EOL
        .'  Megabytes: 2.5 MB'.PHP_EOL
        .'  Gigabytes: 0 GB'.PHP_EOL
        .PHP_EOL
    );
});
