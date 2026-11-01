<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#34.
 *
 * Gravity Forms carries three legacy `*Field` notification aliases —
 * `fromField`, `fromNameField`, and `replyToField` — that populate the From,
 * From Name, and Reply To values from a form field rather than a literal.
 * They were absent from `FORM_CONFIGURATION_ACTION_MAPPING_EMAIL`, so a
 * notification configured with "From Name: {field N}" imported as the
 * `{{ site:name }}` fallback and the applicant's name was silently lost.
 *
 * The fix maps each alias to its `from` / `from_name` / `reply_to` attribute
 * with the `field_id_to_submission_tag` flag, which resolves the field ID to a
 * field handle and emits a `{{ submission:<handle> }}` merge tag the email
 * renderer dereferences at send time. The aliases are placed after the literal
 * entries in the mapping, so a set field reference wins over the literal
 * (matching the Gravity Forms UI).
 *
 * These assertions exercise `mapNotificationsToEmailConfigurations()` directly
 * via reflection. It is a pure array-in / array-out transform (plus `Str`
 * helpers and static `ProcessingContext`) that never touches the container,
 * Statamic facades, or the database — so the test runs on plain PHPUnit.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue34RegressionTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;

/**
 * Map one notification (merged over a minimal, active email notification) and
 * return the first Statamic email configuration.
 *
 * @param  array<array-key, mixed>  $notificationOverrides
 * @return array<int|string, mixed>
 */
function mapSingleNotification(array $notificationOverrides): array
{
    // A single text field, id 1, whose label slugs to the handle
    // `applicant_name_1` (Str::slug + `_<id>` suffix).
    $fields = [
        [
            'id' => 1,
            'label' => 'Applicant Name',
            'type' => 'text',
        ],
    ];

    $notification = array_merge([
        'id' => '1000000000001',
        'name' => 'Test Notification',
        'isActive' => true,
        'toType' => 'email',
    ], $notificationOverrides);

    $formData = [
        'id' => 999,
        'fields' => $fields,
        'notifications' => [$notification],
    ];

    $method = new ReflectionMethod(
        FormConfigurationManager::class,
        'mapNotificationsToEmailConfigurations'
    );
    $method->setAccessible(true);

    $configs = $method->invoke(new FormConfigurationManager, $formData, 'test_site', null);
    $configs = is_array($configs) ? $configs : [];

    // Exactly one email configuration should be produced.
    expect($configs)->toHaveKey(0);

    $config = $configs[0] ?? [];

    return is_array($config) ? $config : [];
}

/**
 * Each legacy *Field notification alias paired with the email-config key it must
 * populate with a {{ submission:<handle> }} merge tag.
 *
 * @return iterable<string, array{array<string, mixed>, string}>
 */
function fieldAliasNotificationCases(): iterable
{
    // fromNameField references field 1; before the fix from_name fell back to
    // {{ site:name }} and the applicant's name was lost.
    yield 'fromNameField -> from_name' => [['fromName' => '', 'fromNameField' => '1'], 'from_name'];
    // fromField must resolve to the field and win over the from literal.
    yield 'fromField -> from (wins over the literal)' => [['from' => 'admin@example.com', 'fromField' => '1'], 'from'];
    yield 'replyToField -> reply_to' => [['replyTo' => '', 'replyToField' => '1'], 'reply_to'];
}

it('resolves each *Field notification alias to a submission merge tag from the referenced field', function (array $overrides, string $configKey): void {
    $config = mapSingleNotification($overrides);

    expect($config[$configKey] ?? null)->toBe('{{ submission:applicant_name_1 }}');
})->with(fieldAliasNotificationCases());

it('prefers a set field reference over a non-empty literal', function (): void {
    // Both a literal name and a field reference are set. The Gravity Forms UI
    // prefers the field, so the import must too.
    $config = mapSingleNotification([
        'fromName' => 'Site Admin',
        'fromNameField' => '1',
    ]);

    expect($config['from_name'] ?? null)->toBe('{{ submission:applicant_name_1 }}');
});

it('preserves the literal fallback when the field alias is empty', function (): void {
    // The common corpus shape: the *Field key is present but empty. The literal
    // (#empty -> {{ site:name }}) fallback must be preserved, not clobbered by
    // the empty alias.
    $config = mapSingleNotification([
        'fromName' => '',
        'fromNameField' => '',
    ]);

    expect($config['from_name'] ?? null)->toBe('{{ site:name }}');
});

it('preserves the fallback for an unresolvable field id while still mapping a later alias', function (): void {
    // fromNameField points at a field that does not exist on the form. Rather
    // than emit a broken merge tag or leak the raw id, the literal fallback must
    // be preserved -- and a *later* alias (replyToField, which follows
    // fromNameField in the mapping) must still be processed, proving an
    // unresolvable alias only skips itself rather than abandoning the rest of
    // the notification.
    $config = mapSingleNotification([
        'fromName' => '',
        'fromNameField' => '999',
        'replyToField' => '1',
    ]);

    expect($config['from_name'] ?? null)->toBe('{{ site:name }}')
        ->and($config['reply_to'] ?? null)->toBe('{{ submission:applicant_name_1 }}');
});
