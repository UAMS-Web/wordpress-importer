<?php

declare(strict_types=1);

/**
 * The Formidable Forms form configuration manager: its options, views, confirmations,
 * email actions, field references and conditional shortcodes (#98).
 *
 * Most of the manager's hooks are protected or private, so they are called through
 * reflection on a real instance; the public entry point configures and saves a real form.
 * `StatamicTestCase` points form writes at a per-process directory, so that form never
 * lands in the working tree.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableFormsFormConfigurationManagerTest.php
 */

use Illuminate\Support\Collection;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Call one of the manager's methods, whatever its visibility.
 *
 * @param  FormConfigurationManager  $manager  The manager
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments
 * @return mixed What the method returned
 */
function ffcmCall(FormConfigurationManager $manager, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($manager, $method))->invoke($manager, ...$arguments);
}

/**
 * Make a Statamic form.
 *
 * @param  string  $handle  The form handle
 * @return Form The form
 */
function ffcmForm(string $handle): Form
{
    $form = FormFacade::make($handle);

    if (! $form instanceof Form) {
        throw new LogicException('FormFacade::make() returned no Statamic form.');
    }

    return $form;
}

/**
 * Read a form's stored data.
 *
 * @param  Form  $form  The form
 * @return array<int|string, mixed> The data
 */
