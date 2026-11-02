<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the three Formidable merge-tag patterns that survived untranslated into
 * the imported Statamic email config (issue wordpress-importer-archive#39).
 *
 * The `communications-uams-edu_web` form "UAMSHealth.com Provider Profile Data
 * Collection Form" exercises all three: a `to` of `[provider_email_address]`
 * (a field KEY, which resolved neither by id nor by label), a `cc` wrapped in
 * `[if editor_email_address not_equal=""]...[/if editor_email_address]`, and a
 * message body of `[default-message exclude_fields="541,542,..."]`.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableMergeTagGapsTest.php
 */
uses(StatamicTestCase::class);

/**
 * Fields modeled on the provider-profile form: each carries an id, a field key,
 * and a label.
 *
 * @return array<int, array<string, mixed>>
 */
function formidableProviderProfileFields(): array
{
    return [
        [
            'id' => '531',
            'field_key' => 'provider_email_address',
            'name' => 'Provider Email Address',
            'type' => 'email',
        ],
        [
            'id' => '532',
            'field_key' => 'editor_email_address',
            'name' => 'Editor Email Address',
            'type' => 'email',
        ],
        [
            'id' => '533',
            'field_key' => 'full_name',
            'name' => 'Full Name',
            'type' => 'text',
        ],
    ];
}

/**
 * Invoke the protected Formidable field-reference translator.
 *
 * @param  array<int, array<string, mixed>>  $fields
 */
function formidableTranslateFieldReferences(string $text, array $fields): string
{
    $manager = new FormConfigurationManager;
    $method = (new ReflectionClass($manager))->getMethod('translateFieldReferences');
    $method->setAccessible(true);

    $result = $method->invoke($manager, $text, $fields);

    return \is_string($result) ? $result : '';
}

/**
 * Invoke the protected Formidable complex-format merge-tag mapper.
 */
function formidableProcessComplexFormatMappings(string $text): string
{
    $manager = new FormConfigurationManager;
    $method = (new ReflectionClass($manager))->getMethod('processComplexFormatMappings');
    $method->setAccessible(true);

    $result = $method->invoke($manager, $text);

    return \is_string($result) ? $result : '';
}

it('resolves a field-key reference to its submission tag', function (): void {
    // `[provider_email_address]` is neither a numeric id nor a label, so it used to
    // fall through unchanged into `to`.
    $translated = formidableTranslateFieldReferences(
        '[provider_email_address]',
        formidableProviderProfileFields()
    );

    expect($translated)->not->toContain('[provider_email_address]');
    expect($translated)->toStartWith('{{ submission:');
})->group('merge-tags');

it('still prefers a numeric id and a label over the field key', function (): void {
    $fields = formidableProviderProfileFields();

    // The field key is only a last-resort fallback, so these paths are unchanged.
    expect(formidableTranslateFieldReferences('[531]', $fields))->toStartWith('{{ submission:');
    expect(formidableTranslateFieldReferences('[Full Name]', $fields))->toStartWith('{{ submission:');
})->group('merge-tags');

it('drops a conditional shortcode together with its body', function (): void {
    // The real `reply_to` from the provider-profile form. The comma lives INSIDE the
    // guard, so keeping the body would make the optional recipient unconditional: a
    // blank editor field renders `provider@uams.edu,`, and Statamic's
    // Email::addresses() explodes on that comma and hands Symfony an empty address,
    // which throws and the notification never sends. wordpress-importer-archive#39
    $translated = formidableTranslateFieldReferences(
        '[provider_email_address][if editor_email_address not_equal=""],[editor_email_address][/if editor_email_address]',
        formidableProviderProfileFields()
    );

    expect($translated)->not->toContain('[if ');
    expect($translated)->not->toContain('[/if ');
    expect($translated)->not->toEndWith(',');
    expect($translated)->not->toContain(',,');

    // Only the required recipient survives, which is what Formidable emitted when
    // the optional field was blank.
    expect($translated)->toStartWith('{{ submission:');
    expect(\substr_count($translated, ','))->toBe(0);
})->group('merge-tags');

it('leaves bracketed text that is not a field conditional untouched', function (
    string $value
): void {
    // An unanchored `[if ...]` pattern also eats Outlook conditional comments and
    // ordinary prose, silently deleting content and breaking the comment. Only a
    // wrapper PAIR naming a real field on this form is dropped. wordpress-importer-archive#39
    expect(formidableTranslateFieldReferences($value, formidableProviderProfileFields()))
        ->toBe($value);
})->with([
    'an Outlook conditional comment' => [
        '<!--[if gte mso 9]><style>table{border:0}</style><![endif]-->',
    ],
    'ordinary bracketed prose' => [
        'Contact us [if you need help] or call.',
    ],
    'an unclosed conditional' => [
        '[if editor_email_address not_equal=""] dangling',
    ],
])->group('merge-tags');

it('resolves a field-key reference embedded in a subject', function (): void {
    // The real `subject` from the provider-profile form.
    $translated = formidableTranslateFieldReferences(
        'Submission From the {{ form:title }} | [full_name]',
        formidableProviderProfileFields()
    );

    expect($translated)->toStartWith('Submission From the {{ form:title }} | {{ submission:');
    expect($translated)->not->toContain('[full_name]');
})->group('merge-tags');

it('maps a parameterized default-message to the base tag', function (): void {
    // The real message body from the provider-profile form.
    expect(formidableProcessComplexFormatMappings(
        '[default-message exclude_fields="541,542,543,538,544,545,546,547,539"]'
    ))->toBe('{{ all_fields }}');
})->group('merge-tags');

it('leaves an unknown bracket reference untouched', function (): void {
    // Only recognized patterns are rewritten; anything else still round-trips.
    expect(formidableTranslateFieldReferences('[not_a_field]', formidableProviderProfileFields()))
        ->toBe('[not_a_field]');
    expect(formidableProcessComplexFormatMappings('no merge tags here'))
        ->toBe('no merge tags here');
})->group('merge-tags');
