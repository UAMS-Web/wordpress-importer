<?php

declare(strict_types=1);

/**
 * The shared form configuration manager trait, driven through probes (#98).
 *
 * The three plugin managers reach the trait only with their own mapping tables, so most of
 * its value processing, its fallbacks and its conditional mappings had never run.
 * `FcmTraitProbe` uses the trait with every plugin hook answering in a recognizable way, and
 * declares no mapping constants. `FcmTraitMappedProbe` declares them, with an entry for each
 * shape the trait handles; `FcmTraitPlainToProbe` declares an email mapping without `toType`,
 * as Ninja Forms does.
 *
 * @command  php vendor/bin/pest tests/Integration/FormConfigurationManagerTraitTest.php
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormConfigurationManagerTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A class using the trait, with each plugin hook answering in a way a test can recognize.
 */
class FcmTraitProbe
{
    use FormConfigurationManagerTrait;

    /**
     * The hooks called, in order, with what they were given.
     *
     * @var list<array<int, mixed>>
     */
    public array $calls = [];

    /**
     * Record a confirmation mapping.
     *
     * @param  Form  $form  The form
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer
     */
    protected function mapConfirmationFields(Form $form, array $formData, string $siteHandle, $assetImporter = null): void
    {
        $this->calls[] = ['mapConfirmationFields', $form->handle(), $siteHandle];
    }

    /**
     * Answer the form data's `parsed` entry when it has one, otherwise the form data.
     *
     * @param  array<int|string, mixed>  $formData  The form data
     * @return array<int|string, mixed> The parsed data
     */
    protected function parseFormData(array $formData): array
    {
        return isset($formData['parsed']) && \is_array($formData['parsed']) ? $formData['parsed'] : $formData;
    }

    /**
     * Mark the form data as having had its plugin-specific fields mapped.
     *
     * @param  array<int|string, mixed>  $currentFormData  The form data being built
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer
     */
    protected function mapPluginSpecificFields(array &$currentFormData, array $formData, string $siteHandle, $assetImporter = null): void
    {
        $currentFormData['plugin_specific'] = $siteHandle;
    }

    /**
     * Process no single confirmation view.
     *
     * @param  array<int|string, mixed>  $processedViewData  The view data
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer
     * @param  bool  $isAdditional  Whether the view is an additional confirmation
     * @return ?array<int|string, mixed> Nothing
     */
    protected function processSingleConfirmationView(array $processedViewData, array $formData, string $siteHandle, $assetImporter = null, bool $isAdditional = false): ?array
    {
        return null;
    }

    /**
     * One variable mapping.
     *
     * @return array<string, string> WordPress variable => Statamic variable
     */
    protected function getVariableMappings(): array
    {
        return ['{site_name}' => '{{ site:name }}'];
    }

    /**
     * Prefix the value of the `special` attribute, and leave every other one alone.
     *
     * @param  string  $statamicKey  The Statamic attribute
     * @param  mixed  $value  The value
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer
     * @return mixed The prefixed value, or null
     */
    protected function processPluginSpecificField(string $statamicKey, $value, array $formData, string $siteHandle, $assetImporter = null): mixed
    {
        return $statamicKey === 'special' && \is_string($value) ? "special:{$value}" : null;
    }

    /**
     * Replace the `{complex}` marker.
     *
     * @param  string  $value  The value
     * @return string The value with the marker replaced
     */
    protected function processComplexFormatMappings(string $value): string
    {
        return \str_replace('{complex}', '[complex]', $value);
    }

    /**
     * Whether the form data carries notifications.
     *
     * @param  array<int|string, mixed>  $formData  The form data
     * @return bool Whether it does
     */
    protected function hasNotifications(array $formData): bool
    {
        return isset($formData['notifications']) && $formData['notifications'] !== [];
    }

    /**
     * Answer the form data's notifications as its email configurations.
     *
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  string  $siteHandle  The site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer
     * @return array<int|string, mixed> The email configurations
     */
    protected function mapNotificationsToEmailConfigurations(array $formData, string $siteHandle, $assetImporter = null): array
    {
        return isset($formData['notifications']) && \is_array($formData['notifications']) ? $formData['notifications'] : [];
    }

    /**
     * Process no date and time field.
     *
     * @param  string  $statamicKey  The Statamic attribute
     * @param  mixed  $value  The value
     * @param  array<int|string, mixed>  $formData  The form data
     * @return ?string Nothing
     */
    protected function processDateTimeField(string $statamicKey, $value, array $formData): ?string
    {
        return null;
    }

    /**
     * Combine the configured date and time parts.
     *
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration
     * @param  array<int|string, mixed>  $formData  The form data
     * @return ?string The combination
     */
    protected function processDatetimeCombination(array $mappingConfig, array $formData): ?string
    {
        return 'combined:'.\json_encode($mappingConfig['datetime_combination'] ?? null);
    }

    /**
     * Replace the `{field}` marker with the number of form fields.
     *
     * @param  string  $value  The value
     * @param  array<int|string, mixed>  $formFields  The form fields
     * @return string The value with the marker replaced
     */
    protected function processFieldMergeTags(string $value, array $formFields): string
    {
        return \str_replace('{field}', '[field:'.\count($formFields).']', $value);
    }

    /**
     * A field's label.
     *
     * @param  array<int|string, mixed>  $field  The field
     * @return string The label
     */
    protected function getFieldNameFromField(array $field): string
    {
        return isset($field['label']) && \is_string($field['label']) ? $field['label'] : '';
    }

    /**
     * A subfield handle made from its ID.
     *
     * @param  string  $subfieldId  The subfield ID
     * @param  array<int|string, mixed>  $formFields  The form fields
     * @return ?string The handle
     */
    protected function getSubfieldHandleById(string $subfieldId, array $formFields): ?string
    {
        return 'sub_'.\str_replace('.', '_', $subfieldId);
    }

    /**
     * Resolve no subfield handle from a mapping.
     *
     * @param  string  $fieldType  The field type
     * @param  string  $subfieldLabel  The subfield label
     * @param  array<int|string, mixed>  $subfieldMapping  The subfield mapping
     * @return ?string Nothing
     */
    protected function getSubfieldHandleFromMapping(string $fieldType, string $subfieldLabel, array $subfieldMapping): ?string
    {
        return null;
    }

    /**
     * Wrap the routing rules, so a test can see they were converted.
     *
     * @param  array<int|string, mixed>  $routingRules  The routing rules
     * @param  array<int|string, mixed>  $formFields  The form fields
     * @return array<int|string, mixed> The wrapped rules
     */
    protected function convertRoutingRulesToStatamicFormat(array $routingRules, array $formFields): array
    {
        return ['converted' => $routingRules];
    }

    /**
     * Answer the operator unchanged.
     *
     * @param  string  $operator  The operator
     * @return string The operator
     */
    protected function convertRoutingOperatorToStatamicFormat(string $operator): string
    {
        return $operator;
    }

