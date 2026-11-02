<?php

declare(strict_types=1);

/**
 * The shared FormBlueprintCreatorTrait, through a probe class with fake mapping classes (#98).
 *
 * The three plugin blueprint creators reach the trait only with their own mapping tables,
 * which never return a linked field together with set attributes or subfields, never fail to
 * build a blueprint, and always define every lookup the trait calls. The probe here supplies
 * those cases: a configurable mapping class that follows FieldtypeMappingTrait's rules for the
 * entries it carries, one whose lookups return the wrong types, and one that defines none of them.
 *
 * @command  php vendor/bin/pest tests/Integration/FormBlueprintCreatorTraitTest.php
 */

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormBlueprintCreatorTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A mapping class whose fieldtypes, linked fields and set attributes a test configures.
 *
 * It answers the four lookups the blueprint trait makes, plus the two mapping predicates,
 * which follow FieldtypeMappingTrait's rules for the attribute-less entries used here.
 */
final class BlueprintTraitMapping
{
    /**
     * The fieldtype mapping the blueprint trait reads.
     *
     * @var array<int|string, mixed>
     */
    private static array $mapping = [
        'text' => ['default' => 'text'],
        'email' => ['default' => 'text'],
        'hidden' => ['default' => false],
        'captcha' => ['default' => null, 'trace_when_skipped' => true],
        'html' => ['default' => null],
        // Suppressed by its mapping entry, although the resolver below would map it.
        'retired' => ['default' => false],
        // An entry that is not an array cannot describe a mapping.
        'weird' => 'text',
    ];

    /**
     * The Statamic fieldtype each WordPress type maps to.
     *
     * @var array<string, string|false|null>
     */
    public static array $fieldtypes = ['text' => 'text', 'email' => 'text', 'hidden' => false, 'retired' => 'text', 'weird' => 'text'];

    /**
     * The WordPress type that maps to a linked field, if any.
     */
    public static ?string $linkedType = null;

    /**
     * The set attributes every field receives.
     *
     * @var array<int|string, mixed>
     */
    public static array $setAttributes = [];

    /**
     * Return the fieldtype mapping.
     *
     * @return array<int|string, mixed> The mapping
     */
    public static function getMapping(): array
    {
        return self::$mapping;
    }

    /**
     * Whether an entry maps the type: a set `default`, false included, as in FieldtypeMappingTrait.
     *
     * @param  array<int|string, mixed>  $mapping  The entry
     * @return bool Whether it is mapped
     */
    public static function isFieldtypeMapped(array $mapping): bool
    {
        return isset($mapping['default']);
    }

    /**
     * Whether an entry asks for its skipped fields to be traced.
     *
     * @param  array<int|string, mixed>  $mapping  The entry
     * @return bool Whether it traces
     */
    public static function tracesWhenSkipped(array $mapping): bool
    {
        return ($mapping['trace_when_skipped'] ?? null) === true;
    }

    /**
     * Map a WordPress field to its configured Statamic fieldtype.
     *
     * @param  string|array<int|string, mixed>  $wordpressField  The field or its type
     * @return string|null|false The fieldtype
     */
    public static function getStatamicFieldtype(string|array $wordpressField): string|null|false
    {
        $type = \is_array($wordpressField) ? ($wordpressField['type'] ?? null) : $wordpressField;

        return \is_string($type) ? (self::$fieldtypes[$type] ?? null) : null;
    }

    /**
     * Name the linked field for the configured type.
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping
     * @param  string|array<int|string, mixed>  $fieldOrType  The field or its type
     * @return ?string The linked field handle
     */
    public static function getLinkedFieldFromMapping(array $mappingArray, string|array $fieldOrType): ?string
    {
        $type = \is_array($fieldOrType) ? ($fieldOrType['type'] ?? null) : $fieldOrType;

        return self::$linkedType !== null && $type === self::$linkedType ? 'shared_email' : null;
    }

