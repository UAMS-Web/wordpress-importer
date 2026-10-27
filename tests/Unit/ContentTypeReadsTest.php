<?php

declare(strict_types=1);

/**
 * Tests that every type `--content-type` accepts declares its companions, link targets and
 * passes, and that redirect rules declare less than the types with a body (#109).
 *
 * @command  php vendor/bin/pest tests/Unit/ContentTypeReadsTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\ContentTypeReads;

it('declares the body companions, the link targets and the navigation pass for every type with a body', function (string $contentType): void {
    // On a vanity domain too: only redirect rules read less there
    foreach ([false, true] as $redirectDomain) {
        expect(ContentTypeReads::companions($contentType, $redirectDomain))
            ->toBe(['attachment', 'wp_block', 'nav_menu_item', 'tablepress_table'])
            ->and(ContentTypeReads::linkTargets($contentType, $redirectDomain))
            ->toBe(['page', 'post', 'uamspeople']);
    }

    expect(ContentTypeReads::passes($contentType))->toBe(['navigation'])
        ->and(ContentTypeReads::runsNavigation($contentType))->toBeTrue();
})->with(['page', 'post', 'uamspeople', 'tshowcase', 'logo-list-item', 'advert']);

it('adds pages to a knowledge base, after the body companions and once', function (): void {
    expect(ContentTypeReads::companions('kb', false))
        ->toBe(['attachment', 'wp_block', 'nav_menu_item', 'tablepress_table', 'page'])
        ->and(ContentTypeReads::linkTargets('kb', false))->toBe(['page', 'post', 'uamspeople'])
        ->and(ContentTypeReads::runsNavigation('kb'))->toBeTrue();
});

it('reads only attachments and the link targets for redirect rules on a site', function (): void {
    expect(ContentTypeReads::companions('redirect_rule', false))->toBe(['attachment'])
        ->and(ContentTypeReads::linkTargets('redirect_rule', false))->toBe(['page', 'post', 'uamspeople']);
});

it('reads nothing besides the rules for redirect rules on a vanity domain', function (): void {
    expect(ContentTypeReads::companions('redirect_rule', true))->toBe([])
        ->and(ContentTypeReads::linkTargets('redirect_rule', true))->toBe([]);
});

it('runs no pass after the loop for redirect rules', function (): void {
    expect(ContentTypeReads::passes('redirect_rule'))->toBe([])
        ->and(ContentTypeReads::runsNavigation('redirect_rule'))->toBeFalse();
});