    /**
     * Prefix the text, so a test can see it was translated.
     *
     * @param  string  $text  The text
     * @param  array<int|string, mixed>  $allFields  The form fields
     * @return string The prefixed text
     */
    protected function translateFieldReferences(string $text, array $allFields = []): string
    {
        return 'translated('.\count($allFields).'):'.$text;
    }
}

/**
 * The probe with a mapping constant for each table the trait reads, holding every shape.
 */
class FcmTraitMappedProbe extends FcmTraitProbe
{
    /**
     * The form settings mapping.
     *
     * @var array<string, mixed>
     */
    public const FORM_CONFIGURATION_MAPPING = [
        'numbered_config' => 5,
        'plain' => 'plain_target',
        'odd_conditional' => ['statamic_attribute' => ['based_on_other_attribute_value' => 'not a table']],
        'title' => ['statamic_attribute' => 'title'],
        'settings.honeypot' => ['statamic_attribute' => 'honeypot', 'as_boolean' => true],
        'schedule.start' => ['statamic_attribute' => 'schedule_form.start_datetime'],
        'special_value' => ['statamic_attribute' => 'special'],
        'empty_flag' => ['statamic_attribute' => 'empty_flag', 'value_translation' => ['#empty' => 'was empty']],
        'cleared' => ['statamic_attribute' => 'cleared', 'value_translation' => ['#empty' => null]],
        'cleared_nested' => ['statamic_attribute' => 'outer.cleared', 'value_translation' => ['#empty' => null]],
        'absent' => ['statamic_attribute' => 'absent'],
        'confirmation' => ['statamic_attribute' => ['based_on_other_attribute_value' => ['confirmation_type' => ['redirect' => 'redirect_url', 'message' => 'message_text']]]],
        'unmatched' => ['statamic_attribute' => ['based_on_other_attribute_value' => ['missing' => ['x' => 'y']]]],
        'no_attribute' => ['as_boolean' => true],
        'store' => ['statamic_attribute' => 'store', 'statamic_other_field_value' => ['store_mode' => 'always']],
        'spread' => ['statamic_attribute' => ['multiple' => [
            'numbered' => ['statamic_attribute' => '5'],
            'spread_copy' => 'any',
            'ignored' => ['statamic_attribute' => 'spread_nested.upper', 'value_translation' => ['b' => 'B']],
            'dropped' => ['statamic_attribute' => 'dropped', 'value_translation' => ['b' => null]],
            'unusable' => ['as_boolean' => true],
        ]]],
        'spread_empty' => ['statamic_attribute' => ['multiple' => ['never' => 'any']]],
        'spread_translated' => ['statamic_attribute' => ['multiple' => ['spread_translated_copy' => 'any']], 'value_translation' => ['#empty' => 'none']],
    ];

    /**
     * The email notification mapping.
     *
     * @var array<string, mixed>
     */
    public const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL = [
        'no_attribute' => ['trim' => true],
        'listed_attribute' => ['statamic_attribute' => ['not', 'a name']],
        'toType' => ['statamic_attribute' => 'to_type'],
        'to' => ['statamic_attribute' => 'to'],
        'toField' => ['statamic_attribute' => 'to_field'],
        'fromField' => ['statamic_attribute' => 'from', 'field_id_to_submission_tag' => true],
        'routing' => ['statamic_attribute' => 'routing'],
        'isActive' => ['statamic_attribute' => 'enabled', 'value_translation' => ['#empty' => 'unused']],
        'subject' => ['statamic_attribute' => 'subject'],
        'special_value' => ['statamic_attribute' => 'special'],
        'attachments' => ['statamic_attribute' => 'attachments'],
        'plain' => 'not a complex mapping',
        'note' => ['statamic_attribute' => 'note', 'value_translation' => ['#empty' => 'none']],
        'cleared' => ['statamic_attribute' => 'cleared', 'value_translation' => ['#empty' => null]],
        'html' => ['statamic_attribute' => 'html', 'statamic_other_field_value' => ['markdown' => false]],
    ];

    /**
     * The email content mapping.
     *
     * @var array<string, mixed>
     */
    public const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL_CONTENT = [
        'skipped' => null,
        'body' => 'message',
        'html_body' => ['statamic_attribute' => 'html', 'statamic_other_field_value' => ['format' => 'html']],
        'no_attribute' => ['trim' => true],
        'numbered' => 5,
        'count' => 'count',
        'spread' => ['statamic_attribute' => ['multiple' => [
            'text' => 'any',
            'copy' => ['statamic_attribute' => 'copy', 'value_translation' => ['dropped' => null]],
        ]], 'statamic_other_field_value' => ['spread_done' => true]],
        'after_spread' => 'after',
    ];

    /**
     * A mapping constant that is not a table.
     */
    public const NOT_A_TABLE = 'not a table';
}

/**
 * The probe with an email mapping that carries no `toType`, as Ninja Forms' does.
 */
class FcmTraitPlainToProbe extends FcmTraitProbe
{
    /**
     * The email notification mapping.
     *
     * @var array<string, mixed>
     */
    public const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL = [
        'to' => ['statamic_attribute' => 'to'],
    ];
}

/**
 * Call one of the trait's methods on a probe, whatever its visibility.
 *
 * @param  object  $probe  The probe
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments
 * @return mixed What the method returned
 */
function fcmCall(object $probe, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($probe, $method))->invoke($probe, ...$arguments);
}

/**
 * Make a Statamic form.
 *
 * @param  string  $handle  The form handle
 * @return Form The form
 */
function fcmForm(string $handle): Form
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
 * @return mixed The data
 */
function fcmFormData(Form $form): mixed
{
    $data = $form->data();

    return $data instanceof Collection ? $data->all() : null;
}

/**
 * Narrow a result a test knows is a table.
 *
 * @param  mixed  $value  The result
 * @return array<int|string, mixed> The table
 */
function fcmTable(mixed $value): array
{
    if (! \is_array($value)) {
        throw new LogicException('Expected a table, got '.\get_debug_type($value).'.');
    }

    return $value;
}

/**
 * Collect every warning logged from now on.
 *
 * @return ArrayObject<int, array{0: string, 1: array<int|string, mixed>}> The warnings, filled as they are logged
 */
function fcmWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{0: string, 1: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(static function (string $message, array $context) use ($warnings): void {
        $warnings[] = [$message, $context];
    });

    return $warnings;
}

beforeEach(function (): void {
    $this->probe = new FcmTraitProbe;
    $this->mapped = new FcmTraitMappedProbe;
    $this->importer = $this::createStub(AssetImporter::class);
});

afterEach(function (): void {
    ProcessingContext::clearFormConfigContext();
});

it('keeps the page data mapping it is given', function (): void {
    $this->probe->setPageDataMapping([12 => ['entry' => 'abc']]);

    expect($this->probe->getPageDataMapping())->toBe([12 => ['entry' => 'abc']]);
});