    /**
     * Return the configured set attributes.
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping
     * @param  string|array<int|string, mixed>  $fieldOrType  The field or its type
     * @param  ?string  $siteHandle  The site handle
     * @return array<int|string, mixed> The set attributes
     */
    public static function getSetAttributesFromMapping(array $mappingArray, string|array $fieldOrType, ?string $siteHandle = null): array
    {
        return self::$setAttributes;
    }
}

/**
 * A mapping class whose lookups all return a value of the wrong type.
 */
final class BlueprintTraitBrokenMapping
{
    /**
     * Return a mapping that is not an array.
     *
     * @return mixed Not an array
     */
    public static function getMapping(): mixed
    {
        return 'nope';
    }

    /**
     * Return a fieldtype that is not a string.
     *
     * @param  mixed  $field  The field
     * @return mixed Not a string
     */
    public static function getStatamicFieldtype(mixed $field): mixed
    {
        return 5;
    }

    /**
     * Return a linked field that is not a usable handle.
     *
     * @param  mixed  $mapping  The mapping
     * @param  mixed  $field  The field
     * @return mixed An empty handle
     */
    public static function getLinkedFieldFromMapping(mixed $mapping, mixed $field): mixed
    {
        return '';
    }

    /**
     * Return set attributes that are not an array.
     *
     * @param  mixed  $mapping  The mapping
     * @param  mixed  $field  The field
     * @param  mixed  $siteHandle  The site handle
     * @return mixed Not an array
     */
    public static function getSetAttributesFromMapping(mixed $mapping, mixed $field, mixed $siteHandle = null): mixed
    {
        return 'nope';
    }
}

/**
 * A mapping class that defines none of the lookups.
 */
final class BlueprintTraitEmptyMapping {}

/**
 * A minimal blueprint creator over the trait, with every plugin hook configurable.
 */
final class BlueprintTraitProbe
{
    use FormBlueprintCreatorTrait {
        makeBlueprintFromFields as protected traitMakeBlueprintFromFields;
        getRequiredAttributeName as protected traitRequiredAttributeName;
    }

    /**
     * The mapping class the probe reads.
     *
     * @var class-string
     */
    public string $mappingClass = BlueprintTraitMapping::class;

    /**
     * The attributes getMappedFieldAttributes() returns.
     *
     * @var array<int|string, mixed>
     */
    public array $mapped = [];

    /**
     * The attributes getMappedSubfieldAttributes() returns.
     *
     * @var array<int|string, mixed>
     */
    public array $subfields = [];

    /**
     * Whether building a blueprint throws.
     */
    public bool $failMake = false;

    /**
     * The required-attribute name, or null for the trait's default.
     */
    public ?string $required = null;

    /**
     * Read the field's label as its handle name.
     *
     * @param  array<int|string, mixed>  $field  The field
     * @return string The name
     */
    protected function getFieldNameForHandle(array $field): string
    {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : '';
    }

    /**
     * Read the field's label as its display name.
     *
     * @param  array<int|string, mixed>  $field  The field
     * @return string The name
     */
    protected function getFieldNameForDisplay(array $field): string
    {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : '';
    }

    /**
     * Return the configured field attributes.
     *
     * @param  array<int|string, mixed>  $field  The field
     * @param  string  $pluginName  The plugin
     * @param  array<int|string, mixed>  $allFields  The form's fields
     * @param  ?string  $formTitle  The form title
     * @param  ?string  $fieldLabel  The field label
     * @param  ?string  $formId  The form ID
     * @param  ?string  $siteHandle  The site handle
     * @return array<int|string, mixed> The attributes
     */
    protected function getMappedFieldAttributes(array $field, string $pluginName, array $allFields = [], ?string $formTitle = null, ?string $fieldLabel = null, ?string $formId = null, ?string $siteHandle = null): array
    {
        return $this->mapped;
    }

    /**
     * Return the configured subfield attributes.
     *
     * @param  array<int|string, mixed>  $field  The field
     * @param  string  $pluginName  The plugin
     * @param  array<int|string, mixed>  $allFields  The form's fields
     * @param  ?string  $formTitle  The form title
     * @param  ?string  $fieldLabel  The field label
     * @param  ?string  $formId  The form ID
     * @return array<int|string, mixed> The attributes
     */
    protected function getMappedSubfieldAttributes(array $field, string $pluginName, array $allFields = [], ?string $formTitle = null, ?string $fieldLabel = null, ?string $formId = null): array
    {
        return $this->subfields;
    }

