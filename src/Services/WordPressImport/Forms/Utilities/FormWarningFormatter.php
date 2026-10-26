<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Formatter for form-related warnings.
 *
 * This class handles the formatting and display of form-specific warnings
 * such as unsupported field attributes.
 */
class FormWarningFormatter
{
    /**
     * Display unsupported form attribute warnings in the specified format.
     *
     * Groups warnings by fieldtype, then by attribute, then by form (title + ID),
     * and displays them in a structured, readable format for manual review.
     *
     * Example output:
     *
     * The following Gravity Forms fieldtypes had field attributes that are not
     * supported in Statamic and were not imported. You may need to manually configure
     * these field settings in Statamic.
     *
     *  - checkbox
     *      - Attribute: 'enableChoiceValue'
     *          - Form(s):
     *              - 'Supply Chain — Foo' (ID: 1)
     *              - 'Supply Chain — Bar' (ID: 2)
     *      ...
     *
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $warnings  Array of unsupported attribute warnings. Each warning should have:
     *                                              - field_type: string
     *                                              - attribute_name: string
     *                                              - form_title: string
     *                                              - form_id: int|string|null
     */
    public static function displayUnsupportedFormAttributeWarnings(
        OutputInterface $output,
        array $warnings
    ): void {
        // Group warnings by fieldtype, then by attribute, then by form (title + ID)
        $grouped = [];

        foreach ($warnings as $warning) {
            if (! \is_array($warning)) {
                continue;
            }

            $fieldtype = self::warningStringValue(
                $warning,
                'field_type',
                'Unknown Fieldtype'
            );
            $attributeName = self::warningStringValue(
                $warning,
                'attribute_name',
                'Unknown Attribute'
            );
            $formTitle = self::warningStringValue(
                $warning,
                'form_title',
                'Unknown Form'
            );
            $formId = self::warningNullableStringValue(
                $warning,
                'form_id'
            );
            $formKey = $formTitle.($formId !== null && $formId !== '' ? " (ID: $formId)" : '');
            $grouped[$fieldtype] ??= [];

            $grouped[$fieldtype][$attributeName] ??= [];

            // Use associative array for deduplication
            $grouped[$fieldtype][$attributeName][$formKey] = true;
        }

        ksort($grouped);

        // Display the header message
        $output->writeln('<comment>The following Gravity Forms fieldtypes had field attributes that are not supported in Statamic and were not imported. You may need to manually configure these field settings in Statamic.</comment>');
        $output->writeln('');

        // Iterate through the grouped warnings and display them in the requested format
        foreach ($grouped as $fieldtype => $attributes) {
            $output->writeln(' - '.$fieldtype);
            foreach ($attributes as $attributeName => $forms) {
                $output->writeln("     - Attribute: '".$attributeName."'");
                $output->writeln('         - Form(s):');
                foreach (\array_keys($forms) as $formKey) {
                    // Extract form name and ID from the form key
                    $formName = $formKey;
                    $formId = null;
                    if (
                        \preg_match(
                            '/^(.*?)\\s*\\(ID:\\s*(\\d+)\\)$/',
                            $formKey,
                            $matches
                        ) === 1
                    ) {
                        $formName = \trim($matches[1]);
                        $formId = $matches[2];
                    }

                    if ($formId !== null) {
                        $output->writeln("             - '".$formName."' (ID: ".$formId.')');
                    } else {
                        $output->writeln("             - '".$formName."'");
                    }
                }
            }
        }

        // Add a blank line at the end for better readability
        $output->writeln('');

        // Clean up variables to free up memory
        unset(
            $grouped,
            $fieldtype,
            $attributeName,
            $formTitle,
            $formId,
            $formKey,
            $formName,
            $matches,
        );
    }