it('converts a value to a boolean, inverting it when asked', function (mixed $value, bool $expected): void {
    expect(fcmCall($this->probe, 'processMappingConfiguration', $value, ['as_boolean' => true], [], 'default'))->toBe($expected)
        ->and(fcmCall($this->probe, 'processMappingConfiguration', $value, ['as_boolean' => true, 'invert_boolean' => true], [], 'default'))->toBe(! $expected);
})->with([
    'yes' => [' Yes ', true],
    'on' => ['on', true],
    'true' => ['TRUE', true],
    'one' => ['1', true],
    'off' => ['off', false],
    'no' => ['no', false],
    'false' => ['false', false],
    'zero' => ['0', false],
    'a number' => [2, true],
    'a zero' => [0, false],
    'a numeric string' => ['2.5', true],
    'a boolean' => [true, true],
    'a false boolean' => [false, false],
    'other text' => ['maybe', false],
    'a list' => [['x'], false],
    'a padded zero' => [' 0 ', false],
    'a padded one' => [' 1 ', true],
    'a zero with a decimal' => ['0.0', true],
    // Judged by its trimmed text, as convertToInteger() judges it.
    'a number ending in a null byte' => ["2\0", true],
]);

it('leaves the boolean alone when inversion is not exactly true', function (): void {
    expect(fcmCall($this->probe, 'processMappingConfiguration', 'yes', ['as_boolean' => true, 'invert_boolean' => 1], [], 'default'))->toBeTrue()
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'yes', ['as_boolean' => 1], [], 'default'))->toBe('yes');
});

it('converts a value to an integer only when it is a whole number', function (mixed $value, ?int $expected): void {
    expect(fcmCall($this->probe, 'processMappingConfiguration', $value, ['as_integer' => true], [], 'default'))->toBe($expected);
})->with([
    'a padded string' => [' 42 ', 42],
    'a float string' => ['4.0', 4],
    'a fractional string' => ['4.5', null],
    'text' => ['four', null],
    'an integer' => [7, 7],
    'a whole float' => [7.0, 7],
    'a fraction' => [7.5, null],
    'a boolean' => [true, null],
    'text ending in a null byte' => ["42\0", 42],
]);

it('translates a value by key, by its boolean spelling, or through its default table', function (mixed $value, array $translation, mixed $expected): void {
    expect(fcmCall($this->probe, 'processMappingConfiguration', $value, ['value_translation' => $translation], [], 'default'))->toBe($expected);
})->with([
    'a string key' => ['a', ['a' => 'A'], 'A'],
    'an integer key' => [3, [3 => 'three'], 'three'],
    'a true boolean' => [true, ['true' => 'yes'], 'yes'],
    'a false boolean' => [false, ['false' => 'no', '#empty' => 'empty'], 'empty'],
    'a false boolean without an empty entry' => [false, ['false' => 'no'], 'no'],
    'no match' => ['z', ['a' => 'A'], 'z'],
    'a boolean with no match' => [true, ['false' => 'no'], true],
    'a list' => [['x'], ['a' => 'A'], ['x']],
    'the default table' => ['a', ['default' => ['a' => 'from default']], 'from default'],
    'the default table, integer key' => [3, ['default' => [3 => 'three']], 'three'],
    'the default table by boolean' => [true, ['default' => ['true' => 'on']], 'on'],
    'the default table with no match' => ['z', ['default' => ['a' => 'A']], 'z'],
    'the default table with a boolean and no match' => [true, ['default' => ['false' => 'off']], true],
    'only a specific table' => ['a', ['specific' => ['a' => 'from specific']], 'a'],
    'a default that is not a table' => ['a', ['default' => 'x', 'specific' => 'y'], 'a'],
]);

it('translates every empty value through the #empty entry', function (mixed $value): void {
    expect(fcmCall($this->probe, 'processMappingConfiguration', $value, ['value_translation' => ['#empty' => 'none']], [], 'default'))->toBe('none');
})->with([
    'null' => [null],
    'blank text' => ['  '],
    'an empty list' => [[]],
    'false' => [false],
    'zero' => [0],
    'a zero string' => ['0'],
]);

it('leaves a value that is not empty for the rest of the translation table', function (mixed $value): void {
    expect(fcmCall($this->probe, 'processMappingConfiguration', $value, ['value_translation' => ['#empty' => 'none']], [], 'default'))->toBe($value);
})->with([
    'text' => ['x'],
    'a list' => [['x']],
    'true' => [true],
    'one' => [1],
]);

it('applies each usable substitution in order, and only to text', function (): void {
    $config = ['value_substitution_regex' => ['/a/' => 'b', '/b+/' => 'c', 5 => 'x', '/c/' => ['not text'], '/c$/' => 9]];

    expect(fcmCall($this->probe, 'processMappingConfiguration', 'aab', $config, [], 'default'))->toBe('9')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 7, $config, [], 'default'))->toBe(7)
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'aab', ['value_substitution_regex' => 'not a table'], [], 'default'))->toBe('aab');
});

it('translates field references, strips markup, trims and unescapes only text, and only when asked', function (): void {
    $fields = [['id' => 1], ['id' => 2]];

    expect(fcmCall($this->probe, 'processMappingConfiguration', 'x', ['translate_field_references' => true], [], 'default', null, $fields))->toBe('translated(2):x')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 3, ['translate_field_references' => true], [], 'default'))->toBe(3)
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'x', ['translate_field_references' => 1], [], 'default'))->toBe('x')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '<b>Bold</b> text', ['strip_html' => true], [], 'default'))->toBe('Bold text')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '<b>x</b>', ['strip_html' => 1], [], 'default'))->toBe('<b>x</b>')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 3, ['strip_html' => true], [], 'default'))->toBe(3)
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '  x  ', ['trim' => true], [], 'default'))->toBe('x')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '  x  ', ['trim' => 1], [], 'default'))->toBe('  x  ')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 3, ['trim' => true], [], 'default'))->toBe(3)
        ->and(fcmCall($this->probe, 'processMappingConfiguration', ' a , ,b ,', ['trim_comma_separated_values' => true], [], 'default'))->toBe('a,b')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', ' a , b', ['trim_comma_separated_values' => 1], [], 'default'))->toBe(' a , b')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 3, ['trim_comma_separated_values' => true], [], 'default'))->toBe(3)
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'a\/b', ['unescape_slashes' => true], [], 'default'))->toBe('a/b')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'a\/b', ['unescape_slashes' => 1], [], 'default'))->toBe('a\/b')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 3, ['unescape_slashes' => true], [], 'default'))->toBe(3)
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'x', 'a string configuration', [], 'default'))->toBe('x');
});

it('answers a date and time combination before any other processing', function (): void {
    expect(fcmCall($this->probe, 'processMappingConfiguration', 'ignored', ['datetime_combination' => ['date' => 'd'], 'as_boolean' => true], [], 'default'))->toBe('combined:{"date":"d"}')
        ->and(fcmCall($this->probe, 'hasDatetimeCombination', ['datetime_combination' => 'not a table']))->toBeFalse();
});

