<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#380.
 *
 * The PHPStan cleanup regression removed the wildcard-matching branch from
 * FieldtypeMappingTrait::applyValueTranslation(). That branch handled
 * `value_translation` keys containing `*`, applying self::matchesPattern() and
 * self::resolveWildcards() so a mapping like `prefix_* => WILD-*` would
 * translate `prefix_hello` into `WILD-hello`. With the branch gone, wildcard
 * value translations silently passed the original value through unchanged.
 *
 * The fix restores the wildcard branch in both translation-application blocks
 * of applyValueTranslation(), preserving exact-match precedence: the wildcard
 * loop only runs when no exact key matched.
 *
 * applyValueTranslation() is exercised via reflection on the real
 * FieldtypeMapping consumer. The translation logic is pure and reaches no
 * facades, container, or Statamic repositories, so no app bootstrap is needed.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue380RegressionTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke the protected static FieldtypeMapping::applyValueTranslation() via
 * reflection, passing the null/empty collaborators the pure translation path
 * never touches.
 *
 * @param  array<int|string, mixed>  $config
 */
function translateFieldtypeValue(mixed $value, array $config, string $statamicAttr): mixed
{
    $method = new ReflectionMethod(
        FieldtypeMapping::class,
        'applyValueTranslation'
    );
    $method->setAccessible(true);

    return $method->invoke(
        null,
        $value,
        $config,
        null,
        [],
        null,
        null,
        $statamicAttr
    );
}

it('applies wildcard value translations while honoring exact-match precedence', function (string $value, array $config, string $expected): void {
    expect(translateFieldtypeValue($value, $config, 'target_field'))->toBe($expected);
})->with([
    'wildcard pattern translates value' => [
        'prefix_hello',
        [
            'value_translation' => [
                'prefix_*' => 'WILD-*',
            ],
        ],
        'WILD-hello',
    ],
    'exact match takes precedence over wildcard' => [
        'prefix_hello',
        [
            'value_translation' => [
                'prefix_hello' => 'EXACT',
                'prefix_*' => 'WILD-*',
            ],
        ],
        'EXACT',
    ],
    'non-matching value is unchanged' => [
        'other_value',
        [
            'value_translation' => [
                'prefix_*' => 'WILD-*',
            ],
        ],
        'other_value',
    ],
]);
