<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Invoke the protected merge-tag neutralizer via reflection.
 */
function createdByNeutralize(string $value): string
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
function createdByEmailConfig(array $notification): array
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

it('neutralizes an entry-author merge tag standing alone', function (string $mergeTag): void {
    // Both corpus occurrences are whole-value, so the neutralized value collapses to
    // empty and falls back to the site default rather than shipping a literal token.
    // wordpress-importer-archive#497
    expect(createdByNeutralize($mergeTag))->toBe('');
})->with([
    // The two occurrences in the corpus's GravityForms/
    '{created_by:user_email}',
    '{created_by:display_name}',
    // The rest of the WordPress user-property family is equally unresolvable
    '{created_by:first_name}',
    '{created_by:user_login}',
    '{created_by:ID}',
])->group('merge-tags');

it('strips an entry-author merge tag embedded in a longer value', function (
    string $value,
    string $expected
): void {
    // Mirrors the wordpress-importer-archive#444 treatment: the tag is removed, not the whole value blanked.
    expect(createdByNeutralize($value))->toBe($expected);
})->with([
    'appended to an address' => [
        'oedassistance@uams.edu{created_by:display_name}',
        'oedassistance@uams.edu',
    ],
    'inside a subject' => [
        'Formulary Request (_{created_by:display_name})',
        'Formulary Request (_)',
    ],
    'both families in one value' => [
        'a{user:display_name}b{created_by:user_email}c',
        'abc',
    ],
])->group('merge-tags');

it('imports a created_by from address as the site-default From', function (): void {
    // `from` carries no `#empty` translation, so a neutralized whole-value `from`
    // has to be indistinguishable from one that was empty to begin with. wordpress-importer-archive#497
    $neutralized = createdByEmailConfig(['from' => '{created_by:user_email}']);
    $empty = createdByEmailConfig(['from' => '']);

    expect($neutralized['from'] ?? null)->toBe($empty['from'] ?? null);
    expect($neutralized['from'] ?? '')->toBe('');
})->group('merge-tags');

it('keeps the site-name From label when a created_by fromName collapses to empty', function (): void {
    // The tag has to be stripped BEFORE value_translation runs, or the
    // `#empty => {{ site:name }}` branch declared for `fromName` never fires and the
    // imported form silently loses its From label. wordpress-importer-archive#497 (same trap as wordpress-importer-archive#444)
    $emailConfig = createdByEmailConfig(['fromName' => '{created_by:display_name}']);

    expect($emailConfig['from_name'] ?? null)->toBe('{{ site:name }}');
})->group('merge-tags');

it('warns once per neutralized entry-author merge tag', function (): void {
    createdByNeutralize('a{created_by:display_name}b{created_by:user_email}c{created_by:display_name}');

    $neutralized = ImportWarningManager::getWarnings('forms', 'neutralized_merge_tags');

    // One warning per occurrence, so the import report accounts for all three.
    expect($neutralized)->toHaveCount(3);

    $mergeTags = \array_map(
        static fn (mixed $warning): mixed => \is_array($warning) ? ($warning['merge_tag'] ?? null) : null,
        $neutralized
    );
    expect($mergeTags)->toEqual([
        '{created_by:display_name}',
        '{created_by:user_email}',
        '{created_by:display_name}',
    ]);

    // The warning names the entry author, not the logged-in user
    $reasons = \array_map(
        static fn (mixed $warning): mixed => \is_array($warning) ? ($warning['reason'] ?? null) : null,
        $neutralized
    );
    expect($reasons[0] ?? '')->toStartWith('Entry-author merge tags');
})->group('merge-tags');

it('leaves the current-user family untouched by the widened pattern', function (): void {
    // wordpress-importer-archive#444's behavior must be unchanged: the `{user:…}` alternative still matches
    // only the two observed properties, so an unobserved one still falls through.
    expect(createdByNeutralize('{user:display_name}'))->toBe('');
    expect(createdByNeutralize('{user:user_email}'))->toBe('');
    expect(createdByNeutralize('{user:first_name}'))->toBe('{user:first_name}');

    ImportWarningManager::clearWarnings();

    createdByNeutralize('{user:display_name}');
    $warnings = ImportWarningManager::getWarnings('forms', 'neutralized_merge_tags');
    $reason = \is_array($warnings[0] ?? null) ? ($warnings[0]['reason'] ?? '') : '';

    expect($reason)->toStartWith('Current-user merge tags');
})->group('merge-tags');

it('leaves a notification carrying neither family unaffected', function (): void {
    expect(createdByNeutralize('plain@uams.edu'))->toBe('plain@uams.edu');

    // A near-miss that is not a merge tag at all must survive intact
    expect(createdByNeutralize('created_by:display_name'))->toBe('created_by:display_name');
    expect(createdByNeutralize('{created_by}'))->toBe('{created_by}');

    expect(ImportWarningManager::getWarnings('forms', 'neutralized_merge_tags'))->toBeEmpty();
})->group('merge-tags');

it('leaves no live entry-author tag behind when tags overlap', function (string $value): void {
    // A single strip pass lets an overlapping tag reassemble into a live one, which
    // would then ship untouched. wordpress-importer-archive#444's repeat-until-stable loop has to cover the
    // widened pattern too. wordpress-importer-archive#497
    $result = createdByNeutralize($value);

    expect($result)->not->toContain('{created_by:');
    expect($result)->not->toContain('{user:');
})->with([
    'inner tag splits an outer one' => ['{created_by:dis{created_by:user_email}play_name}'],
    'cross-family overlap' => ['{created_by:dis{user:user_email}play_name}'],
])->group('merge-tags');