it('turns text into Bard content, and blank text into none', function (): void {
    $bard = fcmCall($this->probe, 'processMappingConfiguration', "Hello {site_name}\n\nAgain", ['as_bard' => true], [], 'default', $this->importer, [['id' => 1]]);

    expect(\json_encode($bard))->toContain('Hello {{ site:name }}')->toContain('Again')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '   ', ['as_bard' => true], [], 'default', $this->importer))->toBe([])
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '   ', ['as_bard' => true], [], 'default'))->toBe([])
        // The text is not run through a second paragraph pass, whose byte-wise decoding would
        // turn an entity escaped twice into a control character.
        ->and(\json_encode(fcmCall($this->probe, 'processMappingConfiguration', 'It&amp;#8217;s fine', ['as_bard' => true], [], 'default', $this->importer), \JSON_UNESCAPED_UNICODE))->toContain("It\u{2019}s fine")
        // A single line break is kept as a hard break.
        ->and(\json_encode(fcmCall($this->probe, 'processMappingConfiguration', "One\nTwo", ['as_bard' => true], [], 'default', $this->importer)))->toContain('hardBreak')
        // Without an asset importer the text is left as it was.
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'Hello', ['as_bard' => true], [], 'default'))->toBe('Hello')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 'Hello', ['as_bard' => 1], [], 'default', $this->importer))->toBe('Hello')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 3, ['as_bard' => true], [], 'default', $this->importer))->toBe(3);
});

it('maps a page reference to its entry, and to a placeholder it warns about otherwise', function (): void {
    $warnings = fcmWarnings();
    $this->probe->setPageDataMapping(['12' => ['entry' => 'entry-12'], '13' => ['entry' => ['not scalar']], '14' => 'not a table', '16' => ['entry' => 55], '17' => ['other' => 1]]);
    $config = ['translate_page_references' => true];

    expect(fcmCall($this->probe, 'processMappingConfiguration', 12, $config, [], 'default'))->toBe('entry-12')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '12', $config, [], 'default'))->toBe('entry-12')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 13, $config, ['id' => 9], 'default'))->toBe('default_wordpress_page_id_13')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 14, $config, [], 'default'))->toBe('default_wordpress_page_id_14')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 15, $config, [], 'default'))->toBe('default_wordpress_page_id_15')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', ['x'], $config, [], 'default'))->toBe('default_wordpress_page_id_unknown')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 16, $config, [], 'default'))->toBe('55')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 17, $config, [], 'default'))->toBe('default_wordpress_page_id_17')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', true, $config, [], 'default'))->toBe('default_wordpress_page_id_1')
        // Nothing to map, and a mapping that was not asked for.
        ->and(fcmCall($this->probe, 'processMappingConfiguration', '', $config, [], 'default'))->toBe('')
        ->and(fcmCall($this->probe, 'processMappingConfiguration', null, $config, [], 'default'))->toBeNull()
        ->and(fcmCall($this->probe, 'processMappingConfiguration', [], $config, [], 'default'))->toBe([])
        ->and(fcmCall($this->probe, 'processMappingConfiguration', 12, ['translate_page_references' => 1], [], 'default'))->toBe(12);

    $pick = static fn (array $context): array => \array_intersect_key($context, \array_flip(['wordpress_page_id', 'form_id', 'site_handle', 'page_data_mapping_available']));

    $logged = \array_map(static fn (array $warning): array => $pick($warning[1]), $warnings->getArrayCopy());
    $messages = \implode("\n", \array_column($warnings->getArrayCopy(), 0));

    expect($logged)->toBe([
        ['wordpress_page_id' => '13', 'form_id' => '9', 'site_handle' => 'default', 'page_data_mapping_available' => true],
        ['wordpress_page_id' => '14', 'form_id' => 'unknown', 'site_handle' => 'default', 'page_data_mapping_available' => true],
        ['wordpress_page_id' => '15', 'form_id' => 'unknown', 'site_handle' => 'default', 'page_data_mapping_available' => true],
        ['wordpress_page_id' => 'unknown', 'form_id' => 'unknown', 'site_handle' => 'default', 'page_data_mapping_available' => true],
        ['wordpress_page_id' => '17', 'form_id' => 'unknown', 'site_handle' => 'default', 'page_data_mapping_available' => true],
        ['wordpress_page_id' => '1', 'form_id' => 'unknown', 'site_handle' => 'default', 'page_data_mapping_available' => true],
    ])->and(\str_contains($messages, "FormConfigurationManagerTrait::processPageIdMapping: Could not map WordPress page ID '13' to Statamic entry ID. Using placeholder: 'default_wordpress_page_id_13'"))->toBeTrue();
});

it('reports that no page data mapping was available', function (): void {
    $warnings = fcmWarnings();

    expect(fcmCall($this->probe, 'processMappingConfiguration', 5, ['translate_page_references' => true], [], 'site_b'))->toBe('site_b_wordpress_page_id_5')
        ->and(\count($warnings))->toBe(1)
        ->and($warnings[0][1]['page_data_mapping_available'] ?? null)->toBeFalse();
});

it('replaces variables, complex formats and, with fields, field merge tags', function (): void {
    expect(fcmCall($this->probe, 'processMergeTagsInString', '{site_name} {complex} {field}', [['id' => 1]]))->toBe('{{ site:name }} [complex] [field:1]')
        ->and(fcmCall($this->probe, 'processMergeTagsInString', '{site_name} {complex} {field}'))->toBe('{{ site:name }} [complex] {field}');
});

it('reads a value by key, by integer key, or along a dotted path', function (): void {
    $data = ['a' => 1, 3 => 'three', 'b' => ['c' => ['d' => 'deep']], 'e' => 'flat', 'f.g' => 'dotted key'];

    expect(fcmCall($this->probe, 'extractValueFromData', $data, 'a'))->toBe(1)
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 3))->toBe('three')
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 4))->toBeNull()
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 'missing'))->toBeNull()
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 'b.c.d'))->toBe('deep')
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 'b.c'))->toBe(['d' => 'deep'])
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 'b.x.d'))->toBeNull()
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 'e.f'))->toBeNull()
        ->and(fcmCall($this->probe, 'extractValueFromData', $data, 'f.g'))->toBeNull();
});