    /**
     * Display unmapped subfield and attribute warnings in a grouped format.
     *
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $warnings  Array of unmapped subfield warnings
     */
    public static function displayUnmappedSubfieldWarnings(
        OutputInterface $output,
        array $warnings
    ): void {
        // Group warnings by fieldtype, then by subfield, then by attribute
        $grouped = [];

        // Iterate through each warning and organize into the grouping structure
        foreach ($warnings as $warning) {
            if (! \is_array($warning)) {
                continue;
            }

            // Extract the fieldtype for grouping
            $fieldtype = self::warningStringValue(
                $warning,
                'field_type',
                'Unknown Fieldtype'
            );

            // Extract the subfield label for grouping
            $subfieldLabel = self::warningStringValue(
                $warning,
                'subfield_label',
                'Unknown Subfield'
            );

            $grouped[$fieldtype] ??= [
                'subfields' => [],
                'attributes' => [],
            ];

            // Check if this is an unmapped subfield (no attribute_name) or unmapped attribute
            $attributeName = self::warningNullableStringValue(
                $warning,
                'attribute_name'
            );
            if ($attributeName === null || $attributeName === '') {
                // This is an unmapped subfield
                $grouped[$fieldtype]['subfields'][$subfieldLabel] = true;
            } else {
                $grouped[$fieldtype]['attributes'][$subfieldLabel] ??= [];

                $grouped[$fieldtype]['attributes'][$subfieldLabel][$attributeName] = true;
            }
        }

        // Sort the grouped warnings alphabetically by fieldtype
        ksort($grouped);

        // Display the header message
        $output->writeln('<comment>The following Gravity Forms fieldtypes had subfields or subfield attributes that could not be mapped to Statamic. You may need to manually review these fields and subfields in Statamic.</comment>');
        $output->writeln('');

        // Iterate through the grouped warnings and display them in the requested format
        foreach ($grouped as $fieldtype => $data) {
            // Display the fieldtype as the top-level group
            $output->writeln(' - '.$fieldtype);

            // Display unmapped subfields if any exist
            if (($data['subfields']) !== []) {
                $output->writeln('     - Unmapped Subfields:');
                foreach (\array_keys($data['subfields']) as $subfieldLabel) {
                    $output->writeln("         - '".$subfieldLabel."'");
                }
            }

            // Display unmapped subfield attributes if any exist
            if (($data['attributes']) !== []) {
                $output->writeln('     - Unmapped Subfield Attributes:');
                foreach ($data['attributes'] as $subfieldLabel => $attributes) {
                    $output->writeln("         - Subfield: '".$subfieldLabel."'");
                    foreach (\array_keys($attributes) as $attributeName) {
                        $output->writeln('             - '.$attributeName);
                    }
                }
            }
        }

        // Add a blank line at the end for better readability
        $output->writeln('');

        // Clean up variables to free up memory
        unset(
            $grouped,
            $fieldtype,
            $subfieldLabel,
            $attributeName,
            $data,
            $subfieldLabel,
            $attributes,
            $attributeName,
        );
    }

