<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Map a form's notifications through the real notification path, which is what
 * persists.
 *
 * @param  list<array<string, mixed>>  $notifications
 * @return array<int|string, mixed>
 */
function notificationSkipConfigs(array $notifications, string $formId = '6'): array
{
    $manager = new FormConfigurationManager;
    $method = (new ReflectionClass($manager))->getMethod('mapNotificationsToEmailConfigurations');
    $method->setAccessible(true);

    $formData = [
        'id' => $formId,
        'title' => 'Test Form',
        'fields' => [],
        'notifications' => $notifications,
    ];

    $result = $method->invoke($manager, $formData, 'supply_chain', null);

    return \is_array($result) ? $result : [];
}

/**
 * Build a Save & Continue notification in the shape the corpus carries.
 *
 * @return array<string, mixed>
 */
function saveAndContinueNotification(string $id = '62b47371007ec'): array
{
    return [
        'id' => $id,
        'isActive' => true,
        'name' => 'User Email',
        'event' => 'form_save_email_requested',
        'toType' => 'hidden',
        'to' => null,
        'subject' => 'Link to continue {form_title}',
        'message' => 'Thank you for saving {form_title}. Please use the unique link '
            .'below to return to the form from any computer. <br /><br /> {save_link} '
            .'<br /><br /> Remember that the link will expire after 30 days.',
        'from' => '{admin_email}',
    ];
}

/**
 * Build an ordinary submission notification.
 *
 * @return array<string, mixed>
 */