    /**
     * Name the configured mapping class.
     *
     * @return string The class
     */
    protected function getMappingClass(): string
    {
        return $this->mappingClass;
    }

    /**
     * Build a blueprint, or fail when told to.
     *
     * @param  array<string, mixed>  $fields  The fields
     * @return Blueprint The blueprint
     */
    protected function makeBlueprintFromFields(array $fields): Blueprint
    {
        if ($this->failMake) {
            throw new RuntimeException('cannot build');
        }

        return $this->traitMakeBlueprintFromFields($fields);
    }

    /**
     * Name the required attribute, or defer to the trait's default.
     *
     * @return string The attribute name
     */
    protected function getRequiredAttributeName(): string
    {
        return $this->required ?? $this->traitRequiredAttributeName();
    }
}

/**
 * Call a protected method on a probe.
 *
 * @param  BlueprintTraitProbe  $probe  The probe
 * @param  string  $method  The method name
 * @param  mixed  ...$args  The method's arguments
 * @return mixed What the method returned
 */
function bctCall(BlueprintTraitProbe $probe, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($probe, $method))->invoke($probe, ...$args);
}

/**
 * The first section of a blueprint tab, or null when the contents are not that shape.
 *
 * @param  mixed  $contents  The blueprint contents
 * @param  ?string  $tab  The tab handle, or null for the first tab
 * @return ?array<int|string, mixed> The section
 */
function bctSection(mixed $contents, ?string $tab = null): ?array
{
    $tabs = \is_array($contents) ? ($contents['tabs'] ?? null) : null;

    if (! \is_array($tabs) || $tabs === []) {
        return null;
    }

    $chosen = $tab === null ? \array_values($tabs)[0] : ($tabs[$tab] ?? null);
    $sections = \is_array($chosen) ? ($chosen['sections'] ?? null) : null;
    $section = \is_array($sections) ? ($sections[0] ?? null) : null;

    return \is_array($section) ? $section : null;
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
    BlueprintTraitMapping::$linkedType = null;
    BlueprintTraitMapping::$setAttributes = [];
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    BlueprintTraitMapping::$linkedType = null;
    BlueprintTraitMapping::$setAttributes = [];

    foreach (['bct_basic', 'bct_fields'] as $handle) {
        $blueprint = BlueprintFacade::find('forms.'.$handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }
});

it('saves an empty blueprint, then finds and deletes it', function (): void {
    $probe = new BlueprintTraitProbe;

    expect($probe->blueprintExists('bct_basic'))->toBeFalse()
        ->and($probe->createBasicBlueprint('bct_basic', 'Basic'))->toBeTrue()
        ->and($probe->blueprintExists('bct_basic'))->toBeTrue()
        ->and($probe->getBlueprint('bct_basic')?->namespace())->toBe('forms')
        ->and($probe->deleteBlueprint('bct_basic'))->toBeTrue()
        ->and($probe->blueprintExists('bct_basic'))->toBeFalse()
        ->and($probe->deleteBlueprint('bct_basic'))->toBeFalse();
});

it('logs and reports failure when an empty blueprint cannot be built', function (): void {
    $log = Log::spy();
    $probe = new BlueprintTraitProbe;
    $probe->failMake = true;

    expect($probe->createBasicBlueprint('bct_basic', 'Basic'))->toBeFalse()
        ->and($probe->blueprintExists('bct_basic'))->toBeFalse();

    $log->shouldHaveReceived('error', ['Failed to create blueprint for form: bct_basic', ['error' => 'cannot build', 'form_title' => 'Basic']]);
});