it('records a normalized form context for logging, and clears it', function (): void {
    fcmCall($this->probe, 'setFormConfigContextForLogging', ['form_id' => 7, 'source' => 'export.json', 'source_name' => 'Contact', 'plugin' => 'Gravity Forms']);
    $full = ProcessingContext::getFormConfigContext();

    fcmCall($this->probe, 'setFormConfigContextForLogging', ['form_id' => 'seven', 'plugin' => 5]);
    $partial = ProcessingContext::getFormConfigContext();

    fcmCall($this->probe, 'setFormConfigContextForLogging', ['form_id' => 7.5, 'source' => 3, 'source_name' => ['x']]);
    $fallback = ProcessingContext::getFormConfigContext();

    fcmCall($this->probe, 'clearFormConfigContextForLogging');

    expect($full)->toBe(['form_id' => 7, 'source' => 'export.json', 'source_name' => 'Contact', 'plugin' => 'Gravity Forms'])
        ->and($partial)->toBe(['form_id' => 'seven', 'source' => 'unknown', 'source_name' => 'Unnamed'])
        ->and($fallback)->toBe(['form_id' => '?', 'source' => 'unknown', 'source_name' => 'Unnamed'])
        ->and(ProcessingContext::getFormConfigContext())->toBeNull();
});

it('resolves a field ID to a handle made from its label, a subfield through the plugin, and nothing otherwise', function (): void {
    $fields = ['not a field', ['label' => 'No ID'], ['id' => ['x'], 'label' => 'Bad ID'], ['id' => 4, 'label' => 'First Name'], ['id' => '5', 'label' => '!!!']];

    expect(fcmCall($this->probe, 'getFieldHandleById', '4', $fields))->toBe('first_name_4')
        ->and(fcmCall($this->probe, 'getFieldHandleById', '5', $fields))->toBe('field_5')
        ->and(fcmCall($this->probe, 'getFieldHandleById', '6', $fields))->toBeNull()
        ->and(fcmCall($this->probe, 'getFieldHandleById', '4.3', $fields))->toBe('sub_4_3')
        ->and(fcmCall($this->probe, 'convertLabelToHandle', 'Email-Address Here', '9'))->toBe('email_address_here_9');
});

it('resolves a conditional attribute from the first condition met', function (): void {
    $mapping = [
        5 => ['x' => 'not a string key'],
        'not_a_table' => 'x',
        'missing' => ['x' => 'never'],
        'flag' => ['true' => 'when_true', 'false' => 'when_false'],
        'listed' => ['a' => 'never'],
        'type' => ['redirect' => ['not a string'], 'message' => 'message_text', 3 => 'by_number'],
        'fallback' => ['on' => 'fallback_attr'],
    ];

    expect(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['flag' => true]))->toBe('when_true')
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['flag' => false]))->toBe('when_false')
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['listed' => ['a'], 'type' => 'message']))->toBe('message_text')
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['type' => 3]))->toBe('by_number')
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['type' => 'redirect']))->toBeNull()
        // A value naming no usable attribute leaves the next condition to answer.
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['type' => 'redirect', 'fallback' => 'on']))->toBe('fallback_attr')
        // A value mapping that is not a table answers nothing, even for a key text could be read by.
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['not_a_table' => 0]))->toBeNull()
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, ['type' => 'other']))->toBeNull()
        ->and(fcmCall($this->probe, 'processConditionalAttributeMapping', $mapping, []))->toBeNull();
});

it('spreads one value over several attributes, processing each as configured', function (): void {
    $results = fcmCall($this->probe, 'processMultipleMappings', 'b', [
        'copy' => 'any',
        'ignored' => ['statamic_attribute' => 'upper', 'value_translation' => ['b' => 'B']],
        'fields' => ['statamic_attribute' => 'fields', 'translate_field_references' => true],
        'unusable' => ['as_boolean' => true],
        5 => 'a key that is not text',
    ], ['fields' => [['id' => 1]]], 'default');

    expect($results)->toBe(['copy' => 'b', 'upper' => 'B', 'fields' => 'translated(1):b'])
        ->and(fcmCall($this->probe, 'processMultipleMappings', 'b', ['fields' => ['statamic_attribute' => 'fields', 'translate_field_references' => true]], ['fields' => 'not a list'], 'default'))->toBe(['fields' => 'translated(0):b']);
});

it('reports multiple mappings only for a table of them', function (mixed $config, bool $expected): void {
    expect(fcmCall($this->probe, 'hasMultipleMappings', $config))->toBe($expected)
        ->and(fcmCall($this->probe, 'processMultipleMappingsAndGetResults', 'v', $config, [], 'default', null))->toBe($expected ? ['copy' => 'v'] : []);
})->with([
    'a table' => [['statamic_attribute' => ['multiple' => ['copy' => 'any']]], true],
    'a string' => ['x', false],
    'no attribute' => [[], false],
    'a string attribute' => [['statamic_attribute' => 'x'], false],
    'no multiple' => [['statamic_attribute' => ['other' => []]], false],
    'multiple not a table' => [['statamic_attribute' => ['multiple' => 'x']], false],
]);

it('hands a real asset importer on to the spread values, and nothing else', function (): void {
    $config = ['statamic_attribute' => ['multiple' => ['body' => ['statamic_attribute' => 'body', 'as_bard' => true]]]];

    expect(\json_encode(fcmCall($this->probe, 'processMultipleMappingsAndGetResults', 'Hello', $config, [], 'default', $this->importer)))->toContain('paragraph')
        ->and(fcmCall($this->probe, 'processMultipleMappingsAndGetResults', 'Hello', $config, [], 'default', 'not an importer'))->toBe(['body' => 'Hello']);
});

it('sets the other field values a mapping names, at the top level or under a parent', function (): void {
    $config = ['statamic_other_field_value' => ['mode' => 'always', 'count' => 2]];
    $top = ['kept' => 1];
    $nested = ['confirmation_settings' => 'not a table'];
    $existing = ['confirmation_settings' => ['kept' => 1]];
    $untouched = [];

    $method = new ReflectionMethod($this->probe, 'processStatamicOtherFieldValue');
    $method->invokeArgs($this->probe, [$config, 'value', &$top]);
    $method->invokeArgs($this->probe, [$config, 'value', &$nested, 'confirmation_settings']);
    $method->invokeArgs($this->probe, [$config, 'value', &$existing, 'confirmation_settings']);
    $method->invokeArgs($this->probe, ['a string', 'value', &$untouched]);
    $method->invokeArgs($this->probe, [['statamic_other_field_value' => 'x'], 'value', &$untouched]);

    foreach ([null, '', []] as $empty) {
        $method->invokeArgs($this->probe, [$config, $empty, &$untouched]);
    }

    expect($top)->toBe(['kept' => 1, 'mode' => 'always', 'count' => 2])
        ->and($nested)->toBe(['confirmation_settings' => ['mode' => 'always', 'count' => 2]])
        ->and($existing)->toBe(['confirmation_settings' => ['kept' => 1, 'mode' => 'always', 'count' => 2]])
        ->and($untouched)->toBe([]);
});

