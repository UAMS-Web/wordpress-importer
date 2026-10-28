<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings;

use Closure;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\RestrictionHelper;

/**
 * Extracts and maps MinervaKB settings to Statamic field values.
 *
 * This class is responsible for:
 *   - Mapping MinervaKB settings to knowledge base blueprint fields
 *   - Providing default values for missing settings
 *   - Maintaining the mapping logic between WordPress and Statamic
 *   - Handling complex conditional field dependencies and type conversions
 *
 * Follows the Single Responsibility Principle by focusing solely on settings
 * extraction and mapping.
 */
class Extractor
{
    /**
     * Extract relevant settings for mapping to Statamic knowledge base fields.
     *
     * Applies complex mapping and transformation rules to MinervaKB settings JSON
     * to produce Statamic knowledge base blueprint field values. Handles toggles,
     * conditional fields, and type conversions as required by the Statamic import
     * process.
     *
     * @param  array<int|string, mixed>  $settings  The full MinervaKB settings array
     * @param  string  $siteName  The site name for title composition
     * @return array<int|string, mixed> The extracted settings for knowledge base mapping
     */
    public function extractKnowledgeBaseSettings(
        array $settings,
        string $siteName
    ): array {
        // Normalize once, here, so every comparison below sees one representation.
        // The two MinervaKB plugin versions store the same setting differently, and
        // the migration API returns each faithfully (wordpress-importer-archive#1106).
        $settings = $this->normalize($settings);

        $result = [];

        // title
        $this->setResultField(
            $settings,
            $result,
            'breadcrumbs_home_label',
            'title',
            fn (array $settings, string $key): string => "{$siteName} ".$this->getString(
                $settings,
                $key,
                'Knowledge Base'
            )
        );

        // nav_alt_name
        $this->setResultField(
            $settings,
            $result,
            'breadcrumbs_home_label',
            'nav_alt_name',
            fn (array $settings, string $key): string => $this->getString(
                $settings,
                $key,
                'Knowledge Base'
            )
        );

        // knowledge_base_search_query
        $this->setResultField(
            $settings,
            $result,
            'home_sections_switch',
            'knowledge_base_search_query',
            function (array $settings, string $key): bool {
                // Check if 'home_sections_switch' is not set or is not explicitly true
                if (($settings[$key] ?? false) !== true) {
                    // Home sections switch is disabled, so enable search query
                    return true;
                }

                // Otherwise (if 'home_sections_switch' is explicitly true)
                // ---
                // Assign the value of 'home_sections' to a local variable for normalization and
                // further processing. A missing key is treated as empty rather than read
                // bare: this branch was unreachable for the original plugin's string
                // 'true' until wordpress-importer-archive#1106, and reaching it exposed the undefined-key warning.
                // ---
                $homeSections = $settings['home_sections'] ?? [];

                // Check if home_sections is a comma-separated string that needs normalization
                if (\is_string($homeSections)) {
                    // Convert comma-separated string to array and trim whitespace
                    $homeSections = \array_map(
                        trim(...),
                        \explode(
                            ',',
                            $homeSections
                        )
                    );
                }

                // Check if home_sections is still not an array after normalization attempt
                if (! \is_array($homeSections)) {
                    // If home_sections is not an array after normalization, return false
                    return false;
                }

                // Otherwise (if home_sections is an array)
                // ---
                // Check if 'search' is present in the home_sections array. Return true if
                // 'search' is present, otherwise false.
                // ---
                return \in_array(
                    'search',
                    $homeSections,
                    true
                );
            }
        );

        // knowledge_base_search_title
        $this->setIfResultKeyIsOne(
            $settings,
            $result,
            'knowledge_base_search_query',
            'knowledge_base_search_title',
            'search_title'
        );

        // knowledge_base_search_placeholder
        $this->setIfResultKeyIsOne(
            $settings,
            $result,
            'knowledge_base_search_query',
            'knowledge_base_search_placeholder',
            'search_placeholder'
        );

        // topic_label
        $this->setResultField(
            $settings,
            $result,
            'cpt_topic_label_name',
            'topic_label'
        );

        // topic_limit
        $this->setResultField(
            $settings,
            $result,
            'home_topics_limit',
            'topic_limit',
            fn (array $settings, string $key): int => $this->getInt(
                $settings,
                $key,
                -1
            )
        );

        // show_all_topics_label
        $this->setResultField(
            $settings,
            $result,
            'show_all_label',
            'show_all_topics_label'
        );

        // topic_description_query
        $this->setBoolIfSet(
            $settings,
            'home_topics_show_description',
            $result,
            'topic_description_query'
        );

        // restriction_enabled, restriction_global_query, restriction_message,
        // restriction_category, restriction_user_roles, and import_wordpress_notes
        $this->processRestrictionSettings(
            $settings,
            $result
        );

        // topic_search_query
        $this->setBoolIfSet(
            $settings,
            'add_topic_search',
            $result,
            'topic_search_query'
        );

        // subtopic_description_query
        $this->setBoolIfSet(
            $settings,
            'show_topic_description',
            $result,
            'subtopic_description_query'
        );

        // topic_article_count
        $this->setResultField(
            $settings,
            $result,
            'topic_articles_per_page',
            'topic_article_count',
            function (array $settings, string $key) {
                if (! isset($settings[$key])) {
                    return null;
                }

                $value = $settings[$key];

                return \is_string($value) && \is_numeric($value) ? (int) $value : $value;
            }
        );

        // topic_article_description_query
        $this->setBoolIfSet(
            $settings,
            'show_search_page_excerpt',
            $result,
            'topic_article_description_query'
        );

        // article_last_modified_query
        $this->setBoolIfSet(
            $settings,
            'show_search_page_last_edit',
            $result,
            'article_last_modified_query'
        );

        // article_last_modified_label
        $this->setIfResultKeyIsOne(
            $settings,
            $result,
            'article_last_modified_query',
            'article_last_modified_label',
            'last_modified_date_text'
        );

        // article_creator_query
        $this->setBoolIfSet(
            $settings,
            'show_article_author',
            $result,
            'article_creator_query'
        );

        // article_estimated_reading_time_query
        $this->setBoolIfSet(
            $settings,
            'show_reading_estimate',
            $result,
            'article_estimated_reading_time_query'
        );

        // toc_query
        $this->setBoolIfSet(
            $settings,
            'toc_dynamic_enable',
            $result,
            'toc_query'
        );

        // toc_heading_exclude
        $this->setResultField(
            $settings,
            $result,
            'toc_headings_exclude',
            'toc_heading_exclude',
            fn (array $settings, string $key): array => $this->getArrayFromStringOrArray(
                $settings,
                $key
            )
        );

        // toc_label
        $this->setResultField(
            $settings,
            $result,
            'toc_label'
        );

        // attachments_label
        $this->setResultField(
            $settings,
            $result,
            'article_attach_label',
            'attachments_label'
        );

        // related_articles_query
        $this->setBoolIfSet(
            $settings,
            'show_related_articles',
            $result,
            'related_articles_query'
        );

        // related_articles_label
        $this->setIfResultKeyIsOne(
            $settings,
            $result,
            'related_articles_query',
            'related_articles_label',
            'related_articles_label'
        );

        // Return the extracted settings array mapped to Statamic knowledge base fields
        return $result;
    }