it('builds a blueprint from the mapped fields, and notes the traced ones it dropped', function (): void {
    $probe = new BlueprintTraitProbe;
    $fields = [
        'not a field',
        ['id' => 1, 'type' => 'text', 'label' => 'Name'],
        // Suppressed: dropped without a warning.
        ['id' => 2, 'type' => 'hidden', 'label' => 'Secret'],
        // Unmapped and traced: dropped, warned, and named in the note.
        ['id' => 3, 'type' => 'captcha', 'label' => 'Robot check'],
        // Unmapped and not traced: dropped and warned.
        ['id' => 4, 'type' => 'html', 'label' => 'Intro'],
        // A type that is not a single value reads as 'unknown', which is not mapped.
        ['id' => 5, 'type' => ['x'], 'label' => 'Odd'],
        ['id' => 6, 'type' => 'retired', 'label' => 'Old'],
        ['id' => 7, 'type' => 'weird', 'label' => 'Strange'],
        // An int type is read as its string, which is not mapped.
        ['id' => 8, 'type' => 5, 'label' => 'Numbered'],
    ];

    expect($probe->createBlueprintWithFields('bct_fields', 'Contact', $fields, 'probe', '9', 'default'))->toBeTrue();

    $section = bctSection(BlueprintFacade::find('forms.bct_fields')?->contents());
    $handles = \is_array($section) && \is_array($section['fields'] ?? null) ? \array_column($section['fields'], 'handle') : null;
    $unmapped = \array_map(static fn (mixed $row): mixed => \is_array($row) ? $row['field_type'] ?? null : null, \array_values((array) (ImportWarningManager::getWarningsByCategory('forms')['unmapped_fieldtypes'] ?? [])));

    expect($handles)->toBe(['name_1'])
        ->and(\is_array($section) ? $section['instructions'] ?? null : null)->toBe('Imported from WordPress. 1 field could not be imported because Statamic has no equivalent, so this form no longer collects it: Robot check (captcha). Review whether the form still does what it was built for.')
        ->and($unmapped)->toContain('captcha', 'html', 'unknown', '5')->not->toContain('hidden', 'retired');
});

it('logs and reports failure when a blueprint with fields cannot be built', function (): void {
    $log = Log::spy();
    $probe = new BlueprintTraitProbe;
    $probe->failMake = true;

    expect($probe->createBlueprintWithFields('bct_fields', 'Contact', [['id' => 1, 'type' => 'text', 'label' => 'Name'], 'not a field'], 'probe'))->toBeFalse();

    $log->shouldHaveReceived('error', ['Failed to create blueprint with fields for form: bct_fields', ['error' => 'cannot build', 'form_title' => 'Contact', 'field_count' => 2]]);
});

it('words the note for several dropped fields in the plural', function (): void {
    $blueprint = BlueprintFacade::makeFromFields(['name_1' => ['type' => 'text']]);

    bctCall(new BlueprintTraitProbe, 'noteSkippedFieldsOnBlueprint', $blueprint, ['Robot check (captcha)', 'Quantity (quantity)']);

    $section = bctSection($blueprint->contents());

    expect(\is_array($section) ? $section['instructions'] ?? null : null)->toBe('Imported from WordPress. 2 fields could not be imported because Statamic has no equivalent, so this form no longer collects them: Robot check (captcha), Quantity (quantity). Review whether the form still does what it was built for.');
});

it('leaves a blueprint it did not build in the expected shape untouched, without a warning', function (array $contents): void {
    $blueprint = BlueprintFacade::make();
    $blueprint->setContents($contents);
    $before = $blueprint->contents();

    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        bctCall(new BlueprintTraitProbe, 'noteSkippedFieldsOnBlueprint', $blueprint, ['Robot check (captcha)']);
    } finally {
        restore_error_handler();
    }

    expect($blueprint->contents())->toBe($before);
})->with([
    'no tabs' => [['title' => 'x']],
    'tabs that are not an array' => [['tabs' => 'nope']],
    'no tabs in the list' => [['tabs' => []]],
    'a first tab that is not an array' => [['tabs' => ['main' => 'nope']]],
    'a first tab with no sections' => [['tabs' => ['main' => ['display' => 'Main']]]],
    'a first section that is not an array' => [['tabs' => ['main' => ['sections' => ['nope']]]]],
]);