function submissionNotification(string $id = 'aaa111'): array
{
    return [
        'id' => $id,
        'isActive' => true,
        'name' => 'Admin Notification',
        'event' => 'form_submission',
        'toType' => 'email',
        'to' => 'admin@uams.edu',
        'subject' => 'New submission',
        'message' => 'A submission arrived.',
    ];
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('skips a Save & Continue notification instead of importing a dead email action', function (): void {
    // Importing it would write an action with `to: null` and a literal, unresolvable
    // `{save_link}` in the body — a live broken notification that reads as real to
    // anyone inspecting the form YAML. wordpress-importer-archive#33
    $configs = notificationSkipConfigs([saveAndContinueNotification()]);

    expect($configs)->toBe([]);
})->group('gf-notifications');

it('keeps every other notification on a form that also has a Save & Continue one', function (): void {
    // The skip is per notification, not per form — a form carrying both must still
    // import its submission notification. wordpress-importer-archive#33
    $configs = notificationSkipConfigs([
        saveAndContinueNotification(),
        submissionNotification(),
    ]);

    expect($configs)->toHaveCount(1);

    $first = \is_array($configs[0] ?? null) ? $configs[0] : [];
    expect($first['id'] ?? null)->toBe('aaa111');
    expect($first['to'] ?? null)->toBe('admin@uams.edu');
})->group('gf-notifications');

it('warns once per skipped Save & Continue notification, naming form and notification', function (): void {
    notificationSkipConfigs(
        [
            saveAndContinueNotification('62b47371007ec'),
            submissionNotification(),
            saveAndContinueNotification('64ee02b639bf0'),
        ],
        '16'
    );

    $warnings = ImportWarningManager::getWarnings('forms', 'skipped_notifications');

    expect($warnings)->toHaveCount(2);

    $first = \is_array($warnings[0] ?? null) ? $warnings[0] : [];
    expect($first['form_id'] ?? null)->toBe('16');
    expect($first['notification_id'] ?? null)->toBe('62b47371007ec');
    expect($first['notification_name'] ?? null)->toBe('User Email');
    expect($first['event'] ?? null)->toBe('form_save_email_requested');

    $second = \is_array($warnings[1] ?? null) ? $warnings[1] : [];
    expect($second['notification_id'] ?? null)->toBe('64ee02b639bf0');
})->group('gf-notifications');

it('leaves an ordinary submission notification untouched', function (): void {
    // The skip must key on the Save & Continue event alone — the common
    // `form_submission` event is what a Statamic email action already means. wordpress-importer-archive#33
    $configs = notificationSkipConfigs([submissionNotification()]);

    expect($configs)->toHaveCount(1);
    expect(ImportWarningManager::getWarnings('forms', 'skipped_notifications'))->toBeEmpty();
})->group('gf-notifications');

it('imports a notification whose event is absent or unrecognized', function (mixed $event): void {
    // Only the one event is skipped; anything else still imports, so an export
    // shape the audit never saw cannot silently lose its notifications. wordpress-importer-archive#33
    $notification = submissionNotification();

    if ($event === null) {
        unset($notification['event']);
    } else {
        $notification['event'] = $event;
    }

    expect(notificationSkipConfigs([$notification]))->toHaveCount(1);
})->with([
    'absent' => [null],
    'unknown event' => ['form_some_future_event'],
    'empty string' => [''],
    'non-string' => [0],
])->group('gf-notifications');

it('does not skip on a near-miss event value', function (string $event): void {
    // An exact match only — a substring or differently-cased value is a different
    // event and must still import. wordpress-importer-archive#33
    $notification = submissionNotification();
    $notification['event'] = $event;

    expect(notificationSkipConfigs([$notification]))->toHaveCount(1);
})->with([
    'cased differently' => ['Form_Save_Email_Requested'],
    'prefixed' => ['xform_save_email_requested'],
    'suffixed' => ['form_save_email_requested_v2'],
    'padded' => [' form_save_email_requested'],
])->group('gf-notifications');

it('reports a Notification Scheduler delay the import cannot honor', function (string $scheduleType): void {
    // Nothing in the corpus schedules anything, but the corpus is a sample of what
    // the importer will see, not a bound on it. A delayed notification would
    // otherwise collapse to an immediate send with no signal anywhere. wordpress-importer-archive#35
    notificationSkipConfigs([
        submissionNotification() + ['scheduleType' => $scheduleType, 'schedule' => '1'],
    ]);

    $warnings = ImportWarningManager::getWarnings('forms', 'unhonored_notification_schedule');

    expect($warnings)->toHaveCount(1);

    $first = \is_array($warnings[0] ?? null) ? $warnings[0] : [];
    expect($first['schedule_type'] ?? null)->toBe($scheduleType);
    expect($first['form_id'] ?? null)->toBe('6');
    expect($first['notification_id'] ?? null)->toBe('aaa111');
})->with([
    'delay' => ['delay'],
    'date' => ['date'],
    'field value' => ['field_value'],
])->group('gf-notifications');

it('still imports a scheduled notification after warning about it', function (): void {
    // The scheduler fields are dropped, not the notification — an immediate send is
    // a degraded outcome, not a reason to delete the email action. wordpress-importer-archive#35
    $configs = notificationSkipConfigs([
        submissionNotification() + ['scheduleType' => 'delay', 'scheduleDelayOffset' => '30'],
    ]);

    expect($configs)->toHaveCount(1);

    $first = \is_array($configs[0] ?? null) ? $configs[0] : [];
    expect($first['to'] ?? null)->toBe('admin@uams.edu');

    // The dropped add-on fields must not leak into the imported config
    foreach (\array_keys($first) as $key) {
        expect(\is_string($key) ? $key : '')->not->toStartWith('schedule');
    }
})->group('gf-notifications');

it('stays quiet for the immediate schedule every corpus notification uses', function (mixed $scheduleType): void {
    // All 12 scheduler-bearing corpus notifications are `immediate`, which is exactly
    // what the import produces — warning on those would be noise. wordpress-importer-archive#35
    $notification = submissionNotification();

    if ($scheduleType === null) {
        unset($notification['scheduleType']);
    } else {
        $notification['scheduleType'] = $scheduleType;
    }

    notificationSkipConfigs([$notification]);

    expect(ImportWarningManager::getWarnings('forms', 'unhonored_notification_schedule'))->toBeEmpty();
})->with([
    'immediate' => ['immediate'],
    'empty (add-on present, unset)' => [''],
    'absent (add-on not in use)' => [null],
    'non-string' => [0],
])->group('gf-notifications');

it('does not warn about a schedule on a skipped Save & Continue notification', function (): void {
    // A skipped notification sends nothing at all, so a schedule it will never honor
    // is not a finding worth reporting twice. wordpress-importer-archive#33 + wordpress-importer-archive#35
    notificationSkipConfigs([
        saveAndContinueNotification() + ['scheduleType' => 'delay'],
    ]);

    expect(ImportWarningManager::getWarnings('forms', 'skipped_notifications'))->toHaveCount(1);
    expect(ImportWarningManager::getWarnings('forms', 'unhonored_notification_schedule'))->toBeEmpty();
})->group('gf-notifications');