    /**
     * Display unmapped/null-mapped fieldtype warnings grouped by fieldtype, then by
     * form, then by field.
     *
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $warnings  Array of unmapped fieldtype warnings
     */
    public static function displayUnmappedFieldtypeWarnings(
        OutputInterface $output,
        array $warnings
    ): void {
        // Group warnings by fieldtype, then by form title + ID
        $grouped = [];

        // Iterate through each warning and organize into the grouping structure
        foreach ($warnings as $warning) {
            if (! \is_array($warning)) {
                continue;
            }

            // Extract the fieldtype for grouping
            $fieldtype = self::warningStringValue(
                $warning,
                'field_type',
                'Unknown Fieldtype'
            );

            // Extract the form title for grouping
            $formTitle = self::warningStringValue(
                $warning,
                'form_title',
                'Unknown Form'
            );

            // Extract the form ID for grouping
            $formId = self::warningNullableStringValue(
                $warning,
                'form_id'
            );

            // Create a unique form key combining title and ID
            $formKey = $formTitle.($formId !== null && $formId !== '' ? " (ID: $formId)" : '');

            $grouped[$fieldtype] ??= [];

            // Add this form to the fieldtype group (using associative array for deduplication)
            $grouped[$fieldtype][$formKey] = true; // Use associative array to deduplicate
        }

        // Sort the grouped warnings alphabetically by fieldtype
        ksort($grouped);

        // Display the header message
        $output->writeln('<comment>The following Gravity Forms fieldtypes could not be mapped to a Statamic fieldtype. You may need to manually review these fieldtypes and configure them in Statamic.</comment>');
        $output->writeln('');

        // Iterate through the grouped warnings and display them in the requested format
        foreach ($grouped as $fieldtype => $forms) {
            // Display the fieldtype as the top-level group
            $output->writeln(' - '.$fieldtype);

            // Display the forms subheading
            $output->writeln('     - Form(s):');

            // Sort forms alphabetically for consistent output
            $sortedForms = \array_keys($forms);
            sort($sortedForms);

            // Iterate through each form for this fieldtype
            foreach ($sortedForms as $formKey) {
                // Extract form name and ID from the form key
                $formName = $formKey;
                $formId = null;

                // Check if the form key contains an ID in parentheses
                if (
                    \preg_match(
                        '/^(.*?)\s*\(ID:\s*(\d+)\)$/',
                        $formKey,
                        $matches
                    ) === 1
                ) {
                    $formName = \trim($matches[1]);
                    $formId = $matches[2];
                }

                // Display the form with proper formatting
                if ($formId !== null) {
                    $output->writeln("         - '".$formName."' (ID: ".$formId.')');
                } else {
                    $output->writeln("         - '".$formName."'");
                }
            }
        }

        // Add a blank line at the end for better readability
        $output->writeln('');

        // Clean up variables to free up memory
        unset(
            $grouped,
            $fieldtype,
            $formTitle,
            $formId,
            $formKey,
            $formName,
            $matches,
        );
    }

    /**
     * Display unsupported embed warnings grouped by provider, then by embed type.
     *
     * Groups warnings by provider name, then by embed type, and displays them in a
     * structured format showing which embeds were not supported during import.
     *
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $warnings  Array of unsupported embed warnings. Each warning should have:
     *                                              - embed_type: string
     *                                              - provider_name: string
     *                                              - url: string
     *                                              - site: string
     *                                              - page_slug: ?string
     */
    public static function displayUnsupportedEmbedWarnings(
        OutputInterface $output,
        array $warnings
    ): void {
        // ---
        // Group warnings by provider, then by embed type
        // ---

        // Initialize the grouped warnings array
        $grouped = [];

        // Iterate through each warning and organize into the grouping structure
        foreach ($warnings as $warning) {
            if (! \is_array($warning)) {
                continue;
            }

            // Extract the provider name for grouping
            $providerName = self::warningStringValue(
                $warning,
                'provider_name',
                'unknown'
            );

            // Extract the embed type for grouping
            $embedType = self::warningStringValue(
                $warning,
                'embed_type',
                'none'
            );

            $grouped[$providerName] ??= [];

            $grouped[$providerName][$embedType] ??= [];

            // Add this warning to the group
            $grouped[$providerName][$embedType][] = [
                'url' => self::warningStringValue(
                    $warning,
                    'url',
                    ''
                ),
                'page_slug' => self::warningNullableStringValue(
                    $warning,
                    'page_slug'
                ),
                'site' => self::warningStringValue(
                    $warning,
                    'site',
                    ''
                ),
            ];
        }

        // Sort the grouped warnings alphabetically by provider name
        ksort($grouped);

        // Display the header message
        $output->writeln('<comment>The following WordPress embed providers are not currently supported and were not imported. You may need to manually review these embeds in Statamic.</comment>');
        $output->writeln('');

        // Iterate through the grouped warnings and display them
        foreach ($grouped as $providerName => $embedTypes) {
            // Display the provider name as the top-level group
            $output->writeln(" - Provider: '$providerName'");

            // Sort embed types alphabetically
            ksort($embedTypes);

            // Iterate through each embed type for this provider
            foreach ($embedTypes as $embedType => $typeWarnings) {
                // Display the embed type
                $output->writeln("     - Embed type: '$embedType'");

                // ---
                // Display count of occurrences
                // ---

                // Count warnings for this embed type
                $count = \count($typeWarnings);

                // Output occurrence count
                $output->writeln("         - Occurrences: $count");

                // ---
                // Display first few URLs as examples (limit to 3)
                // ---

                // Initialize example counter
                $exampleCount = 0;

                // Loop through warnings to display examples
                foreach ($typeWarnings as $warning) {
                    // Check if example limit reached
                    if ($exampleCount >= 3) {
                        // Break loop after showing 3 examples
                        break;
                    }

                    // Extract URL from warning
                    $url = self::warningStringValue(
                        $warning,
                        'url',
                        ''
                    );

                    // Extract page slug from warning
                    $pageSlug = self::warningNullableStringValue(
                        $warning,
                        'page_slug'
                    );

                    // Extract site from warning
                    $site = self::warningStringValue(
                        $warning,
                        'site',
                        ''
                    );

                    // Check if URL exists
                    if ($url !== '') {
                        // Initialize display text with URL
                        $displayText = $url;

                        // Check if page slug exists
                        if ($pageSlug !== null && $pageSlug !== '') {
                            // Append page slug and site to display text
                            $displayText .= " (page: $pageSlug, site: $site)";
                        } elseif ($site !== '') { // Check if site exists without page slug
                            // Append site to display text
                            $displayText .= " (site: $site)";
                        }

                        // Output example URL
                        $output->writeln("         - Example: $displayText");

                        // Increment example counter
                        $exampleCount++;
                    }
                }

                // ---
                // If there are more warnings than examples shown, indicate that
                // ---

                // Check if more warnings exist than examples shown
                if ($count > 3) {
                    // Calculate remaining warning count
                    $remaining = $count - 3;

                    // Output remaining count message
                    $output->writeln("         - ... and $remaining more");
                }
            }
        }

        // Add a blank line at the end for better readability
        $output->writeln('');

        // Clean up variables to free up memory
        unset(
            $grouped,
            $providerName,
            $embedType,
            $typeWarnings,
            $count,
            $exampleCount,
            $warning,
            $url,
            $pageSlug,
            $site,
            $displayText,
            $remaining
        );
    }