it('leaves a blueprint alone when nothing was dropped', function (): void {
    $blueprint = BlueprintFacade::makeFromFields(['name_1' => ['type' => 'text']]);
    $before = $blueprint->contents();

    bctCall(new BlueprintTraitProbe, 'noteSkippedFieldsOnBlueprint', $blueprint, []);

    expect($blueprint->contents())->toBe($before);
});

it('builds a field handle from the name and ID, falling back to the ID or the type', function (array $field, string $pattern): void {
    $handle = bctCall(new BlueprintTraitProbe, 'generateFieldHandle', $field);

    expect(\is_string($handle) ? $handle : '')->toMatch($pattern);
})->with([
    'a name and an ID' => [['id' => 7, 'label' => 'First Name'], '/^first_name_7$/'],
    'a name that slugs to nothing' => [['id' => 7, 'label' => '!!!'], '/^field_7$/'],
    'an ID only' => [['id' => '7', 'label' => '   '], '/^field_7$/'],
    'a type only' => [['type' => 'email', 'label' => 'Email'], '/^field_email_[A-Za-z0-9]{8}$/'],
    'an ID and a type that are not single values' => [['id' => ['7'], 'type' => ['email']], '/^field_[A-Za-z0-9]{8}$/'],
    'an empty type' => [['type' => ''], '/^field_[A-Za-z0-9]{8}$/'],
]);

it('displays a field with no name by its type and ID', function (array $field, string $expected): void {
    expect(bctCall(new BlueprintTraitProbe, 'generateDisplayName', $field))->toBe($expected);
})->with([
    'a type and an ID' => [['id' => 7, 'type' => 'single_line_text'], 'Single Line Text (ID: 7)'],
    'neither as a single value' => [['id' => ['7'], 'type' => ['x']], 'Unknown (ID: unknown)'],
    'neither present' => [[], 'Unknown (ID: unknown)'],
]);

it('creates no field config for a suppressed type, and a text one for an unmapped type', function (): void {
    $probe = new BlueprintTraitProbe;

    expect(bctCall($probe, 'createBlueprintField', ['id' => 1, 'type' => 'hidden', 'label' => 'Secret'], 'probe'))->toBeNull()
        ->and(bctCall($probe, 'createBlueprintField', ['id' => 1, 'type' => 'mystery', 'label' => 'Mystery'], 'probe'))->toBe(['type' => 'text', 'display' => 'Mystery']);
});

it('merges validation and every attribute source into a regular field, dropping nulls', function (): void {
    $probe = new BlueprintTraitProbe;
    $probe->required = 'isRequired';
    $probe->mapped = ['placeholder' => 'Your name', 'gone' => null];
    $probe->subfields = ['first_label' => 'First'];
    BlueprintTraitMapping::$setAttributes = ['input_type' => 'text', 'also_gone' => null];

    expect(bctCall($probe, 'createBlueprintField', ['id' => 1, 'type' => 'text', 'label' => 'Name', 'isRequired' => 1], 'probe'))->toBe([
        'type' => 'text',
        'display' => 'Name',
        'validate' => ['required', 'sometimes'],
        'placeholder' => 'Your name',
        'input_type' => 'text',
        'first_label' => 'First',
    ]);
});

it('puts a linked field\'s validation and attributes under its config, dropping nulls', function (): void {
    $probe = new BlueprintTraitProbe;
    $probe->required = 'isRequired';
    $probe->mapped = ['placeholder' => 'you@example.com', 'gone' => null];
    $probe->subfields = ['confirm' => true];
    BlueprintTraitMapping::$linkedType = 'email';
    BlueprintTraitMapping::$setAttributes = ['input_type' => 'email', 'also_gone' => null];

    expect(bctCall($probe, 'createBlueprintField', ['id' => 2, 'type' => 'email', 'label' => 'Email', 'isRequired' => true], 'probe'))->toBe([
        'field' => 'shared_email',
        'config' => [
            'display' => 'Email',
            'validate' => ['required', 'sometimes'],
            'placeholder' => 'you@example.com',
            'input_type' => 'email',
            'confirm' => true,
        ],
    ]);
});

