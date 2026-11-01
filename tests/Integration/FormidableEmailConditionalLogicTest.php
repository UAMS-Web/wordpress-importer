<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that an email action's send conditions survive to the persisted email
 * configuration (issue wordpress-importer-archive#38).
 *
 * `processConditionalLogic()` used to run only in `processEmailView()`, whose
 * output lands on the transient `currentFormData['email']` that
 * `applyEmailConfigurationsToForm()` replaces wholesale via `Form::email()`
 * before `Form::save()` persists anything. The authoritative builder —
 * `mapNotificationsToEmailConfigurations()` — never called it, so all 22 corpus
 * email actions carrying send-rules imported as send-to-everyone.
 *
 * This drives the authoritative builder directly, which is the copy that reaches
 * disk.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableEmailConditionalLogicTest.php
 */
uses(StatamicTestCase::class);

/**
 * A Formidable form carrying one email action, optionally with send conditions.
 *
 * @param  array<int|string, mixed>  $conditions
 * @return array<string, mixed>
 */
function formidableFormWithEmailAction(array $conditions = []): array
{
    $content = [
        'email_to' => '[3]',
        'email_subject' => 'New submission',
    ];

    if ($conditions !== []) {
        $content['conditions'] = $conditions;
    }

    return [
        'id' => '57',
        'form_key' => 'contact_form',
        'name' => 'Contact Form',
        'fields' => [
            [
                'id' => '3',
                'field_key' => 'contact_email',
                'name' => 'Email Address',
                'type' => 'email',
            ],
            [
                'id' => '4',
                'field_key' => 'department',
                'name' => 'Department',
                'type' => 'select',
            ],
        ],
        'views' => [
            [
                'excerpt' => 'email',
                'post_title' => 'Route to the department',
                'content' => \json_encode($content),
            ],
        ],
    ];
}

/**
 * Build the authoritative email configurations for a Formidable form.
 *
 * @param  array<string, mixed>  $formData
 * @return array<int|string, mixed>
 */
function formidableAuthoritativeEmailConfigurations(array $formData): array
{
    $manager = new FormConfigurationManager;
    $method = (new ReflectionClass($manager))->getMethod('mapNotificationsToEmailConfigurations');
    $method->setAccessible(true);

    $result = $method->invoke($manager, $formData, 'supply_chain', null);

    return \is_array($result) ? $result : [];
}

it('carries email action send conditions into the persisted configuration', function (): void {
    // A Formidable `conditions` block: send only when field 4 equals 'pharmacy'.
    $configurations = formidableAuthoritativeEmailConfigurations(
        formidableFormWithEmailAction([
            'send_stop' => 'send',
            'any_all' => 'all',
            0 => [
                'hide_field' => '4',
                'hide_field_cond' => '==',
                'hide_opt' => 'pharmacy',
            ],
        ])
    );

    expect($configurations)->toHaveCount(1);

    $emailConfig = $configurations[0];
    expect($emailConfig)->toBeArray();
    if (! is_array($emailConfig)) {
        throw new RuntimeException('Expected the email configuration to be an array.');
    }

    // The rule must be on the configuration this path returns, since that is what
    // Form::email() persists.
    expect($emailConfig)->toHaveKey('conditional_logic');
    expect($emailConfig['conditional_logic'])->not->toBeEmpty();
})->group('form-configuration', 'conditional-logic');

it('omits conditional logic for an email action without send conditions', function (): void {
    $configurations = formidableAuthoritativeEmailConfigurations(
        formidableFormWithEmailAction()
    );

    expect($configurations)->toHaveCount(1);

    $emailConfig = $configurations[0];
    expect($emailConfig)->toBeArray();
    if (! is_array($emailConfig)) {
        throw new RuntimeException('Expected the email configuration to be an array.');
    }

    // A send-to-everyone notification stays unconditional.
    expect($emailConfig)->not->toHaveKey('conditional_logic');
})->group('form-configuration', 'conditional-logic');

it('inverts an email action whose directive is stop', function (
    string $sendStop,
    string $expectedKey
): void {
    // An action's directive lives in `send_stop`, not the field-level `show_hide`.
    // Reading only `show_hide` made a "send unless" rule import as "send when", so
    // the notification would fire exactly when it should be suppressed. wordpress-importer-archive#38
    $configurations = formidableAuthoritativeEmailConfigurations(
        formidableFormWithEmailAction([
            'send_stop' => $sendStop,
            'any_all' => 'all',
            0 => [
                'hide_field' => '4',
                'hide_field_cond' => '==',
                'hide_opt' => 'pharmacy',
            ],
        ])
    );

    $emailConfig = $configurations[0] ?? [];
    expect($emailConfig)->toBeArray();
    if (! is_array($emailConfig)) {
        throw new RuntimeException('Expected the email configuration to be an array.');
    }

    $conditionalLogic = $emailConfig['conditional_logic'] ?? [];
    expect($conditionalLogic)->toBeArray();
    if (! is_array($conditionalLogic)) {
        throw new RuntimeException('Expected the conditional logic to be an array.');
    }

    expect($conditionalLogic)->toHaveKey($expectedKey);
})->with([
    'send when the rule matches' => ['send', 'if'],
    'send unless the rule matches' => ['stop', 'unless'],
])->group('form-configuration', 'conditional-logic');