it('assigns a value to a top-level or a nested attribute of the form data', function (): void {
    $method = new ReflectionMethod($this->probe, 'assignNestedFieldToFormData');
    $data = ['schedule' => 'not a table', 'kept' => ['a' => 1]];

    $method->invokeArgs($this->probe, [&$data, 'title', 'Contact']);
    $method->invokeArgs($this->probe, [&$data, 7, 'seven']);
    $method->invokeArgs($this->probe, [&$data, 'schedule.start', 'today']);
    $method->invokeArgs($this->probe, [&$data, 'kept.b', 2]);
    $method->invokeArgs($this->probe, [&$data, 'fresh.child', 'new']);

    expect($data)->toBe(['schedule' => ['start' => 'today'], 'kept' => ['a' => 1, 'b' => 2], 'title' => 'Contact', 7 => 'seven', 'fresh' => ['child' => 'new']]);
});

it('assigns a confirmation value directly, or under the settings of an additional one', function (): void {
    $method = new ReflectionMethod($this->probe, 'assignNestedFieldToMappedData');
    $default = [];
    $additional = ['confirmation_settings' => 'not a table'];
    $existing = ['confirmation_settings' => ['kept' => 1]];

    $method->invokeArgs($this->probe, [&$default, 'message', 'Thanks']);
    $method->invokeArgs($this->probe, [&$default, 3, 'three']);
    $method->invokeArgs($this->probe, [&$additional, 'message', 'Thanks', true]);
    $method->invokeArgs($this->probe, [&$existing, 'message', 'Thanks', true]);

    expect($default)->toBe(['message' => 'Thanks', 3 => 'three'])
        ->and($additional)->toBe(['confirmation_settings' => ['message' => 'Thanks']])
        ->and($existing)->toBe(['confirmation_settings' => ['kept' => 1, 'message' => 'Thanks']]);
});

it('adds the spread values to the form data, and reports whether it was a spread', function (): void {
    $method = new ReflectionMethod($this->probe, 'processMultipleMappingsForFormData');
    $data = [];
    $config = ['statamic_attribute' => ['multiple' => ['title' => 'any', 'gone' => ['statamic_attribute' => 'schedule.start', 'value_translation' => ['b' => null]], 'kept' => ['statamic_attribute' => 'schedule.end']]]];

    $spread = $method->invokeArgs($this->probe, ['b', $config, [], 'default', null, &$data]);
    $notSpread = $method->invokeArgs($this->probe, ['b', ['statamic_attribute' => 'title'], [], 'default', null, &$data]);

    expect([$spread, $notSpread])->toBe([true, false])
        ->and($data)->toBe(['title' => 'b', 'schedule' => ['end' => 'b']]);
});

it('adds the spread values to a confirmation, with its other field values when asked', function (): void {
    $method = new ReflectionMethod($this->probe, 'processMultipleMappingsForMappedData');
    $config = ['statamic_attribute' => ['multiple' => ['message' => 'any', 'gone' => ['statamic_attribute' => 'gone', 'value_translation' => ['b' => null]]]], 'statamic_other_field_value' => ['format' => 'text']];
    $default = [];
    $additional = [];
    $quiet = [];

    $results = [
        $method->invokeArgs($this->probe, ['b', $config, [], 'default', null, &$default, false, true]),
        $method->invokeArgs($this->probe, ['b', $config, [], 'default', null, &$additional, true, true]),
        $method->invokeArgs($this->probe, ['b', $config, [], 'default', null, &$quiet]),
        $method->invokeArgs($this->probe, ['b', ['statamic_attribute' => 'message'], [], 'default', null, &$quiet]),
    ];

    expect($results)->toBe([true, true, true, false])
        ->and($default)->toBe(['message' => 'b', 'format' => 'text'])
        ->and($additional)->toBe(['confirmation_settings' => ['message' => 'b', 'format' => 'text']])
        ->and($quiet)->toBe(['message' => 'b']);
});

it('maps a confirmation field by field, through the value processor', function (): void {
    $processed = [];
    $processor = static function (string $key, mixed $value, mixed $config) use (&$processed): void {
        $processed[] = [$key, $value, $config];
    };
    $configs = [
        'skipped' => null,
        'message' => 'message_text',
        'url' => ['statamic_attribute' => 'redirect_url', 'trim' => true],
        'default_only' => ['statamic_attribute' => 'excluded', 'exclude_from_default_confirmation' => true],
        'loosely_excluded' => ['statamic_attribute' => 'loosely', 'exclude_from_default_confirmation' => 1],
        'type' => ['statamic_attribute' => ['based_on_other_attribute_value' => ['type' => ['page' => 'page_id']]]],
        'unmatched' => ['statamic_attribute' => ['based_on_other_attribute_value' => ['missing' => ['x' => 'y']]]],
        'no_attribute' => ['trim' => true],
        'numbered' => 5,
        'special_value' => ['statamic_attribute' => 'special'],
        'emptied' => ['statamic_attribute' => 'emptied', 'trim' => true],
        'blank' => 'blank',
        'off' => 'off',
        'spread' => ['statamic_attribute' => ['multiple' => ['spread_copy' => 'any', 'gone' => ['statamic_attribute' => 'gone', 'value_translation' => ['s' => null]]]]],
        'store' => ['statamic_attribute' => 'store', 'statamic_other_field_value' => ['store_mode' => 'always']],
        'not_in_confirmation' => 'never',
    ];
    $confirmation = [
        'message' => 'Thanks',
        'url' => '  https://www.uams.edu/  ',
        'default_only' => 'x',
        'loosely_excluded' => 'y',
        'type' => 'page',
        'unmatched' => 'z',
        'no_attribute' => 'w',
        'numbered' => 'n',
        'special_value' => 'v',
        'emptied' => '   x',
        'blank' => '   ',
        'off' => false,
        'spread' => 's',
        'store' => 'kept',
    ];

    $mapped = fcmCall($this->probe, 'processConfirmationFieldMapping', $confirmation, [], 'default', null, [], $configs, $processor);
    $defaultProcessed = $processed;
    $processed = [];
    fcmCall($this->probe, 'processConfirmationFieldMapping', $confirmation, [], 'default', null, [], $configs, $processor, true);

    // A spread names no single attribute, so a confirmation mapping skips it.
    expect($mapped)->toBe(['store_mode' => 'always'])
        ->and(\array_column($defaultProcessed, 0))->toBe(['message_text', 'redirect_url', 'excluded', 'loosely', 'page_id', 'special', 'emptied', 'store'])
        ->and(\array_column($defaultProcessed, 1))->toBe(['Thanks', 'https://www.uams.edu/', 'x', 'y', 'page', 'special:v', 'x', 'kept'])
        ->and($defaultProcessed[0][2])->toBe('message_text')
        ->and(\array_column($processed, 0))->toBe(['message_text', 'redirect_url', 'loosely', 'page_id', 'special', 'emptied', 'store']);
});