    /**
     * Process restriction settings based on restrict_on and
     * restrict_article_role values.
     *
     * Sets restriction_enabled, restriction_global_query,
     * restriction_message, restriction_category,
     * restriction_user_roles, and import_wordpress_notes based on
     * the restriction configuration.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to extract from
     * @param  array<int|string, mixed>  &$result  The result array to update
     */
    private function processRestrictionSettings(
        array $settings,
        array &$result
    ): void {
        // Check if restrict_on is set and is false
        if (($settings['restrict_on'] ?? null) === false) {
            // When restrict_on is false, set restriction_enabled to false
            $result['restriction_enabled'] = false;

            // Exit early since restriction is disabled
            return;
        }

        // Check if restrict_on is true
        if (($settings['restrict_on'] ?? null) === true) {
            // Get restrict_article_role value
            $restrictArticleRole = $settings['restrict_article_role'] ?? null;

            // Check if restrict_article_role is guest-only
            if (RestrictionHelper::isGuestOnlyRole($restrictArticleRole)) {
                // Set restriction_enabled to true since restrict_on is true
                $result['restriction_enabled'] = true;

                // Set restriction_global_query to false for guest-only access
                $result['restriction_global_query'] = false;
            } else { // Otherwise
                // Set restriction_enabled to true since restrict_on is true
                $result['restriction_enabled'] = true;

                // Set restriction_global_query to true for role-based access
                $result['restriction_global_query'] = true;

                // Check if restrict_article_message is set
                if (isset($settings['restrict_article_message'])) {
                    // Set restriction_message from restrict_article_message
                    $result['restriction_message'] = $settings['restrict_article_message'];
                }

                // Apply restriction fields using shared helper
                RestrictionHelper::applyRestrictionFields(
                    $result,
                    $restrictArticleRole
                );
            }

            // Clean up variables to free up memory
            unset(
                $restrictArticleRole
            );
        }
    }