    /**
     * Display unaddressed shortcode warnings grouped by shortcode name.
     *
     * Groups warnings by shortcode name and displays them in a structured format
     * showing which shortcodes were not supported during import.
     *
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $warnings  Array of unaddressed shortcode warnings. Each warning should have:
     *                                              - shortcode_name: string
     *                                              - original: string
     *                                              - site: string
     *                                              - page_slug: ?string
     *                                              - page_id: string
     *                                              - page_link: string
     */
    public static function displayUnaddressedShortcodeWarnings(
        OutputInterface $output,
        array $warnings
    ): void {
        // ---
        // Group warnings by shortcode name
        // ---

        // Initialize the grouped warnings array
        $grouped = [];

        // Iterate through each warning and organize into the grouping structure
        foreach ($warnings as $warning) {
            if (! \is_array($warning)) {
                continue;
            }

            // Extract the shortcode name for grouping
            $shortcodeName = self::warningStringValue(
                $warning,
                'shortcode_name',
                'unknown'
            );

            $grouped[$shortcodeName] ??= [];

            // Add this warning to the group
            $grouped[$shortcodeName][] = [
                'original' => self::warningStringValue(
                    $warning,
                    'original',
                    ''
                ),
                'page_slug' => self::warningNullableStringValue(
                    $warning,
                    'page_slug'
                ),
                'site' => self::warningStringValue(
                    $warning,
                    'site',
                    ''
                ),
            ];
        }

        // Sort the grouped warnings alphabetically by shortcode name
        ksort($grouped);

        // Display the header message
        $output->writeln('<comment>The following WordPress shortcodes are not currently supported and were not imported. You may need to manually review these shortcodes in Statamic.</comment>');
        $output->writeln('');

        // Iterate through the grouped warnings and display them
        foreach ($grouped as $shortcodeName => $shortcodeWarnings) {
            // Display the shortcode name as the top-level group
            $output->writeln(" - Shortcode: '[$shortcodeName]'");

            // ---
            // Display count of occurrences
            // ---

            // Count warnings for this shortcode
            $count = \count($shortcodeWarnings);

            // Output occurrence count
            $output->writeln("     - Occurrences: $count");

            // ---
            // Display first few examples (limit to 3)
            // ---

            // Initialize example counter
            $exampleCount = 0;

            // Loop through warnings to display examples
            foreach ($shortcodeWarnings as $warning) {
                // Check if example limit reached
                if ($exampleCount >= 3) {
                    // Break loop after showing 3 examples
                    break;
                }

                // Extract original shortcode text
                $original = self::warningStringValue(
                    $warning,
                    'original',
                    ''
                );

                // Extract page slug from warning
                $pageSlug = self::warningNullableStringValue(
                    $warning,
                    'page_slug'
                );

                // Extract site from warning
                $site = self::warningStringValue(
                    $warning,
                    'site',
                    ''
                );

                // Check if original shortcode exists
                if ($original !== '') {
                    // Initialize display text with original shortcode
                    $displayText = $original;

                    // Check if page slug exists
                    if ($pageSlug !== null && $pageSlug !== '') {
                        // Append page slug and site to display text
                        $displayText .= " (page: $pageSlug, site: $site)";
                    } elseif ($site !== '') { // Check if site exists without page slug
                        // Append site to display text
                        $displayText .= " (site: $site)";
                    }

                    // Output example shortcode
                    $output->writeln("     - Example: $displayText");

                    // Increment example counter
                    $exampleCount++;
                }
            }

            // ---
            // If there are more warnings than examples shown, indicate that
            // ---

            // Check if more warnings exist than examples shown
            if ($count > 3) {
                // Calculate remaining warning count
                $remaining = $count - 3;

                // Output remaining count message
                $output->writeln("     - ... and $remaining more");
            }
        }

        // Add a blank line at the end for better readability
        $output->writeln('');

        // Clean up variables to free up memory
        unset(
            $grouped,
            $shortcodeName,
            $shortcodeWarnings,
            $count,
            $exampleCount,
            $warning,
            $original,
            $pageSlug,
            $site,
            $displayText,
            $remaining
        );
    }

