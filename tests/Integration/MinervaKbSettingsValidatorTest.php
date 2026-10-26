<?php

declare(strict_types=1);

/**
 * Tests that the MinervaKB settings validator accepts a settings array only when
 * every required MinervaKB key is present, and reports a rejection on the console
 * and in the log (#1314).
 *
 * @command  php vendor/bin/pest tests/Integration/MinervaKbSettingsValidatorTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Validator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A settings array carrying every key the validator requires.
 *
 * @return array<string, string>
 */
function minervaKbValidatorSettings(): array
{
    return [
        'search_title' => 'Search',
        'search_placeholder' => 'Search the knowledge base',
        'breadcrumbs_home_label' => 'Home',
        'cpt_label_name' => 'Articles',
        'cpt_topic_label_name' => 'Topics',
    ];
}

it('accepts settings carrying every required key, saying nothing', function (): void {
    $log = Log::spy();
    $output = new BufferedOutput;

    // An extra key alongside the required ones does not matter
    $settings = minervaKbValidatorSettings() + ['other' => 'value'];

    expect((new Validator)->validateSettings($settings, $output))->toBeTrue()
        ->and($output->fetch())->toBe('');

    $log->shouldNotHaveReceived('error');
});

it('rejects settings missing any one required key, on the console and in the log', function (string $missing): void {
    $log = Log::spy();
    $output = new BufferedOutput;

    $settings = minervaKbValidatorSettings();
    unset($settings[$missing]);

    expect((new Validator)->validateSettings($settings, $output))->toBeFalse()
        ->and($output->fetch())->toBe('The file does not appear to be a valid MinervaKB settings file.'.PHP_EOL);

    $log->shouldHaveReceived('error', [
        'Validator::validateSettings: File does not appear to be a valid MinervaKB settings file',
    ]);
})->with([
    'search_title',
    'search_placeholder',
    'breadcrumbs_home_label',
    'cpt_label_name',
    'cpt_topic_label_name',
]);

it('rejects an empty settings array', function (): void {
    Log::spy();

    expect((new Validator)->validateSettings([], new BufferedOutput))->toBeFalse();
});

it('accepts a required key whose value is null', function (): void {
    Log::spy();

    $settings = ['search_title' => null] + minervaKbValidatorSettings();

    expect((new Validator)->validateSettings($settings, new BufferedOutput))->toBeTrue();
});