it('spreads a confirmation value whose attribute is both conditional and multiple', function (): void {
    $processed = [];
    $processor = static function (string $key, mixed $value) use (&$processed): void {
        $processed[] = $key;
    };
    $configs = [
        'both' => ['statamic_attribute' => [
            'based_on_other_attribute_value' => ['type' => ['page' => 'page_id']],
            'multiple' => ['first' => 'any', 'gone' => ['statamic_attribute' => 'gone', 'value_translation' => ['v' => null]], 'second' => ['statamic_attribute' => 'second']],
        ]],
        'after' => 'after_text',
    ];

    // The spread is handled whole; the plain mapping after it still runs.
    expect(fcmCall($this->probe, 'processConfirmationFieldMapping', ['both' => 'v', 'type' => 'page', 'after' => 'a'], [], 'default', null, [], $configs, $processor))->toBe(['first' => 'v', 'second' => 'v'])
        ->and($processed)->toBe(['after_text']);
});

it('reports a meaningful value only for one that is not blank or false', function (mixed $value, bool $expected): void {
    expect(fcmCall($this->probe, 'hasMeaningfulValue', $value))->toBe($expected);
})->with([
    'null' => [null, false],
    'blank text' => ['  ', false],
    'an empty list' => [[], false],
    'false' => [false, false],
    'text' => ['x', true],
    'zero' => [0, true],
    'a zero string' => ['0', true],
    'true' => [true, true],
    'a list' => [['x'], true],
]);

it('maps form settings through each shape of the mapping table', function (): void {
    $form = fcmForm('contact');
    $form->merge(['existing' => 'kept', 'schedule_form' => 'not a table', 'outer' => ['kept' => 1], 'spread_nested' => ['kept' => 1]]);

    fcmCall($this->mapped, 'mapFormConfigurationFields', $form, [
        'fields' => [['id' => 1]],
        'parsed' => [
            'title' => 'Contact {site_name} {field}',
            'settings' => ['honeypot' => 'yes'],
            'schedule' => ['start' => '2026-01-01'],
            'special_value' => 'v',
            'empty_flag' => '',
            'cleared' => '',
            'cleared_nested' => '',
            'confirmation' => 'https://www.uams.edu/',
            'confirmation_type' => 'redirect',
            'unmatched' => 'u',
            'no_attribute' => 'yes',
            'store' => 'kept',
            'spread' => 'b',
            'spread_empty' => '',
            // Neither a table nor an attribute name, a bare attribute name, and a conditional
            // whose value table is not one: all skipped.
            'numbered_config' => 'n',
            'plain' => 'p',
            'odd_conditional' => 'o',
            // An empty value with nothing to translate it is skipped too.
            'absent' => '',
        ],
    ], 'default', $this->importer);

    expect(fcmFormData($form))->toBe([
        'existing' => 'kept',
        'schedule_form' => ['start_datetime' => '2026-01-01'],
        'outer' => ['kept' => 1],
        'spread_nested' => ['kept' => 1, 'upper' => 'B'],
        'title' => 'Contact {{ site:name }} [field:1]',
        'honeypot' => true,
        'special' => 'special:v',
        'empty_flag' => 'was empty',
        'redirect_url' => 'https://www.uams.edu/',
        'store' => 'kept',
        'store_mode' => 'always',
        'spread_copy' => 'b',
        'spread_translated_copy' => 'none',
        'plugin_specific' => 'default',
    ])->and($this->mapped->calls)->toBe([['mapConfirmationFields', 'contact', 'default']]);
});

it('maps form settings without any form fields to resolve merge tags against', function (): void {
    $form = fcmForm('contact');

    fcmCall($this->mapped, 'mapFormConfigurationFields', $form, ['parsed' => [
        'title' => 'Contact {field}',
        'spread' => 'b',
        'spread_translated' => 'x',
        'confirmation' => '/thanks',
        'confirmation_type' => 'message',
    ]], 'default');

    expect(fcmFormData($form))->toBe([
        'title' => 'Contact {field}',
        'empty_flag' => 'was empty',
        // A nested attribute whose value translates to nothing still gets its parent group.
        'outer' => [],
        'message_text' => '/thanks',
        'spread_copy' => 'b',
        'spread_nested' => ['upper' => 'B'],
        'spread_translated_copy' => 'x',
        'plugin_specific' => 'default',
    ]);
});

it('maps no form settings for a manager without a mapping table', function (): void {
    $form = fcmForm('contact');

    fcmCall($this->probe, 'mapFormConfigurationFields', $form, ['title' => 'Contact'], 'default');

    expect(fcmFormData($form))->toBe([])
        ->and($this->probe->calls)->toBe([]);
});

it('builds an email configuration from each shape of the notification mapping', function (): void {
    $fields = [['id' => 3, 'label' => 'Email'], ['id' => 4, 'label' => 'Name']];

    $config = fcmTable(fcmCall($this->mapped, 'buildEmailConfiguration', [
        'id' => 'given',
        'no_attribute' => 'x',
        'listed_attribute' => 'y',
        'toType' => 'email',
        'to' => 'office@uams.edu',
        'toField' => '3',
        'fromField' => '4',
        'routing' => [['email' => 'a@uams.edu']],
        'isActive' => '0',
        'subject' => 'New {site_name} {field}',
        'special_value' => 'v',
        'attachments' => 'yes',
        'plain' => 'p',
        'note' => '',
        'cleared' => '',
        'html' => '<p>Hi</p>',
    ], [], 'default', null, $fields));

    expect($config)->toBe([
        'to_type' => 'email',
        'to' => 'office@uams.edu',
        'to_field' => 'email_3',
        'from' => '{{ submission:name_4 }}',
        'routing' => ['converted' => [['email' => 'a@uams.edu']]],
        'enabled' => false,
        'subject' => 'New {{ site:name }} [field:2]',
        'special' => 'special:v',
        'attachments' => true,
        'note' => 'none',
        'html' => '<p>Hi</p>',
        'markdown' => false,
        // The notification's own ID is not mapped, so the configuration gets a new one.
        'id' => $config['id'] ?? null,
    ])->and($config['id'] ?? null)->toBeString()->toHaveLength(8)->not->toBe('given');
});

it('drops a recipient whose type is not an address, and field references it cannot resolve', function (): void {
    $config = fcmTable(fcmCall($this->mapped, 'buildEmailConfiguration', [
        'toType' => 'field',
        'to' => 'office@uams.edu',
        'toField' => '99',
        'fromField' => '98',
        'routing' => 'not a list',
        'isActive' => '1',
    ], [], 'default'));

    expect(\array_diff_key($config, ['id' => true]))->toBe([
        'to_type' => 'field',
        'to_field' => '99',
        'routing' => 'not a list',
        'enabled' => true,
        'note' => 'none',
    ])->and($config['id'] ?? null)->toBeString()->toHaveLength(8);
});

it('skips a field reference that is not a scalar', function (): void {
    $config = fcmTable(fcmCall($this->mapped, 'buildEmailConfiguration', ['toField' => ['3'], 'fromField' => ['4'], 'subject' => 's'], [], 'default'));

    // An absent isActive still enables the notification, in its own place in the table.
    expect(\array_diff_key($config, ['id' => true]))->toBe(['enabled' => true, 'subject' => 's', 'note' => 'none']);
});