    /**
     * Display malformed self-closing shortcode warnings grouped by shortcode name.
     *
     * Groups warnings by shortcode name and shows how each malformed attribute value
     * was normalized (a glued-on self-closing slash stripped, e.g. [table id=2/] → id="2"),
     * naming the affected entry so source-content defects stay visible.
     *
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $warnings  Array of malformed shortcode warnings. Each warning should have:
     *                                              - shortcode_name: string
     *                                              - attribute: string
     *                                              - raw_value: string
     *                                              - normalized_value: string
     *                                              - original: string
     *                                              - page_url: ?string
     */
    public static function displayMalformedShortcodeWarnings(
        OutputInterface $output,
        array $warnings
    ): void {
        // ---
        // Group warnings by shortcode name
        // ---

        // Initialize the grouped warnings array
        $grouped = [];

        // Iterate through each warning and organize into the grouping structure
        foreach ($warnings as $warning) {
            if (! \is_array($warning)) {
                continue;
            }

            // Extract the shortcode name for grouping
            $shortcodeName = self::warningStringValue(
                $warning,
                'shortcode_name',
                'unknown'
            );

            $grouped[$shortcodeName] ??= [];

            // Add this warning to the group
            $grouped[$shortcodeName][] = [
                'original' => self::warningStringValue(
                    $warning,
                    'original',
                    ''
                ),
                'attribute' => self::warningStringValue(
                    $warning,
                    'attribute',
                    ''
                ),
                'raw_value' => self::warningStringValue(
                    $warning,
                    'raw_value',
                    ''
                ),
                'normalized_value' => self::warningStringValue(
                    $warning,
                    'normalized_value',
                    ''
                ),
                'page_url' => self::warningNullableStringValue(
                    $warning,
                    'page_url'
                ),
            ];
        }

        // Sort the grouped warnings alphabetically by shortcode name
        ksort($grouped);

        // Display the header message
        $output->writeln('<comment>The following WordPress shortcodes were malformed: a glued-on self-closing slash was stripped from an attribute value during import (e.g. [table id=2/] became id="2"). The import normalized them automatically, but the source content should be corrected.</comment>');
        $output->writeln('');

        // Iterate through the grouped warnings and display them
        foreach ($grouped as $shortcodeName => $shortcodeWarnings) {
            // Display the shortcode name as the top-level group
            $output->writeln(" - Shortcode: '[$shortcodeName]'");

            // Count warnings for this shortcode
            $count = \count($shortcodeWarnings);

            // Output occurrence count
            $output->writeln("     - Occurrences: $count");

            // ---
            // Display first few examples (limit to 3)
            // ---

            // Initialize example counter
            $exampleCount = 0;

            // Loop through warnings to display examples
            foreach ($shortcodeWarnings as $warning) {
                // Break loop after showing 3 examples
                if ($exampleCount >= 3) {
                    break;
                }

                // Extract original shortcode text
                $original = self::warningStringValue(
                    $warning,
                    'original',
                    ''
                );

                // Extract the affected attribute name
                $attribute = self::warningStringValue(
                    $warning,
                    'attribute',
                    ''
                );

                // Extract the raw (pre-normalization) value
                $rawValue = self::warningStringValue(
                    $warning,
                    'raw_value',
                    ''
                );

                // Extract the normalized value
                $normalizedValue = self::warningStringValue(
                    $warning,
                    'normalized_value',
                    ''
                );

                // Extract the affected entry URL
                $pageUrl = self::warningNullableStringValue(
                    $warning,
                    'page_url'
                );

                // Initialize display text with the original shortcode (or its name as a fallback)
                $displayText = $original !== '' ? $original : "[$shortcodeName]";

                // Show how the attribute value was normalized
                $displayText .= " ($attribute: '$rawValue' -> '$normalizedValue')";

                // Append the affected entry when known
                if ($pageUrl !== null && $pageUrl !== '') {
                    // Add entry URL to display text
                    $displayText .= " (entry: $pageUrl)";
                }

                // Output example shortcode
                $output->writeln("     - Example: $displayText");

                // Increment example counter
                $exampleCount++;
            }

            // ---
            // If there are more warnings than examples shown, indicate that
            // ---

            // Check if more warnings exist than examples shown
            if ($count > 3) {
                // Calculate remaining warning count
                $remaining = $count - 3;

                // Output remaining count message
                $output->writeln("     - ... and $remaining more");
            }
        }

        // Add a blank line at the end for better readability
        $output->writeln('');

        // Clean up variables to free up memory
        unset(
            $grouped,
            $shortcodeName,
            $shortcodeWarnings,
            $count,
            $exampleCount,
            $warning,
            $original,
            $attribute,
            $rawValue,
            $normalizedValue,
            $pageUrl,
            $displayText,
            $remaining
        );
    }

    /**
     * Read a warning value as a string.
     *
     * @param  array<mixed>  $warning  The warning payload
     * @param  string  $key  The key to read
     * @param  string  $defaultValue  The fallback value
     */
    private static function warningStringValue(
        array $warning,
        string $key,
        string $defaultValue
    ): string {
        return self::mixedToString(
            $warning[$key] ?? null,
            $defaultValue
        );
    }

    /**
     * Read an optional warning value as a nullable string.
     *
     * @param  array<mixed>  $warning  The warning payload
     * @param  string  $key  The key to read
     */
    private static function warningNullableStringValue(
        array $warning,
        string $key
    ): ?string {
        $value = self::mixedToString(
            $warning[$key] ?? null,
            ''
        );
        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Normalize a mixed warning value to string.
     *
     * @param  mixed  $value  The value to normalize
     * @param  string  $defaultValue  The fallback value
     */
    private static function mixedToString(
        mixed $value,
        string $defaultValue
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $defaultValue;
    }
}
