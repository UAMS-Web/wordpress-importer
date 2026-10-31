<?php

declare(strict_types=1);

/**
 * Tests that every type `--content-type` accepts declares its companions, link targets and
 * passes, and that redirect rules declare less than the types with a body (#109).
 *
 * @command  php vendor/bin/pest tests/Unit/ContentTypeReadsTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\ContentTypeReads;

it('declares the body companions, the link targets and both passes for every type with a body', function (string $contentType): void {
    // On a vanity domain too: only redirect rules read less there
    foreach ([false, true] as $redirectDomain) {
        expect(ContentTypeReads::companions($contentType, $redirectDomain))
            ->toBe(['attachment', 'wp_block', 'nav_menu_item', 'tablepress_table'])
            ->and(ContentTypeReads::linkTargets($contentType, $redirectDomain))
            ->toBe(['page', 'post', 'uamspeople']);
    }

    expect(ContentTypeReads::passes($contentType))->toBe(['navigation', 'bard_sanitization', 'search_index']);
})->with(['page', 'post', 'uamspeople', 'tshowcase', 'logo-list-item', 'advert']);

it('adds pages to a knowledge base, after the body companions and once', function (): void {
    expect(ContentTypeReads::companions('kb', false))
        ->toBe(['attachment', 'wp_block', 'nav_menu_item', 'tablepress_table', 'page'])
        ->and(ContentTypeReads::linkTargets('kb', false))->toBe(['page', 'post', 'uamspeople'])
        ->and(ContentTypeReads::passes('kb'))->toBe(['navigation', 'bard_sanitization', 'search_index']);
});

it('reads only attachments and the link targets for redirect rules on a site', function (): void {
    expect(ContentTypeReads::companions('redirect_rule', false))->toBe(['attachment'])
        ->and(ContentTypeReads::linkTargets('redirect_rule', false))->toBe(['page', 'post', 'uamspeople']);
});

it('reads nothing besides the rules for redirect rules on a vanity domain', function (): void {
    expect(ContentTypeReads::companions('redirect_rule', true))->toBe([])
        ->and(ContentTypeReads::linkTargets('redirect_rule', true))->toBe([]);
});

it('declares no pass after the loop for redirect rules', function (): void {
    expect(ContentTypeReads::passes('redirect_rule'))->toBe([]);
});

it('runs a pass when any imported type declares it', function (string $pass, array $contentTypes, bool $runs): void {
    $types = \array_values(\array_filter($contentTypes, \is_string(...)));

    expect(ContentTypeReads::runsPass($pass, $types))->toBe($runs);
})->with([
    'a page run, navigation' => [ContentTypeReads::PASS_NAVIGATION, ['page'], true],
    'a page run, Bard' => [ContentTypeReads::PASS_BARD_SANITIZATION, ['page'], true],
    'a redirect run, navigation' => [ContentTypeReads::PASS_NAVIGATION, ['redirect_rule'], false],
    'a page run, search indexes' => [ContentTypeReads::PASS_SEARCH_INDEX, ['page'], true],
    'a redirect run, search indexes' => [ContentTypeReads::PASS_SEARCH_INDEX, ['redirect_rule'], false],
    'pages then redirects, search indexes' => [ContentTypeReads::PASS_SEARCH_INDEX, ['page', 'redirect_rule'], true],
    'a redirect run, Bard' => [ContentTypeReads::PASS_BARD_SANITIZATION, ['redirect_rule'], false],
    'redirects then pages' => [ContentTypeReads::PASS_NAVIGATION, ['redirect_rule', 'page'], true],
    'pages then redirects' => [ContentTypeReads::PASS_NAVIGATION, ['page', 'redirect_rule'], true],
    'nothing imported' => [ContentTypeReads::PASS_NAVIGATION, [], true],
    'an undeclared pass' => ['unknown', ['page'], false],
]);

it('gives no type companions that repeat the shared ones, so its list needs no deduplication', function (): void {
    foreach (ContentTypeReads::TYPE_COMPANIONS as $companions) {
        expect(\array_intersect($companions, ContentTypeReads::BODY_COMPANIONS))->toBe([]);
    }
});
