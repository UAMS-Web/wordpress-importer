<?php

declare(strict_types=1);

/**
 * Tests for LogContextHelper, which adds the page or form being processed to a
 * log message and its context (#1314).
 *
 * A form configuration set on ProcessingContext takes precedence and names the
 * plugin, form and source. Otherwise the page URL passed in, or the current
 * WordPress page link, names the entry.
 *
 * @command  php vendor/bin/pest tests/Unit/LogContextHelperTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

beforeEach(function (): void {
    ProcessingContext::clearFormConfigContext();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearFormConfigContext();
    ProcessingContext::clearWordPressPageLink();
});

it('adds the passed page URL to the context and the suffix', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/ignored/');

    expect(LogContextHelper::enhanceWithEntryContext(['site' => 'default'], 'https://example.test/page/'))->toBe([
        'context' => ['site' => 'default', 'entry_url' => 'https://example.test/page/'],
        'page_url' => 'https://example.test/page/',
        'source_suffix' => ' (from entry: https://example.test/page/)',
    ]);
});

it('falls back to the current WordPress page link when no URL is passed', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/current/');

    expect(LogContextHelper::enhanceWithEntryContext([]))->toBe([
        'context' => ['entry_url' => 'https://example.test/current/'],
        'page_url' => 'https://example.test/current/',
        'source_suffix' => ' (from entry: https://example.test/current/)',
    ]);
});

it('adds nothing when there is no page URL at all', function (?string $pageUrl): void {
    expect(LogContextHelper::enhanceWithEntryContext(['a' => 1], $pageUrl))->toBe([
        'context' => ['a' => 1],
        'page_url' => $pageUrl,
        'source_suffix' => null,
    ]);
})->with([
    'none passed and none set' => [null],
    'empty string passed' => [''],
]);

it('prefers a form configuration over the page URL', function (int|string $formId, string $source, string $sourceName, ?string $plugin, string $expectedSuffix): void {
    $formConfig = ['form_id' => $formId, 'source' => $source, 'source_name' => $sourceName];

    if ($plugin !== null) {
        $formConfig['plugin'] = $plugin;
    }

    ProcessingContext::setFormConfigContext($formConfig);

    expect(LogContextHelper::enhanceWithEntryContext(['a' => 1], 'https://example.test/page/'))->toBe([
        'context' => ['a' => 1, 'form_config_context' => $formConfig],
        'page_url' => 'https://example.test/page/',
        'source_suffix' => $expectedSuffix,
    ]);
})->with([
    'named plugin and source' => [8, 'notification', 'Admin Notification', 'Gravity Forms', ' (from Gravity Forms form 8 (notification: Admin Notification))'],
    'no plugin' => ['3', 'confirmation', 'Default', null, ' (from form configuration (form 3) (confirmation: Default))'],
    'empty plugin' => [3, 'view', 'Table', '', ' (from form configuration (form 3) (view: Table))'],
    'empty source name' => [5, 'notification', '', 'Formidable Forms', ' (from Formidable Forms form 5)'],
]);

it('appends the form configuration suffix to a message, ignoring the page URL', function (): void {
    ProcessingContext::setFormConfigContext(['form_id' => 8, 'source' => 'notification', 'source_name' => 'Admin', 'plugin' => 'Gravity Forms']);

    expect(LogContextHelper::formatMessageWithEntry('Bad tag', 'https://example.test/page/'))
        ->toBe('Bad tag (from Gravity Forms form 8 (notification: Admin))');
});

it('passes an empty URL through without falling back to the page link', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/current/');

    expect(LogContextHelper::enhanceWithEntryContext(['a' => 1], ''))->toBe([
        'context' => ['a' => 1],
        'page_url' => '',
        'source_suffix' => null,
    ]);
});

it('formats a message from the URL passed in only, never from the page link', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/current/');

    expect(LogContextHelper::formatMessageWithEntry('Bad tag', null))->toBe('Bad tag');
});

it('appends the page URL to a message when no form configuration is set', function (?string $pageUrl, string $expected): void {
    expect(LogContextHelper::formatMessageWithEntry('Bad tag', $pageUrl))->toBe($expected);
})->with([
    'a URL' => ['https://example.test/page/', 'Bad tag (from entry: https://example.test/page/)'],
    'no URL' => [null, 'Bad tag'],
    'an empty URL' => ['', 'Bad tag'],
]);
