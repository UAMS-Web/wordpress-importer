<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Invoke the protected current-user merge-tag handler via reflection.
 */
function gravityProcessComplexFormatMappings(string $value): string
{
    $manager = new FormConfigurationManager;
    $method = (new ReflectionClass($manager))->getMethod('processCurrentUserMergeTags');
    $method->setAccessible(true);

    $result = $method->invoke($manager, $value);

    return \is_string($result) ? $result : '';
}

/**
 * Build the email configuration for a single notification through the real
 * notification path, which is what persists.
 *
 * @param  array<string, mixed>  $notification
 * @return array<int|string, mixed>
 */
function gravityEmailConfigForNotification(array $notification): array
{
    $manager = new FormConfigurationManager;
    $method = (new ReflectionClass($manager))->getMethod('mapNotificationsToEmailConfigurations');
    $method->setAccessible(true);

    $formData = [
        'id' => '1',
        'title' => 'Test Form',
        'fields' => [],
        'notifications' => [$notification + ['name' => 'Admin Notification', 'to' => 'a@uams.edu']],
    ];

    $result = $method->invoke($manager, $formData, 'supply_chain', null);
    $first = \is_array($result) ? ($result[0] ?? []) : [];

    return \is_array($first) ? $first : [];
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('neutralizes a current-user merge tag standing alone', function (string $mergeTag): void {
    // A value that held nothing else collapses to empty, which falls back to the
    // site-default From rather than shipping a literal token. wordpress-importer-archive#444
    expect(gravityProcessComplexFormatMappings($mergeTag))->toBe('');
})->with([
    '{user:display_name}',
    '{user:user_email}',
])->group('merge-tags');

it('strips a current-user merge tag embedded in a longer value', function (
    string $value,
    string $expected
): void {
    // The corpus carries these tags inside longer strings, so the tag is removed
    // rather than the whole value being blanked. wordpress-importer-archive#444
    expect(gravityProcessComplexFormatMappings($value))->toBe($expected);
})->with([
    // A real `from` value from gravityforms-export_educationaldevelopment-uams-edu.json
    'appended to an address' => [
        '{Name (First):1.3}oedassistance@uams.edu{user:display_name}',
        '{Name (First):1.3}oedassistance@uams.edu',
    ],
    // A real `subject` from gravityforms-export_inside-uams-edu_pharmacyservices.json
    'inside a subject' => [
        'Formulary Request (_{user:display_name})',
        'Formulary Request (_)',
    ],
    'both tags in one value' => [
        'a{user:display_name}b{user:user_email}c',
        'abc',
    ],
])->group('merge-tags');

it('leaves a value without current-user merge tags untouched', function (): void {
    expect(gravityProcessComplexFormatMappings('plain@uams.edu'))->toBe('plain@uams.edu');
    expect(ImportWarningManager::getWarnings('forms', 'neutralized_merge_tags'))->toBeEmpty();
})->group('merge-tags');

it('warns once per neutralized current-user merge tag', function (): void {
    gravityProcessComplexFormatMappings('a{user:display_name}b{user:user_email}c{user:display_name}');

    $neutralized = ImportWarningManager::getWarnings('forms', 'neutralized_merge_tags');

    // One warning per occurrence, so the import report accounts for all three.
    expect($neutralized)->toHaveCount(3);

    $mergeTags = \array_map(
        static fn (mixed $warning): mixed => \is_array($warning) ? ($warning['merge_tag'] ?? null) : null,
        $neutralized
    );
    expect($mergeTags)->toEqual([
        '{user:display_name}',
        '{user:user_email}',
        '{user:display_name}',
    ]);
})->group('merge-tags');

it('keeps the site-name From label when a neutralized fromName collapses to empty', function (
    string $fromName
): void {
    // Every corpus `fromName` occurrence is the whole-value form, so the tag has to
    // be stripped BEFORE value_translation runs — otherwise the
    // `#empty => {{ site:name }}` branch declared for exactly this case never fires
    // and the imported form silently loses its From label. wordpress-importer-archive#444
    $emailConfig = gravityEmailConfigForNotification(['fromName' => $fromName]);

    expect($emailConfig['from_name'] ?? null)->toBe('{{ site:name }}');
})->with([
    '{user:display_name}',
    '{user:user_email}',
])->group('merge-tags');

it('matches an empty fromName exactly when the tag is neutralized', function (): void {
    // The neutralized value must be indistinguishable from one that was empty to
    // begin with. wordpress-importer-archive#444
    $neutralized = gravityEmailConfigForNotification(['fromName' => '{user:display_name}']);
    $empty = gravityEmailConfigForNotification(['fromName' => '']);

    expect($neutralized['from_name'] ?? null)->toBe($empty['from_name'] ?? null);
})->group('merge-tags');

it('strips a current-user merge tag embedded in a notification address', function (): void {
    // A real `from` from gravityforms-export_educationaldevelopment-uams-edu.json.
    $emailConfig = gravityEmailConfigForNotification([
        'from' => 'oedassistance@uams.edu{user:display_name}',
    ]);

    expect($emailConfig['from'] ?? null)->toBe('oedassistance@uams.edu');
})->group('merge-tags');

it('leaves no live tag behind when tags overlap', function (string $value): void {
    // A single strip pass lets an overlapping tag reassemble into a live one, which
    // would then ship untouched. wordpress-importer-archive#444
    $result = gravityProcessComplexFormatMappings($value);

    expect($result)->not->toContain('{user:');
})->with([
    'inner tag splits an outer one' => ['{user:dis{user:user_email}play_name}'],
    'inner tag opens an outer one' => ['{user:{user:display_name}user_email}'],
])->group('merge-tags');