it('resolves field references given as numbers, skips an empty value, and converts only the routing list', function (): void {
    $fields = [['id' => 3, 'label' => 'Email'], ['id' => 4, 'label' => 'Name']];
    $config = fcmTable(fcmCall($this->mapped, 'buildEmailConfiguration', [
        'toField' => 3,
        'fromField' => 4,
        'isActive' => true,
        'subject' => '',
        'html' => ['not', 'text'],
    ], [], 'default', null, $fields));

    expect(\array_diff_key($config, ['id' => true]))->toBe([
        'to_field' => 'email_3',
        'from' => '{{ submission:name_4 }}',
        'enabled' => true,
        'note' => 'none',
        'html' => ['not', 'text'],
        'markdown' => false,
    ]);
});

it('keeps the recipient for a plugin whose mapping has no recipient type', function (): void {
    $config = fcmTable(fcmCall(new FcmTraitPlainToProbe, 'buildEmailConfiguration', ['to' => 'office@uams.edu'], [], 'default'));

    expect(\array_diff_key($config, ['id' => true]))->toBe(['to' => 'office@uams.edu', 'enabled' => true]);
});

it('builds no email configuration without a mapping table', function (): void {
    expect(fcmCall($this->probe, 'buildEmailConfiguration', ['to' => 'office@uams.edu'], [], 'default'))->toBe([]);
});

it('maps email content through each shape of the content mapping', function (): void {
    $fields = [['id' => 1]];

    $content = fcmCall($this->mapped, 'processEmailContentMappings', [
        'skipped' => 'x',
        'body' => 'Hi {site_name} {field}',
        'html_body' => '<p>{complex}</p>',
        'no_attribute' => 'w',
        'numbered' => 'n',
        'count' => 7,
        'spread' => 'Copy {field}',
        'after_spread' => 'After',
    ], [], 'default', null, $fields);

    expect($content)->toBe([
        'message' => 'Hi {{ site:name }} [field:1]',
        'html' => '<p>[complex]</p>',
        'format' => 'html',
        'count' => 7,
        'text' => 'Copy [field:1]',
        'copy' => 'Copy [field:1]',
        'spread_done' => true,
        'after' => 'After',
    ]);
});

it('maps no email content for an empty value, a missing table or a constant that is not one', function (): void {
    expect(fcmCall($this->mapped, 'processEmailContentMappings', ['body' => '', 'spread' => ''], [], 'default'))->toBe([])
        ->and(fcmCall($this->probe, 'processEmailContentMappings', ['body' => 'x'], [], 'default'))->toBe([])
        ->and(fcmCall($this->mapped, 'processEmailContentMappings', ['body' => 'x'], [], 'default', null, [], 'NOT_A_TABLE'))->toBe([]);
});

it('drops a spread content value that translates to nothing', function (): void {
    expect(fcmCall($this->mapped, 'processEmailContentMappings', ['spread' => 'dropped'], [], 'default'))->toBe(['text' => 'dropped', 'spread_done' => true]);
});

it('hands each mapped value of a named table to the assignment callback', function (): void {
    $assigned = [];
    $callback = static function (string $key, mixed $value, mixed $config, string $extra) use (&$assigned): void {
        $assigned[] = [$key, $value, $extra];
    };

    fcmCall($this->mapped, 'processMappingConfigurations', 'FORM_CONFIGURATION_MAPPING', [
        'numbered_config' => 'n',
        'plain' => 'p',
        'odd_conditional' => 'o',
        'title' => ' Contact ',
        'settings' => ['honeypot' => 'yes'],
        'confirmation' => 'https://www.uams.edu/',
        'confirmation_type' => 'message',
        'unmatched' => 'u',
        'no_attribute' => 'x',
        'absent' => '',
        'store' => 'kept',
    ], ['fields' => [['id' => 1]]], 'default', null, $callback, ['extra']);
    fcmCall($this->mapped, 'processMappingConfigurations', 'FORM_CONFIGURATION_ACTION_MAPPING_EMAIL_CONTENT', ['body' => 'Hi', 'numbered' => 'n', 'skipped' => 'x'], [], 'default', null, $callback, ['content']);
    fcmCall($this->mapped, 'processMappingConfigurations', 'NOT_A_TABLE', ['body' => 'Hi'], [], 'default', null, $callback, ['never']);
    fcmCall($this->mapped, 'processMappingConfigurations', 'MISSING', ['body' => 'Hi'], [], 'default', null, $callback, ['never']);

    expect($assigned)->toBe([
        ['plain_target', 'p', 'extra'],
        ['title', ' Contact ', 'extra'],
        ['honeypot', true, 'extra'],
        ['message_text', 'https://www.uams.edu/', 'extra'],
        ['store', 'kept', 'extra'],
        ['message', 'Hi', 'content'],
    ]);
});

it('maps the form settings before saving them', function (): void {
    $form = $this->createMock(Form::class);
    $form->method('data')->willReturn(new Collection);
    $form->method('handle')->willReturn('contact');
    $form->expects($this->once())->method('merge')->with(['title' => 'Contact', 'empty_flag' => 'was empty', 'outer' => [], 'spread_translated_copy' => 'none', 'plugin_specific' => 'default']);
    $form->expects($this->once())->method('save');

    expect(fcmCall($this->mapped, 'configureFormSettingsInternal', $form, ['parsed' => ['title' => 'Contact']], 'Gravity Forms', 'default', null))->toBeTrue();
});

it('saves the configured form, with its email configurations when it has notifications', function (array $formData, ?array $emails): void {
    $form = $this->createMock(Form::class);
    $form->method('data')->willReturn(new Collection);
    $form->method('handle')->willReturn('contact');
    $form->expects($emails === null ? $this->never() : $this->once())->method('email')->with($emails);
    // Without a mapping table the settings are not merged.
    $form->expects($this->never())->method('merge');
    $form->expects($this->once())->method('save');

    expect(fcmCall($this->probe, 'configureFormSettingsInternal', $form, $formData, 'Gravity Forms', 'default', null))->toBeTrue();
})->with([
    'without notifications' => [[], null],
    'with notifications' => [['notifications' => [['to' => 'a@uams.edu']]], [['to' => 'a@uams.edu']]],
]);

it('casts attachments to a boolean and enables a configuration by default', function (): void {
    expect(fcmCall($this->probe, 'setDefaultEmailConfigurationValues', ['attachments' => 'yes']))->toBe(['attachments' => true, 'enabled' => true])
        ->and(fcmCall($this->probe, 'setDefaultEmailConfigurationValues', ['attachments' => 0, 'enabled' => false]))->toBe(['attachments' => false, 'enabled' => false])
        ->and(fcmCall($this->probe, 'setDefaultEmailConfigurationValues', []))->toBe(['enabled' => true]);
});
