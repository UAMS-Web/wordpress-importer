<?php

declare(strict_types=1);

/**
 * Tests that `FieldDefaultHelper` reads a field's default from a fieldset
 * reference, a fieldset and field handle, or a blueprint field (#1314). A
 * blueprint field that imports a fieldset field with config overrides yields the
 * overridden default, because Statamic resolves the import before the helper
 * reads the field.
 *
 * The two lookups it makes, `FieldRepository::find()` and `Blueprint::find()`,
 * are faked so no fieldset or blueprint is written to disk: the field repository
 * by a subclass bound in the container, which the real-time facade resolves, and
 * the blueprint facade by Mockery. The `Field` objects returned are real, so
 * `defaultValue()` runs through Statamic's fieldtypes.
 * Routed to the light Statamic band for that reason.
 *
 * @command  php vendor/bin/pest tests/Integration/FieldDefaultHelperTest.php
 */

use Illuminate\Support\Facades\Facade;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\FieldRepository;
use Statamic\Fields\FieldsetRepository;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FieldDefaultHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Bind a field repository that answers from a map and records every lookup.
 *
 * @param  array<string, array<string, mixed>>  $fields  Field config by dot reference
 * @return ArrayObject<int, string> The references looked up, in order
 */
function defaultHelperFields(array $fields): ArrayObject
{
    /** @var ArrayObject<int, string> $lookups */
    $lookups = new ArrayObject;

    $repository = new class(app(FieldsetRepository::class), $fields, $lookups) extends FieldRepository
    {
        /**
         * @param  FieldsetRepository  $fieldsets  The real fieldset repository
         * @param  array<string, array<string, mixed>>  $fields  Field config by dot reference
         * @param  ArrayObject<int, string>  $lookups  Where each lookup is recorded
         */
        public function __construct(
            FieldsetRepository $fieldsets,
            private readonly array $fields,
            private readonly ArrayObject $lookups
        ) {
            parent::__construct($fieldsets);
        }

        /**
         * A fresh field for a known reference, or null.
         *
         * @param  string  $field  The dot reference
         * @return ?Field The field
         */
        public function find(string $field): ?Field
        {
            $this->lookups->append($field);

            if (! isset($this->fields[$field])) {
                return null;
            }

            return defaultHelperField(\substr($field, (int) \strrpos($field, '.') + 1), $this->fields[$field]);
        }
    };

    app()->instance(FieldRepository::class, $repository);
    Facade::clearResolvedInstances();

    return $lookups;
}

/**
 * A real text field with the given config.
 *
 * @param  string  $handle  The field handle
 * @param  array<string, mixed>  $config  The field config
 * @return Field The field
 */
function defaultHelperField(string $handle, array $config): Field
{
    return new Field($handle, \array_merge(['type' => 'text'], $config));
}

it('returns the default of the field a dot reference names', function (): void {
    $lookups = defaultHelperFields(['header_basic.title_options' => ['default' => 'h1']]);

    expect(FieldDefaultHelper::getDefaultValueForField('header_basic.title_options'))->toBe('h1')
        ->and($lookups->getArrayCopy())->toBe(['header_basic.title_options']);
});

it('returns null when the referenced field does not exist', function (): void {
    $lookups = defaultHelperFields([]);

    expect(FieldDefaultHelper::getDefaultValueForField('missing.field'))->toBeNull()
        ->and($lookups->getArrayCopy())->toBe(['missing.field']);
});

it('joins the fieldset and field handles into one reference', function (): void {
    $lookups = defaultHelperFields(['header_basic.title_options' => ['default' => 'h2']]);

    expect(FieldDefaultHelper::getDefaultValueForFieldsetField('header_basic', 'title_options'))->toBe('h2')
        ->and($lookups->getArrayCopy())->toBe(['header_basic.title_options']);
});

it('returns null for a fieldset field that does not exist', function (): void {
    $lookups = defaultHelperFields([]);

    expect(FieldDefaultHelper::getDefaultValueForFieldsetField('header_basic', 'nope'))->toBeNull()
        ->and($lookups->getArrayCopy())->toBe(['header_basic.nope']);
});

it('returns null when the blueprint does not exist', function (): void {
    Blueprint::shouldReceive('find')->once()->with('collections.pages.page')->andReturn(null);

    expect(FieldDefaultHelper::getDefaultValueForBlueprintField('collections.pages.page', 'title'))->toBeNull();
});

it('returns null when the blueprint has no such field', function (): void {
    $blueprint = Blueprint::make('page')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text', 'default' => 'T']],
    ]]);
    Blueprint::shouldReceive('find')->once()->with('collections.pages.page')->andReturn($blueprint);

    expect(FieldDefaultHelper::getDefaultValueForBlueprintField('collections.pages.page', 'missing'))->toBeNull();
});

it('returns an inline blueprint field default', function (): void {
    $blueprint = Blueprint::make('page')->setContents(['fields' => [
        ['handle' => 'layout', 'field' => ['type' => 'text', 'default' => 'wide']],
    ]]);
    Blueprint::shouldReceive('find')->once()->with('collections.pages.page')->andReturn($blueprint);

    expect(FieldDefaultHelper::getDefaultValueForBlueprintField('collections.pages.page', 'layout'))->toBe('wide');
});

it('resolves a blueprint field that imports a fieldset field, applying its overrides', function (): void {
    $blueprint = Blueprint::make('page')->setContents(['fields' => [
        ['handle' => 'title_options', 'field' => 'header_basic.title_options', 'config' => ['default' => 'h3']],
    ]]);
    Blueprint::shouldReceive('find')->once()->with('collections.pages.page')->andReturn($blueprint);

    // The blueprint resolves the import itself, through the same repository
    defaultHelperFields(['header_basic.title_options' => ['default' => 'h1']]);

    expect(FieldDefaultHelper::getDefaultValueForBlueprintField('collections.pages.page', 'title_options'))->toBe('h3');
});
