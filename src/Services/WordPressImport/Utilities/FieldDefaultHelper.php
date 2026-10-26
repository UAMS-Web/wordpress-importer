<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Facades\Statamic\Fields\FieldRepository;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Field;

/**
 * Utility for fetching the default value of a Statamic field from a fieldset or
 * blueprint reference.
 */
class FieldDefaultHelper
{
    /**
     * Get the default value for a field given a field reference
     * (e.g., 'fieldset.handle').
     *
     * @param  string  $fieldReference  Dot notation field reference
     *                                  (e.g., 'uamsweb_header_selector_basic.title_options')
     * @return mixed The default value, or null if not found
     */
    public static function getDefaultValueForField(
        string $fieldReference
    ): mixed {
        $field = FieldRepository::find($fieldReference);
        if ($field !== null) {
            return $field->defaultValue();
        }

        return null;
    }

    /**
     * Get the default value for a field in a fieldset.
     *
     * @param  string  $fieldsetHandle  The fieldset handle (e.g., 'uamsweb_header_selector_basic')
     * @param  string  $fieldHandle  The field handle within the fieldset (e.g., 'title_options')
     * @return mixed The default value, or null if not found
     */
    public static function getDefaultValueForFieldsetField(
        string $fieldsetHandle,
        string $fieldHandle
    ): mixed {
        $field = FieldRepository::find("{$fieldsetHandle}.{$fieldHandle}");
        if ($field !== null) {
            return $field->defaultValue();
        }

        return null;
    }

    /**
     * Get the default value for a field in a blueprint.
     *
     * A blueprint field imported from a fieldset is resolved by Statamic before
     * `field()` returns it, with the blueprint's config overrides merged in, so
     * the field's own default is already the effective one. The returned field
     * carries no `field` reference key, which is why this method no longer looks
     * one up itself (#1314).
     *
     * @param  string  $blueprintHandle  The blueprint handle (e.g., 'collections.knowledge_bases.article')
     * @param  string  $fieldHandle  The field handle within the blueprint (e.g., 'title_options')
     * @return mixed The default value, or null if not found
     */
    public static function getDefaultValueForBlueprintField(
        string $blueprintHandle,
        string $fieldHandle
    ): mixed {
        $blueprint = Blueprint::find($blueprintHandle);
        if ($blueprint === null) {
            return null;
        }

        $field = $blueprint->field($fieldHandle);
        if (! $field instanceof Field) {
            return null;
        }

        // Inline or imported, the resolved field holds the effective default
        return $field->defaultValue();
    }
}