function ffcmFormData(Form $form): array
{
    $data = $form->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * Narrow a result a test knows is a table.
 *
 * @param  mixed  $value  The result
 * @return array<int|string, mixed> The table
 */
function ffcmTable(mixed $value): array
{
    if (! \is_array($value)) {
        throw new LogicException('Expected a table, got '.\get_debug_type($value).'.');
    }

    return $value;
}

/**
 * Read the warnings recorded in one category and type.
 *
 * @param  string  $category  The warning category
 * @param  string  $type  The warning type
 * @return list<mixed> The warnings
 */
function ffcmWarnings(string $category, string $type): array
{
    $warnings = ImportWarningManager::getWarningsByCategory($category)[$type] ?? [];

    return \is_array($warnings) ? \array_values($warnings) : [];
}

/**
 * A form's fields: an email field, a select, and a field with no name.
 *
 * @return list<array<string, mixed>> The fields
 */
function ffcmFields(): array
{
    return [
        ['id' => '3', 'field_key' => 'contact_email', 'name' => 'Email Address', 'type' => 'email'],
        ['id' => 4, 'field_key' => 'department', 'name' => 'Department', 'type' => 'select'],
        ['id' => '5', 'field_key' => 'unnamed_key', 'name' => '', 'type' => 'text'],
    ];
}

/**
 * An email action view.
 *
 * @param  array<string, mixed>  $content  The action's settings
 * @param  array<string, mixed>  $view  Overrides for the view itself
 * @return array<string, mixed> The view
 */
function ffcmEmailView(array $content, array $view = []): array
{
    return ['excerpt' => 'email', 'title' => 'Notify the office', 'post_name' => 'notify-office', 'status' => 'publish', 'content' => \json_encode($content), ...$view];
}

/**
 * A confirmation view.
 *
 * @param  array<string, mixed>  $content  The confirmation's settings
 * @param  array<string, mixed>  $view  Overrides for the view itself
 * @return array<string, mixed> The view
 */
function ffcmConfirmationView(array $content, array $view = []): array
{
    return ['excerpt' => 'on_submit', 'title' => 'Thanks', 'post_name' => 'thanks', 'status' => 'publish', 'content' => \json_encode($content), ...$view];
}

beforeEach(function (): void {
    $this->manager = new FormConfigurationManager;
    $this->importer = $this::createStub(AssetImporter::class);
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearFormConfigContext();
});

it('answers nothing from the hooks Formidable Forms has no use for', function (): void {
    expect(ffcmCall($this->manager, 'processFieldMergeTags', '{1}', ffcmFields()))->toBe('{1}')
        ->and(ffcmCall($this->manager, 'getSubfieldHandleById', '3.1', ffcmFields()))->toBeNull()
        ->and(ffcmCall($this->manager, 'getSubfieldHandleFromMapping', 'name', 'First', []))->toBeNull()
        ->and(ffcmCall($this->manager, 'convertRoutingRulesToStatamicFormat', [['x']], ffcmFields()))->toBe([])
        ->and(ffcmCall($this->manager, 'convertRoutingOperatorToStatamicFormat', '=='))->toBe('==')
        ->and(ffcmCall($this->manager, 'processDateTimeField', 'x', 'y', []))->toBeNull()
        ->and(ffcmCall($this->manager, 'processDatetimeCombination', ['datetime_combination' => []], []))->toBeNull()
        ->and(ffcmCall($this->manager, 'processPluginSpecificField', 'x', 'y', [], 'default'))->toBeNull();
});

it('reads a field name from its name', function (mixed $name, string $expected): void {
    expect(ffcmCall($this->manager, 'getFieldNameFromField', ['name' => $name]))->toBe($expected);
})->with([
    'text' => ['Email', 'Email'],
    'a number' => [5, '5'],
    'a list' => [['x'], ''],
    'nothing' => [null, ''],
]);

it('spreads the JSON options into the form data, and keeps the data when they cannot be read', function (mixed $options, array $expected): void {
    expect(ffcmCall($this->manager, 'parseFormData', ['name' => 'Contact', 'options' => $options]))->toBe($expected);
})->with([
    'options' => ['{"no_save":"1","name":"From options"}', ['name' => 'From options', 'options' => '{"no_save":"1","name":"From options"}', 'no_save' => '1']],
    'not JSON' => ['not json', ['name' => 'Contact', 'options' => 'not json']],
    'a JSON scalar' => ['5', ['name' => 'Contact', 'options' => '5']],
    'not text' => [['no_save' => '1'], ['name' => 'Contact', 'options' => ['no_save' => '1']]],
]);

it('maps the options, storing submissions only when a form turns storage off', function (mixed $noSave, array $expected): void {
    $method = new ReflectionMethod($this->manager, 'mapPluginSpecificFields');
    $data = ['kept' => 1];

    $method->invokeArgs($this->manager, [&$data, ['options' => \json_encode(['no_save' => $noSave, 'honeypot' => 'basic'])], 'default']);

    expect($data)->toBe($expected);
})->with([
    'storage off' => ['1', ['kept' => 1, 'store' => false]],
    'storage on' => ['0', ['kept' => 1]],
    'no setting' => ['', ['kept' => 1]],
]);

it('rewrites the default-message shortcode, with or without attributes', function (): void {
    expect(ffcmCall($this->manager, 'processComplexFormatMappings', 'A [default-message inc_labels="1"] B [default-message x] C'))->toBe('A {{ all_fields }} B {{ all_fields }} C')
        ->and(ffcmCall($this->manager, 'processComplexFormatMappings', 'Plain [default-message]'))->toBe('Plain [default-message]')
        ->and(ffcmCall($this->manager, 'getVariableMappings'))->toBe([
            '[admin_email]' => '{{ admin_email }}',
            '[default-email]' => '{{ admin_email }}',
            '[default-from-email]' => '{{ admin_email }}',
            '[default-message]' => '{{ all_fields }}',
            '[form_name]' => '{{ form:title }}',
            '[sitename]' => '{{ site:name }}',
        ]);
});

it('reports notifications only for a list of views holding at least one', function (mixed $views, bool $expected): void {
    expect(ffcmCall($this->manager, 'hasNotifications', ['views' => $views]))->toBe($expected);
})->with([
    'one' => [[['excerpt' => 'email']], true],
    'none' => [[], false],
    'not a list' => ['x', false],
    'absent' => [null, false],
]);

it('names a view by its post title, then its title, then its excerpt', function (array $view, string $expected): void {
    expect(FormConfigurationManager::resolveViewTitle($view, 'Fallback'))->toBe($expected);
})->with([
    'a post title' => [['post_title' => 'Post', 'title' => 'Title', 'excerpt' => 'email'], 'Post'],
    'an empty post title' => [['post_title' => '', 'title' => 'Title', 'excerpt' => 'email'], 'Title'],
    'a post title that is not text' => [['post_title' => 5, 'title' => 'Title'], 'Title'],
    'only an excerpt' => [['excerpt' => 'email'], 'email'],
    'nothing' => [['title' => ['x']], 'Fallback'],
]);

it('identifies a form for logging by its ID, then its key', function (array $formData, int|string $expected): void {
    expect(ffcmCall($this->manager, 'formIdentifierForLogging', $formData))->toBe($expected);
})->with([
    'an ID' => [['id' => 57, 'form_key' => 'contact'], 57],
    'a text ID' => [['id' => '57', 'form_key' => 'contact'], '57'],
    'an ID that is not a number or text' => [['id' => 5.5, 'form_key' => 'contact'], 'contact'],
    'only a key' => [['form_key' => 'contact'], 'contact'],
    'a numeric key' => [['form_key' => 9], 9],
    'nothing' => [['form_key' => ['x']], '?'],
]);

it('drops a conditional shortcode that tests a known field, and warns about it', function (string $reference): void {
    $text = "Hi[if {$reference} equals=\"x\"], dear[/if {$reference}]!";

    expect(ffcmCall($this->manager, 'dropConditionalShortcodes', $text, ['not a field', ...ffcmFields()]))->toBe('Hi!')
        ->and(ffcmWarnings('forms', 'dropped_conditional_shortcodes'))->toBe([[
            'reference' => $reference,
            'dropped' => "[if {$reference} equals=\"x\"], dear[/if {$reference}]",
            'reason' => 'Formidable conditional shortcodes have no Statamic equivalent; the guarded content is dropped so a blank field cannot leave a dangling separator.',
        ]]);
})->with([
    'by ID' => ['3'],
    'by a numeric ID' => ['4'],
    'by name' => ['Department'],
    'by key' => ['contact_email'],
]);

it('keeps a conditional shortcode it cannot tie to a field, and text without one', function (): void {
    expect(ffcmCall($this->manager, 'dropConditionalShortcodes', 'A[if 9]b[/if 9]c', ffcmFields()))->toBe('A[if 9]b[/if 9]c')
        ->and(ffcmCall($this->manager, 'dropConditionalShortcodes', 'A[if 3]b[/if 4]c', ffcmFields()))->toBe('A[if 3]b[/if 4]c')
        ->and(ffcmCall($this->manager, 'dropConditionalShortcodes', 'No conditions [3]', ffcmFields()))->toBe('No conditions [3]')
        ->and(ffcmCall($this->manager, 'dropConditionalShortcodes', 'Upper [IF 3]x[/if 3]', ffcmFields()))->toBe('Upper [IF 3]x[/if 3]')
        ->and(ffcmWarnings('forms', 'dropped_conditional_shortcodes'))->toBe([]);
});

it('rewrites field references by ID, name and key, and leaves the rest alone', function (): void {
    $fields = [
        'not a field',
        ...ffcmFields(),
        ['id' => ['x'], 'field_key' => 'odd_key', 'name' => 'Odd'],
        ['field_key' => 'keyed_only'],
        ['id' => '6', 'name' => ['not text'], 'field_key' => ['not text']],
        ['id' => '13', 'name' => 14],
        ['id' => '12', 'field_key' => 'int_name', 'name' => 7],
    ];

    expect(ffcmCall($this->manager, 'translateFieldReferences', '[3] [4] [5] [9] [Department] [contact_email] [unnamed_key] [Odd] [keyed_only] [odd_key] [field_name] [unknown] [6] [13] [int_name] [department] [if 3]x[/if 3]', $fields))
        ->toBe('{{ submission:email_address_3 }} {{ submission:department_4 }} [5] [9] {{ submission:department_4 }} {{ submission:email_address_3 }} {{ submission:unnamed_key_5 }} {{ submission:odd_unknown }} {{ submission:keyed_only_unknown }} {{ submission:odd_unknown }} {{ submission:current_field_name }} [unknown] [6] {{ submission:14_13 }} {{ submission:7_12 }} {{ submission:department_4 }} ')
        ->and(ffcmCall($this->manager, 'translateFieldReferences', 'Nothing [3]'))->toBe('Nothing [3]');
});

it('maps a default confirmation by its content only', function (): void {
    $view = ['success_action' => 'message', 'success_msg' => 'Thanks [3]', 'success_url' => '/ignored', 'post_name' => 'thanks', 'post_title' => 'Thanks', 'status' => 'publish'];

    $mapped = ffcmTable(ffcmCall($this->manager, 'processSingleConfirmationView', $view, ['fields' => ffcmFields()], 'default', $this->importer));

    expect(\array_keys($mapped))->toBe(['confirmation_type', 'confirmation_message'])
        ->and($mapped['confirmation_type'])->toBe('message')
        ->and(\json_encode($mapped['confirmation_message']))->toContain('submission:email_address_3');
});

it('maps an additional confirmation with its view fields, settings and conditions', function (): void {
    $view = [
        'excerpt' => 'on_submit',
        'title' => 'Redirect',
        'post_name' => 'redirect-1',
        'status' => 'draft',
        'success_action' => 'redirect',
        'success_url' => 'https://www.uams.edu/',
        'open_in_new_tab' => '1',
        'conditions' => ['send_stop' => 'send', 'any_all' => 'all', 0 => ['hide_field' => '4', 'hide_field_cond' => '==', 'hide_opt' => 'pharmacy']],
    ];

    $mapped = ffcmTable(ffcmCall($this->manager, 'processSingleConfirmationView', $view, ['fields' => ffcmFields()], 'default', null, true));

    expect(\array_diff_key($mapped, ['conditional_logic' => true]))->toBe([
        'confirmation_name' => 'Redirect',
        'enabled' => false,
        'id' => 'redirect-1',
        'confirmation_settings' => [
            'confirmation_type' => 'redirect',
            'confirmation_redirect_url' => 'https://www.uams.edu/',
            'confirmation_redirect_target' => '1',
        ],
        'type' => 'confirmation',
    ])->and($mapped['conditional_logic'] ?? null)->not->toBeEmpty();
});

it('names an action only from its title, never from a post title', function (): void {
    // The view-level email mapping is the path that reads the title, so a post title
    // read there would surface as the email's name.
    $form = ffcmForm('frm_contact');

    ffcmCall($this->manager, 'mapConfirmationFields', $form, ['views' => [
        ffcmEmailView(['email_to' => 'a@uams.edu'], ['title' => null, 'post_title' => 'A post title']),
    ]], 'default', $this->importer);

    $data = ffcmFormData($form);
    $email = ffcmTable(ffcmTable($data['email'] ?? null)[0] ?? null);

    expect($email['to'] ?? null)->toBe('a@uams.edu')
        ->and(\array_key_exists('name', $email))->toBeFalse()
        ->and(ffcmCall($this->manager, 'processSingleConfirmationView', ffcmConfirmationView(['success_action' => 'message'], ['title' => null, 'post_title' => 'A post title']), [], 'default', null, true))
        ->not->toHaveKey('confirmation_name');
});

it('maps an empty default confirmation to nothing, and an empty additional one to its type', function (): void {
    expect(ffcmCall($this->manager, 'processSingleConfirmationView', [], [], 'default'))->toBeNull()
        ->and(ffcmCall($this->manager, 'processSingleConfirmationView', ['conditions' => 'x'], [], 'default', null, true))->toBe(['type' => 'confirmation']);
});

it('maps each email action view to an email configuration, with its context while it is mapped', function (): void {
    $manager = new class extends FormConfigurationManager
    {
        /**
         * The contexts seen while building each configuration.
         *
         * @var list<mixed>
         */
        public array $seen = [];

        /**
         * Record the context, then build as the manager does.
         *
         * @param  array<int|string, mixed>  $processedData  The view data
         * @param  array<int|string, mixed>  $formData  The form data
         * @param  string  $siteHandle  The site handle
         * @param  ?AssetImporter  $assetImporter  The asset importer
         * @param  array<int|string, mixed>  $formFields  The form fields
         * @return array<int|string, mixed> The configuration
         */
        protected function buildEmailConfiguration(array $processedData, array $formData, string $siteHandle, $assetImporter = null, array $formFields = []): array
        {
            $this->seen[] = ProcessingContext::getFormConfigContext();

            return parent::buildEmailConfiguration($processedData, $formData, $siteHandle, $assetImporter, $formFields);
        }
    };

    $configs = ffcmTable(ffcmCall($manager, 'mapNotificationsToEmailConfigurations', [
        'id' => '57',
        'fields' => ffcmFields(),
        'views' => [
            'not a view',
            ffcmConfirmationView(['success_action' => 'message']),
            ['excerpt' => ['email']],
            ffcmEmailView([
                'email_to' => ' [3], office@uams.edu ',
                'email_subject' => '  New [Department] entry  ',
                'from' => 'Office <office@uams.edu>',
                'conditions' => ['send_stop' => 'stop', 'any_all' => 'all', 0 => ['hide_field' => '4', 'hide_field_cond' => '==', 'hide_opt' => 'pharmacy']],
            ]),
            ffcmEmailView(['email_to' => 'b@uams.edu'], ['title' => '', 'post_name' => 'second', 'content' => 'not json']),
        ],
    ], 'default'));

    $first = ffcmTable($configs[0] ?? null);
    $second = ffcmTable($configs[1] ?? null);

    expect(\count($configs))->toBe(2)
        // The view's title is the notification's name, and an empty title leaves none
        ->and($first['name'] ?? null)->toBe('Notify the office')
        ->and(\array_key_exists('name', $second))->toBeFalse()
        ->and(\array_intersect_key($first, \array_flip(['enabled', 'id', 'to', 'subject', 'from', 'from_name', 'to_type'])))->toBe([
            'enabled' => true,
            'id' => 'notify-office',
            'subject' => 'New {{ submission:department_4 }} entry',
            'to' => '{{ submission:email_address_3 }},office@uams.edu',
            'to_type' => 'email',
            'from' => 'office@uams.edu',
            'from_name' => 'Office',
        ])
        ->and($first['conditional_logic'] ?? null)->toHaveKey('unless')
        ->and(\array_intersect_key($second, \array_flip(['id', 'to'])))->toBe(['id' => 'second'])
        ->and(\array_key_exists('conditional_logic', $second))->toBeFalse()
        ->and($manager->seen)->toBe([
            ['form_id' => '57', 'source' => 'view', 'source_name' => 'Notify the office', 'plugin' => 'Formidable Forms'],
            ['form_id' => '57', 'source' => 'view', 'source_name' => 'email', 'plugin' => 'Formidable Forms'],
        ])
        ->and(ProcessingContext::getFormConfigContext())->toBeNull();
});

it('imports an email view with no usable title, with no name', function (array $view): void {
    $base = ['excerpt' => 'email', 'post_name' => 'untitled', 'status' => 'publish', 'content' => \json_encode(['email_to' => 'a@uams.edu'])];

    $configs = ffcmTable(ffcmCall($this->manager, 'mapNotificationsToEmailConfigurations', [
        'form_id' => '57',
        'views' => [[...$base, ...$view]],
    ], 'default'));
    $config = ffcmTable($configs[0] ?? null);

    expect(\count($configs))->toBe(1)
        ->and(\array_key_exists('name', $config))->toBeFalse()
        ->and($config['id'] ?? null)->toBe('untitled')
        ->and($config['to'] ?? null)->toBe('a@uams.edu');
})->with([
    'absent' => [[]],
    'empty' => [['title' => '']],
    'not a string' => [['title' => ['x']]],
]);

it('maps no email configuration from views it cannot read', function (mixed $views): void {
    expect(ffcmCall($this->manager, 'mapNotificationsToEmailConfigurations', ['views' => $views], 'default'))->toBe([]);
})->with([
    'not a list' => ['x'],
    'absent' => [null],
    'no email views' => [[ffcmConfirmationView([])]],
]);

it('maps the default and additional confirmations and the email actions onto the form', function (): void {
    $form = ffcmForm('frm_contact');
    $form->merge(['kept' => 'yes']);

    ffcmCall($this->manager, 'mapConfirmationFields', $form, [
        'form_key' => 'contact',
        'fields' => ffcmFields(),
        'views' => [
            'not a view',
            ['excerpt' => 'unknown'],
            ['excerpt' => ['on_submit']],
            ['post_title' => 'No excerpt'],
            ffcmConfirmationView(['success_action' => 'message', 'success_msg' => 'Thanks']),
            ffcmEmailView(['email_to' => 'office@uams.edu']),
            ffcmConfirmationView(['success_action' => 'redirect', 'success_url' => '/next'], ['title' => 'Next', 'post_name' => 'next']),
        ],
    ], 'default', $this->importer);

    $data = ffcmFormData($form);
    $email = ffcmTable($data['email'] ?? null);
    $first = ffcmTable($email[0] ?? null);

    expect(\array_keys($data))->toBe(['kept', 'confirmation_settings_default', 'confirmation_additional', 'email'])
        ->and(ffcmTable($data['confirmation_settings_default'])['confirmation_type'] ?? null)->toBe('message')
        ->and($data['confirmation_additional'])->toBe([[
            'confirmation_name' => 'Next',
            'enabled' => true,
            'id' => 'next',
            'confirmation_settings' => ['confirmation_type' => 'redirect', 'confirmation_redirect_url' => '/next'],
            'type' => 'confirmation',
        ]])
        ->and(\count($email))->toBe(1)
        ->and(\array_intersect_key($first, \array_flip(['name', 'enabled', 'id', 'to', 'to_type'])))->toBe(['name' => 'Notify the office', 'enabled' => true, 'id' => 'notify-office', 'to' => 'office@uams.edu', 'to_type' => 'email'])
        ->and(ProcessingContext::getFormConfigContext())->toBeNull();
});

it('reads a view without content, or with content that is not text, as having no settings', function (): void {
    $form = ffcmForm('frm_contact');

    ffcmCall($this->manager, 'mapConfirmationFields', $form, ['views' => [
        ['excerpt' => 'on_submit', 'title' => 'Bare'],
        ['excerpt' => 'on_submit', 'title' => 'Listed', 'content' => ['success_action' => 'message']],
        ['excerpt' => 'email', 'title' => 'Bare email', 'post_name' => 'bare'],
        ['excerpt' => 'email', 'title' => 'Listed email', 'post_name' => 'listed', 'content' => ['email_to' => 'x@uams.edu']],
    ]], 'default');
    $data = ffcmFormData($form);
    $configs = ffcmCall($this->manager, 'mapNotificationsToEmailConfigurations', ['views' => [
        ['excerpt' => 'email', 'post_name' => 'bare'],
        ['excerpt' => 'email', 'post_name' => 'listed', 'content' => ['email_to' => 'x@uams.edu']],
    ]], 'default');

    expect(\array_key_exists('confirmation_settings_default', $data))->toBeFalse()
        ->and($data['confirmation_additional'] ?? null)->toBe([['confirmation_name' => 'Listed', 'type' => 'confirmation']])
        ->and(\array_column(ffcmTable($data['email'] ?? null), 'id'))->toBe(['bare', 'listed'])
        ->and(\array_column(ffcmTable($data['email'] ?? null), 'to'))->toBe([])
        ->and(\array_column(ffcmTable($configs), 'to'))->toBe([]);
});

it('adds email actions to those the form already holds, with their conditions and a generated ID', function (): void {
    $form = ffcmForm('frm_contact');
    $form->merge(['email' => [['id' => 'earlier']]]);

    ffcmCall($this->manager, 'mapConfirmationFields', $form, ['fields' => ffcmFields(), 'views' => [
        ffcmEmailView([
            'email_to' => 'office@uams.edu',
            'conditions' => ['send_stop' => 'send', 'any_all' => 'all', 0 => ['hide_field' => '4', 'hide_field_cond' => '==', 'hide_opt' => 'pharmacy']],
        ], ['post_name' => null]),
    ]], 'default');

    $email = ffcmTable(ffcmFormData($form)['email'] ?? null);
    $added = ffcmTable($email[1] ?? null);

    expect(\count($email))->toBe(2)
        ->and($email[0] ?? null)->toBe(['id' => 'earlier'])
        ->and($added['id'] ?? null)->toBeString()->toHaveLength(8)
        ->and($added['conditional_logic'] ?? null)->toHaveKey('if');
});

it('maps email actions onto a form without fields, replacing an email value that is not a list', function (): void {
    $form = ffcmForm('frm_contact');
    $form->merge(['email' => 'not a list']);

    ffcmCall($this->manager, 'mapConfirmationFields', $form, ['views' => [
        ffcmEmailView([
            'email_to' => '[3]',
            'conditions' => ['send_stop' => 'send', 'any_all' => 'all', 0 => ['hide_field' => '4', 'hide_field_cond' => '==', 'hide_opt' => 'x']],
        ]),
    ]], 'default');

    $email = ffcmTable(ffcmFormData($form)['email'] ?? null);
    $first = ffcmTable($email[0] ?? null);

    // Without fields there is nothing to resolve a reference or a condition against.
    expect(\count($email))->toBe(1)
        ->and($first['to'] ?? null)->toBe('[3]')
        ->and(\array_key_exists('conditional_logic', $first))->toBeFalse();
});

it('maps no confirmation or email action onto a form without views', function (mixed $views): void {
    $form = ffcmForm('frm_contact');

    ffcmCall($this->manager, 'mapConfirmationFields', $form, ['views' => $views], 'default');

    expect(ffcmFormData($form))->toBe([]);
})->with([
    'none' => [[]],
    'not a list' => ['x'],
    'absent' => [null],
]);

it('identifies each confirmation being mapped while it is mapped', function (): void {
    $manager = new class extends FormConfigurationManager
    {
        /**
         * The contexts seen while mapping each confirmation view.
         *
         * @var list<mixed>
         */
        public array $seen = [];

        /**
         * Record the context, then map as the manager does.
         *
         * @param  array<int|string, mixed>  $processedViewData  The view data
         * @param  array<int|string, mixed>  $formData  The form data
         * @param  string  $siteHandle  The site handle
         * @param  ?AssetImporter  $assetImporter  The asset importer
         * @param  bool  $isAdditional  Whether it is an additional confirmation
         * @return ?array<int|string, mixed> The mapped data
         */
        protected function processSingleConfirmationView(array $processedViewData, array $formData, string $siteHandle, $assetImporter = null, bool $isAdditional = false): ?array
        {
            $this->seen[] = ProcessingContext::getFormConfigContext();

            return parent::processSingleConfirmationView($processedViewData, $formData, $siteHandle, $assetImporter, $isAdditional);
        }
    };

    ffcmCall($manager, 'mapConfirmationFields', ffcmForm('frm_contact'), ['id' => 57, 'views' => [
        ffcmConfirmationView([], ['title' => 'First']),
        ffcmConfirmationView([], ['title' => '']),
    ]], 'default');

    expect($manager->seen)->toBe([
        ['form_id' => 57, 'source' => 'confirmation', 'source_name' => 'First', 'plugin' => 'Formidable Forms'],
        ['form_id' => 57, 'source' => 'confirmation', 'source_name' => 'on_submit', 'plugin' => 'Formidable Forms'],
    ])->and(ProcessingContext::getFormConfigContext())->toBeNull();
});

it('configures and saves a form from its settings, options and views', function (): void {
    $form = ffcmForm('frm_saved');

    $saved = $this->manager->configureFormEmailSettings($form, [
        'id' => '57',
        'name' => 'Contact Us',
        'description' => '<p>Write to <b>us</b></p>',
        'options' => \json_encode(['no_save' => '1']),
        'fields' => ffcmFields(),
        'views' => [
            ffcmConfirmationView(['success_action' => 'message', 'success_msg' => 'Thanks']),
            ffcmEmailView(['email_to' => 'office@uams.edu']),
        ],
    ], 'default', $this->importer);

    $data = ffcmFormData($form);
    $email = $form->email();

    expect($saved)->toBeTrue()
        ->and(\array_intersect_key($data, \array_flip(['title', 'heading', 'description', 'store'])))->toBe(['title' => 'Contact Us', 'heading' => 'Contact Us', 'description' => 'Write to us', 'store' => false])
        ->and(\is_array($email) ? \array_column($email, 'to') : null)->toBe(['office@uams.edu']);
});