    /**
     * Normalize the two representations MinervaKB settings arrive in.
     *
     * **The two plugin versions store booleans differently, and neither the API nor
     * the exporter is at fault.** The UAMS fork stores real JSON booleans; the original
     * plugin stores the strings `'true'` and `'false'`. Measured on one site carrying
     * both option rows in one payload (#1106): `uamswp-minerva-kb-options` served
     * `restrict_on=false` while `minerva-kb-options` served `restrict_on='false'` —
     * same site, same request. The exporter normalizes to real booleans, which is
     * why a settings export and the endpoint agree on fork sites and disagree on
     * original-plugin sites.
     *
     * **A string boolean is not merely skipped by strict comparison — it is inverted
     * by a cast.** `restrict_on` is compared with `===` and so matches neither branch,
     * silently importing a restricted knowledge base unrestricted. The ten other
     * boolean settings go through `getBool()`, whose `(bool)` cast turns `'false'`
     * into `true` — measured: `(bool) 'false'` is `true` in PHP, because it is a
     * non-empty string. So on an original-plugin site every one of those settings
     * with the value `'false'` imported as enabled.
     *
     * **Only the exact strings `'true'` and `'false'` are converted**, because those
     * are the two spellings the original plugin writes. `filter_var()` with
     * `FILTER_VALIDATE_BOOLEAN` would also convert `'1'`, `'0'`, `'yes'` and `'on'`,
     * and a setting legitimately holding one of those as a string would be silently
     * retyped. Nothing measured needs the wider set.
     *
     * **`restrict_article_role` arrives with its quotes escaped** — `[\"guest\"]`
     * rather than `["guest"]` — and `RestrictionHelper::isGuestOnlyRole()` decodes
     * it as JSON, so the escaped form fails the decode and the guest-only branch is
     * never taken. The backslashes are removed here so the helper sees the same value
     * from either source. That key alone is unescaped: it is the one measured, and
     * stripping slashes from every string in the array would corrupt a setting that
     * legitimately contains one.
     *
     * **Why this lives in the extractor rather than the loader.** The loader knows
     * which option row it read, which makes it the natural place — but the extractor
     * is the only consumer that compares these values, and normalizing at its entry
     * makes it correct regardless of which loader path fed it. The loader was also
     * under concurrent change (wordpress-importer-archive#1107) when this was written. If a second consumer of
     * the raw settings appears, moving this to the loader is the right follow-up.
     *
     * Top-level values only. The measured settings are flat, and recursing into
     * nested arrays would retype values whose shape has not been examined.
     *
     * @param  array<int|string, mixed>  $settings  The settings as the loader supplied them
     * @return array<int|string, mixed> The same settings with one representation
     */
    private function normalize(
        array $settings
    ): array {
        foreach ($settings as $key => $value) {
            // Convert the original plugin's string booleans to real booleans.
            if ($value === 'true') {
                $settings[$key] = true;

                continue;
            }

            if ($value === 'false') {
                $settings[$key] = false;

                continue;
            }

            // Remove the backslashes the original plugin leaves in any escaped member.
            //
            // **Applied by SHAPE rather than by name (wordpress-importer-archive#1118).** wordpress-importer-archive#1106 fixed this on
            // `restrict_article_role` alone, and the escaped set is not a fixed list of names:
            // it tracks which settings a site has CONFIGURED, so it grows site by site — eight
            // members on two sites measured, ten on a third, with most of the corpus unread.
            // A name list compiled from one site is wrong by construction.
            //
            // **The `\"` test is what keeps this safe, and it is the same test wordpress-importer-archive#1106 already
            // used** — only the key filter was by name. An unconditional `stripslashes()` would
            // destroy any value legitimately carrying a backslash: measured, `C:\Users\josh`
            // becomes `C:Usersjosh` and `/^\d+\.\d+$/` becomes `/^d+.d+$/`. Neither contains
            // an escaped quote, so neither is touched here.
            if (\is_string($value) && \str_contains($value, '\\"')) {
                $settings[$key] = \stripslashes($value);
            }
        }

        return $settings;
    }