it('requires nothing by default, and reads the required flag for truthiness', function (): void {
    $probe = new BlueprintTraitProbe;

    expect(bctCall($probe, 'getRequiredAttributeName'))->toBe('')
        ->and(bctCall($probe, 'getFieldValidation', ['id' => 1, 'required' => 1]))->toBe([]);

    $probe->required = 'required';

    expect(bctCall($probe, 'getFieldValidation', ['id' => 1, 'required' => '0']))->toBe([])
        ->and(bctCall($probe, 'getFieldValidation', ['id' => 1]))->toBe([])
        ->and(bctCall($probe, 'getFieldValidation', ['id' => 1, 'required' => 'yes']))->toBe(['required', 'sometimes']);
});

it('reads a config section that is not an array as empty', function (): void {
    expect(bctCall(new BlueprintTraitProbe, 'normalizeBlueprintConfigSection', 'nope'))->toBe([])
        ->and(bctCall(new BlueprintTraitProbe, 'normalizeBlueprintConfigSection', ['a' => 1]))->toBe(['a' => 1]);
});

it('falls back safely when a mapping class lacks a lookup or answers with the wrong type', function (string $class, mixed $fieldtype): void {
    if (! \class_exists($class)) {
        throw new LogicException("No class {$class}.");
    }

    $probe = new BlueprintTraitProbe;
    $probe->mappingClass = $class;
    $field = ['id' => 1, 'type' => 'text', 'label' => 'Name'];

    expect(bctCall($probe, 'getMappingForPlugin', 'probe'))->toBe([])
        ->and(bctCall($probe, 'getMappedFieldType', $field, 'probe'))->toBe($fieldtype)
        ->and(bctCall($probe, 'getLinkedFieldFromFieldtypeMapping', $field, 'probe'))->toBeNull()
        ->and(bctCall($probe, 'getSetAttributesFromFieldtypeMapping', $field, 'probe'))->toBe([]);
})->with([
    'no lookups defined' => [BlueprintTraitEmptyMapping::class, null],
    'lookups of the wrong type' => [BlueprintTraitBrokenMapping::class, null],
]);

it('keeps a suppressed fieldtype as false through the mapping lookup', function (): void {
    expect(bctCall(new BlueprintTraitProbe, 'getMappedFieldType', ['id' => 1, 'type' => 'hidden'], 'probe'))->toBeFalse()
        ->and(bctCall(new BlueprintTraitProbe, 'getFieldType', ['id' => 1, 'type' => 'hidden'], 'probe'))->toBeNull();
});

it('writes the note on the first tab only', function (): void {
    $blueprint = BlueprintFacade::make();
    $blueprint->setContents(['tabs' => [
        'main' => ['sections' => [['fields' => []]]],
        'extra' => ['sections' => [['fields' => []]]],
    ]]);

    bctCall(new BlueprintTraitProbe, 'noteSkippedFieldsOnBlueprint', $blueprint, ['Robot check (captcha)']);

    expect(isset(bctSection($blueprint->contents(), 'main')['instructions']))->toBeTrue()
        ->and(bctSection($blueprint->contents(), 'extra'))->toBe(['fields' => []]);
});

it('builds a random handle when the type casts to nothing', function (): void {
    $handle = bctCall(new BlueprintTraitProbe, 'generateFieldHandle', ['type' => false]);

    expect(\is_string($handle) ? $handle : '')->toMatch('/^field_[A-Za-z0-9]{8}$/');
});

it('displays a field with a blank name, or a non-string type, by its type and ID', function (array $field, string $expected): void {
    expect(bctCall(new BlueprintTraitProbe, 'generateDisplayName', $field))->toBe($expected);
})->with([
    'a blank name' => [['id' => 7, 'type' => 'email', 'label' => '   '], 'Email (ID: 7)'],
    'an int type' => [['id' => 7, 'type' => 5], '5 (ID: 7)'],
    'a boolean type' => [['id' => 7, 'type' => true], '1 (ID: 7)'],
]);