    /**
     * Retrieve a string value from the settings array.
     *
     * Returns the value for the given key as a string, or the default if the key
     * is missing or empty.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to extract from
     * @param  string  $key  The key to look up
     * @param  string  $default  The default value if the key is missing or empty
     * @return string The string value from settings or the default
     */
    private function getString(
        array $settings,
        string $key,
        string $default = ''
    ): string {
        // Read the raw value from settings using null when missing
        $value = $settings[$key] ?? null;

        // Check if the value is one of the empty scalars; a missing key, null and an array
        // take the default below, as values that are not scalar
        if (
            in_array($value, [false, '', 0, '0'], true)
        ) {
            return $default;
        }

        // Return scalar-like values as strings, otherwise use the default
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Retrieve a boolean value from settings.
     *
     * Returns true if the value is truthy, false if falsy, or the default if not
     * set.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to extract from
     * @param  string  $key  The key to look up
     * @param  bool  $default  The default boolean if the key is not set
     * @return bool True, false, or the default
     */
    private function getBool(
        array $settings,
        string $key,
        bool $default = false
    ): bool {
        // Check if the key exists in settings
        return isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    /**
     * Retrieve an integer value from the settings array.
     *
     * Returns the integer value for the given key, or the default if not set or
     * not numeric.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to extract from
     * @param  string  $key  The key to look up
     * @param  int  $default  The default integer if the key is missing or not numeric
     * @return int The integer value from settings or the default
     */
    private function getInt(
        array $settings,
        string $key,
        int $default
    ): int {
        // Get the value from settings or use default if key doesn't exist
        $value = $settings[$key] ?? $default;

        // Convert to integer if numeric, otherwise return default
        return \is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Retrieve an array from a comma-separated string or array value in settings.
     *
     * If the value is a string, splits it by commas and trims whitespace. If the
     * value is already an array, returns it as-is. Returns the default if empty.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to extract from
     * @param  string  $key  The key to look up
     * @param  array<int|string, mixed>  $default  The default array if the key is missing or empty
     * @return array<int|string, mixed> The resulting array from settings or the default
     */
    private function getArrayFromStringOrArray(
        array $settings,
        string $key,
        array $default = []
    ): array {
        // Read raw value once for consistent type handling
        $value = $settings[$key] ?? null;

        // Return the default for an empty string; a missing key, null, false, 0 and an empty
        // array take it below, as values that are neither a list nor a string
        if (
            in_array($value, ['', '0'], true)
        ) {
            return $default;
        }

        // If value is already an array, return it as-is
        if (\is_array($value)) {
            return $value;
        }

        // Return default when value is not a string
        if (! \is_string($value)) {
            return $default;
        }

        // Split comma-separated string and trim whitespace from each element
        return \array_map(
            trim(...),
            \explode(
                ',',
                $value
            )
        );
    }

    /**
     * Set a result key to a boolean value if the settings key is set.
     *
     * Convenience wrapper around setResultField that automatically converts
     * settings values to boolean format using getBool.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to check
     * @param  string  $key  The key to check
     * @param  array<int|string, mixed>  &$result  The result array to update
     * @param  string  $resultKey  The key to set in the result
     */
    private function setBoolIfSet(
        array $settings,
        string $key,
        array &$result,
        string $resultKey
    ): void {
        // Use setResultField with getBool as the getter for boolean conversion
        $this->setResultField(
            $settings,
            $result,
            $key,
            $resultKey,
            fn (array $settings, string $key): bool => $this->getBool(
                $settings,
                $key
            )
        );
    }

    /**
     * Set a result key if another result key is true.
     *
     * Used for conditional mapping where a field is only set if a related toggle
     * is enabled. Uses setResultField internally for consistent behavior.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to check
     * @param  array<int|string, mixed>  &$result  The result array to update
     * @param  string  $checkKey  The result key to check for true
     * @param  string  $setKey  The result key to set
     * @param  string  $settingsKey  The settings key to use
     */
    private function setIfResultKeyIsOne(
        array $settings,
        array &$result,
        string $checkKey,
        string $setKey,
        string $settingsKey
    ): void {
        // Check if the conditional key, which is always set before this is asked, is true
        if ($result[$checkKey] === true) {
            // Use setResultField to set the dependent field if condition is met
            $this->setResultField(
                $settings,
                $result,
                $settingsKey,
                $setKey
            );
        }
    }

    /**
     * Set a result field using a getter or default string getter.
     *
     * Primary method for field assignment. If no getter is provided, uses
     * getString as the default getter. The getter callback receives the full
     * settings array and the specific key being processed.
     *
     * @param  array<int|string, mixed>  $settings  The settings array to extract from
     * @param  array<int|string, mixed>  &$result  The result array to update
     * @param  string  $settingsKey  The key to extract from settings
     * @param  ?string  $resultKey  The key to set in the result (defaults to $settingsKey)
     * @param  Closure(array<int|string, mixed>, string): mixed|null  $getter  Optional getter callback
     */
    private function setResultField(
        array $settings,
        array &$result,
        string $settingsKey,
        ?string $resultKey = null,
        ?Closure $getter = null
    ): void {
        // Use provided getter or default to getString if none provided
        $getter ??= fn (
            array $settings,
            string $settingsKey
        ): string => $this->getString(
            $settings,
            $settingsKey
        );

        // Set the result field using the getter to extract the value
        $result[$resultKey ?? $settingsKey] = $getter(
            $settings,
            $settingsKey
        );
    }
}
