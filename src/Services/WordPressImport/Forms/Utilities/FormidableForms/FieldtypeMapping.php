<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Provides mapping from Formidable Forms field types to Statamic fieldtypes.
 *
 * This class is responsible for:
 *   - Maintaining a mapping array of Formidable Forms field types to Statamic
 *     fieldtypes
 *   - Providing lookup methods for mapping
 *   - Identifying unmapped Formidable Forms fieldtypes in a forms export
 */
class FieldtypeMapping
{
    use FieldtypeMappingTrait;

    /**
     * Mapping from Formidable Forms field types to Statamic fieldtypes.
     *
     * Each mapping is an array with at least a 'default' key:
     *   'formidable_forms_type' => [ 'default' => 'statamic_fieldtype' ]
     *
     * For attribute-based mapping, use an 'attributes' key:
     *   'formidable_forms_type' => [
     *     'attributes' => [
     *       'attribute_name' => [
     *         'attribute_value' => 'statamic_fieldtype',
     *         ...
     *       ],
     *       ...
     *     ],
     *     'default' => 'fallback_statamic_fieldtype',
     *   ]
     *
     * Special values:
     *   - null: Maps to 'text' fieldtype as fallback
     *   - false: Completely suppresses the field from import (field is skipped)
     *
     * Example (simple):
     *   'text' => [ 'default' => 'text' ]
     *
     * Example (attribute-based):
     *   'multi_choice' => [
     *     'attributes' => [
     *       'inputType' => [
     *         'checkbox' => 'checkboxes',
     *         'radio' => 'radio',
     *       ],
     *     ],
     *     'default' => null,
     *   ]
     *
     * Example (suppressed):
     *   'page_break' => [ 'default' => false ]
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $mapping = [
        // Free Fields

        // Submit
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/
        //   - https://cancer.uams.edu/the-gala-for-life/
        //   - https://communications.uams.edu/web/
        //       - Vanity Request
        //       - WordPress Training Request
        //       - IIS Web Config Generator
        //       - Portrait Attire
        //       - Web Edit Request
        //       - Canva Teams Application
        //       - YouTube Live Link Generator
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://medicine.uams.edu/neurology/
        //
        // Attributes:
        //   - Field Label
        //       - type: text
        //       - default: Submit
        //   - Update Button Text
        //       - type: text
        //       - default: Update
        //   - Submit Button Position
        //       - type: select
        //       - default: Default
        //       - options:
        //           - Default
        //           - Center
        //           - Full Width
        //           - Inline
        //           - None
        //   - Add Start over button
        //       - type: checkbox
        //       - default: false
        //   - CSS Layout Classes
        //       - type: text
        //       - default: (blank)
        //   - Field Key
        //       - type: text
        //   - Conditional Logic
        // --------------------------------------------------------------------------------
        'submit' => ['default' => false],

        // User ID
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/web/
        //       - Support Request
        //       - UAMSHealth.com Provider Profile Data Collection Form
        // --------------------------------------------------------------------------------
        'user_id' => ['default' => false],

        // Captcha
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/
        // --------------------------------------------------------------------------------
        'captcha' => ['default' => false],

        // Checkboxes
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/the-gala-for-life/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://inside.uams.edu/cancer-service-line/
        // --------------------------------------------------------------------------------
        'checkbox' => ['default' => 'checkboxes'],

        // Email Address
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - (all sites)
        // --------------------------------------------------------------------------------
        'email' => ['default' => 'email'],

        // Hidden
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/web/
        //       - UAMSHealth.com Provider Profile Data Collection Form
        //           - Reference Webpages
        //           - Faculty Titles
        //           - Academic Administrative Titles
        //           - Education and Training
        //           - Specialty and Subspecialty Certifications
        //           - Health Care Professional Association Memberships
        // --------------------------------------------------------------------------------
        'hidden' => ['default' => 'hidden'],

        // HTML
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://inside.uams.edu/cancer-service-line/
        // --------------------------------------------------------------------------------
        'html' => ['default' => 'html'],

        // Dropdown
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://inside.uams.edu/forms/
        //   - https://medicine.uams.edu/gme/
        // --------------------------------------------------------------------------------
        'select' => ['default' => 'select'],

        // Name
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/
        //   - https://cancer.uams.edu/community-outreach-and-engagement/
        //   - https://cancer.uams.edu/patients-family/
        //   - https://communications.uams.edu/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://inside.uams.edu/cancer-service-line/
        //   - https://inside.uams.edu/forms/
        //   - https://medicine.uams.edu/gme/
        //   - https://students.uams.edu/financial-services/
        // --------------------------------------------------------------------------------
        'name' => ['default' => 'name'],

        // Number
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/the-gala-for-life/
        //   - https://communications.uams.edu/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        // --------------------------------------------------------------------------------
        'number' => ['default' => 'float'],

        // Paragraph Text
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - (94% of sites)
        // --------------------------------------------------------------------------------
        'textarea' => ['default' => 'textarea'],

        // Phone Number
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - (39% of sites)
        // --------------------------------------------------------------------------------
        'phone' => ['default' => 'phone'],

        // Radio Buttons
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/partners-card/
        //   - https://cancer.uams.edu/the-gala-for-life/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://inside.uams.edu/cancer-service-line/
        // --------------------------------------------------------------------------------
        'radio' => ['default' => 'radio'],

        // Single Line Text
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - (all sites)
        // --------------------------------------------------------------------------------
        'text' => ['default' => 'text'],

        // Website/URL
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/partners-card/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        // --------------------------------------------------------------------------------
        'url' => ['default' => 'url'],

        // // Payment
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // Dropping this changes what the form DOES -- it stops taking payment, and an
        // editor cannot tell that from a form that never did (wordpress-importer-archive#831).
        'credit_card' => [
            'default' => null,
            'trace_when_skipped' => true,
        ],

        // Advanced Fields

        // File Upload
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/
        //   - https://cancer.uams.edu/the-gala-for-life/
        //   - https://communications.uams.edu/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://students.uams.edu/financial-services/
        // --------------------------------------------------------------------------------
        'file' => [
            'default' => 'assets',
            'set_attributes' => [
                'default' => [
                    // <statamic_attribute> => <value>
                    'container' => 'form_entry_uploads',
                    'folder' => '[#site_handle]',
                    'dynamic' => 'id',
                    'restrict' => true,
                ],
                'specific' => [
                    // <source_fieldtype_handle> => [
                    //     <statamic_attribute> => <value>
                    // ],
                ],
            ],
        ],

        // Address
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/partners-card/
        //   - https://communications.uams.edu/creative-services/
        //   - https://inside.uams.edu/cancer-institute/
        // --------------------------------------------------------------------------------
        'address' => ['default' => 'address'],

        // Date
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/
        //   - https://communications.uams.edu/creative-services/
        //   - https://communications.uams.edu/web/
        //   - https://inside.uams.edu/cancer-institute/
        // --------------------------------------------------------------------------------
        'date' => ['default' => 'date'],

        // Page Break
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/creative-services/
        //   - https://inside.uams.edu/cancer-institute/
        //   - https://inside.uams.edu/cancer-service-line/
        // --------------------------------------------------------------------------------
        'break' => ['default' => false],

        // Time
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/web/
        // --------------------------------------------------------------------------------
        'time' => ['default' => 'time'],

        // Rich Text
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/web/
        // --------------------------------------------------------------------------------
        'rte' => ['default' => 'bard'],

        // Repeater
        // &
        // Section
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://communications.uams.edu/creative-services/
        //       - Release Form
        //       - Letterhead - 2
        //       - Business Cards - 2
        //       - Envelopes - 2
        //       - Notepads-2
        //       - Bid Specs
        //       - Creative Services Work Order 2020
        //       - Business Cards Development
        //       - How can we help?
        //       - Proof Approval Form
        //       - Creative Services Work Order 2022
        //       - Creative Services Work Order 2021 No Paging
        //       - Creative Services Work Order 2022
        //   - https://communications.uams.edu/web/
        //       - Vanity Request
        //       - Provider Clinical Title Request Form
        //       - UAMSHealth.com Provider Profile Data Collection Form
        //       - Web Edit Request
        //   - https://inside.uams.edu/cancer-institute/
        //       - Travel Grant Application
        //       - Travel Grant Application Backup
        //
        // We will need special handling for these fieldtype.
        //
        // `divider` and `end_divider` are indicative of both the "Repeater" fieldtype and
        // the "Section" fieldtype.
        // --------------------------------------------------------------------------------
        'divider' => [
            'default' => 'replicator',
            'based_on_other_attribute_value' => [
                'repeat' => [
                    '0' => 'subsection',
                    '1' => 'replicator',
                ],
            ],
        ], // Will need special handling for this fieldtype
        'end_divider' => ['default' => 'replicator'], // Will need special handling for this fieldtype

        // Signature
        // --------------------------------------------------------------------------------
        // This fieldtype is being used on active UAMS forms (as of 2025-08-15).
        //   - https://cancer.uams.edu/the-gala-for-life/
        //       - Sponsor Application
        //
        // However, that site does not have the Business license or above enabled, so the
        // signature fieldtype is being rendered as a text fieldtype on the front end.
        //
        // TODO (Post-MVP): There is no analog to this fieldtype in Statamic. A custom
        // fieldtype will need to be created to handle this fieldtype.
        // --------------------------------------------------------------------------------
        'signature' => ['default' => null],

        // Slider
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'range' => ['default' => 'range'],

        // Tags
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'tag' => ['default' => 'tags'],

        // Toggle
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'toggle' => ['default' => 'toggle'],

        // Scale
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'scale' => ['default' => null],

        // Star Rating
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'star' => ['default' => null],

        // Dynamic Field
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'data' => ['default' => null],

        // Embed Form
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'form' => ['default' => null],

        // Lookup
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'lookup' => ['default' => null],

        // Summary
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'summary' => ['default' => null],

        // Password
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        'password' => ['default' => 'password'],

        // Business license or Above

        // AI
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // 'AI' => [ 'default' => null ], // The fieldtype handle is unknown

        // Premium Version of Simply Schedule Appointments

        // Appointment
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // 'Appointment' => [ 'default' => null ], // The fieldtype handle is unknown

        // Surveys and Polls Plugin

        // Likert Scale
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // 'Likert Scale' => [ 'default' => null ], // Would likely map to `likert_scale`

        // Net Promoter Score (NPS)
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // 'NPS' => [ 'default' => null ], // May require custom fieldtype for full mapping // The fieldtype handle is unknown

        // Ranking
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // 'Ranking' => [ 'default' => null ], // May require custom fieldtype for full mapping // The fieldtype handle is unknown

        // Pricing Fields

        // Product
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // Dropping this changes what the form DOES -- it stops taking payment, and an
        // editor cannot tell that from a form that never did (wordpress-importer-archive#831).
        'product' => [
            'default' => null,
            'trace_when_skipped' => true,
        ],

        // Quantity
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // Dropping this changes what the form DOES -- it stops taking payment, and an
        // editor cannot tell that from a form that never did (wordpress-importer-archive#831).
        'quantity' => [
            'default' => null,
            'trace_when_skipped' => true,
        ],

        // Total
        // --------------------------------------------------------------------------------
        // This fieldtype is not being used on any active UAMS forms (as of 2025-08-15).
        // --------------------------------------------------------------------------------
        // Dropping this changes what the form DOES -- it stops taking payment, and an
        // editor cannot tell that from a form that never did (wordpress-importer-archive#831).
        'total' => [
            'default' => null,
            'trace_when_skipped' => true,
        ],

    ];

    /**
     * Maps Formidable Forms field attributes to Statamic field attributes.
     *
     * This array defines how Formidable Forms field attributes should be mapped to
     * Statamic field attributes during form import. Each top-level key represents
     * a Formidable Forms field attribute name.
     *
     * Each Formidable Forms attribute can have the following configuration keys:
     *   - 'statamic_attribute'
     *   - 'statamic_other_field_value'
     *   - 'statamic_other_field_value_based_on_attribute_value'
     *   - 'statamic_type_based_on_attribute_value'
     *   - 'translate_field_references'
     *   - 'alert_unsupported'
     *   - 'key_translation'
     *   - 'value_transformations'
     *   - 'exclude_fieldtypes'
     *   - 'value_prepend'
     *   - 'collapse_whitespace'
     *   - 'as_array'
     *   - 'convert_file_size'
     *   - 'as_integer'
     *   - 'convert_dollar_amount'
     *   - 'check_against_list'
     *   - 'transform_json_to_array'
     *   - 'separate_value_field_option'
     *   - 'statamic_form_config'
     *   - 'value_substitution_regex'
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'statamic_attribute'
     *
     * The Statamic field attribute name to receive the data.
     *
     * Can be:
     *   - A string: Direct mapping to a single Statamic attribute
     *   - An array with 'default' and 'specific' keys for fieldtype-specific mapping
     *   - An array with 'based_on_other_attribute_value' for conditional mapping
     *       - Can be a direct mapping or use 'default' and 'specific' structure
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the XML export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the XML export
     *   - An array with 'specific_based_on_attribute_value' for fieldtype-specific
     *     mapping based on the value of the attribute
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the XML export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the XML export
     *   - null: No mapping (attribute is not supported in Statamic)
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_attribute' => '<statamic_attribute_name>',
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_attribute' => null,
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_attribute' => [
     *             'default' => '<statamic_attribute_name>',
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => '<statamic_attribute_name>',
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 '<formidable_forms_attribute_name>' => [
     *                     '<formidable_forms_attribute_value>' => '<statamic_attribute_name>',
     *                 ],
     *             ],
     *             // OR with 'default' and 'specific' structure:
     *             'based_on_other_attribute_value' => [
     *                 'default' => [
     *                     '<formidable_forms_attribute_name>' => [
     *                         '<formidable_forms_attribute_value>' => '<statamic_attribute_name>',
     *                     ],
     *                 ],
     *                 'specific' => [
     *                     '<formidable_forms_fieldtype>' => [
     *                         '<formidable_forms_attribute_name>' => [
     *                             '<formidable_forms_attribute_value>' => '<statamic_attribute_name>',
     *                         ],
     *                     ],
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<formidable_forms_attribute_value>' => '<statamic_attribute_name>',
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'value_translation'
     *
     * Optional array for mapping Formidable Forms option values to Statamic values.
     * Keys are Formidable Forms values, values are Statamic values.
     *
     * Can be:
     *   - An array: Direct mapping of Formidable Forms values to Statamic values
     *   - An array with 'default' and 'specific' keys for fieldtype-specific mapping
     *   - An array with 'based_on_other_attribute_value' for conditional mapping
     *       - Can be a direct mapping or use 'default' and 'specific' structure
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the XML export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the XML export
     *
     * Special keys include:
     *   - '#not_set': Used when the attribute is not set in the XML export
     *   - '#not_empty': Used when the attribute is set and is not empty in the XML
     *     export
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_translation' => [
     *             '<formidable_forms_attribute_value>' => '<statamic_attribute_value>',
     *             '<formidable_forms_attribute_value>' => null,
     *             '#not_set' => '<statamic_attribute_value>',
     *             '#not_empty' => '<statamic_attribute_value>',
     *         ],
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_translation' => [
     *             'default' => [
     *                 '<formidable_forms_attribute_value>' => '<statamic_attribute_value>',
     *                 '#not_set' => '<statamic_attribute_value>',
     *                 '#not_empty' => '<statamic_attribute_value>',
     *             ],
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<formidable_forms_attribute_value>' => '<statamic_attribute_value>',
     *                     '#not_set' => '<statamic_attribute_value>',
     *                     '#not_empty' => '<statamic_attribute_value>',
     *                 ],
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 'default' => [
     *                     '<formidable_forms_attribute_name>' => [ // Name of other Formidable Forms attribute
     *                         '<formidable_forms_attribute_value>' => [ // Value of other Formidable Forms attribute
     *                             '<formidable_forms_attribute_value>' => '<statamic_attribute_value>', // Value of this Formidable Forms attribute
     *                             '#not_set' => '<statamic_attribute_value>', // Value of this Formidable Forms attribute
     *                             '#not_empty' => '<statamic_attribute_value>', // Value of this Formidable Forms attribute
     *                         ],
     *                     ],
     *                 ],
     *                 'specific' => [
     *                     '<formidable_forms_fieldtype>' => [
     *                         '<formidable_forms_attribute_name>' => [ // Name of other Formidable Forms attribute
     *                             '<formidable_forms_attribute_value>' => [ // Value of other Formidable Forms attribute
     *                                 '<formidable_forms_attribute_value>' => '<statamic_attribute_value>', // Value of this Formidable Forms attribute
     *                                 '#not_set' => '<statamic_attribute_value>', // Value of this Formidable Forms attribute
     *                                 '#not_empty' => '<statamic_attribute_value>', // Value of this Formidable Forms attribute
     *                             ],
     *                         ],
     *                     ],
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'key_translation'
     *
     * Optional array for mapping Formidable Forms keys to Statamic keys.
     *
     * Can be:
     *   - An array: Direct mapping of Formidable Forms keys to Statamic keys
     *   - An array with 'default' and 'specific' keys for fieldtype-specific mapping
     *   - An array with 'based_on_other_attribute_value' for conditional mapping
     *       - Can be a direct mapping or use 'default' and 'specific' structure
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the XML export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the XML export
     *   - An array with 'specific_based_on_attribute_value' for fieldtype-specific
     *     mapping based on the value of the attribute
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the XML export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the XML export
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'key_translation' => [
     *           '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *           '<formidable_forms_attribute_key>' => null,
     *         ],
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'key_translation' => [
     *             'default' => [
     *               '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *               '<formidable_forms_attribute_key>' => null,
     *             ],
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                   '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                   '<formidable_forms_attribute_key>' => null,
     *                 ],
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 '<formidable_forms_attribute_name>' => [
     *                     '<formidable_forms_attribute_value>' => [
     *                         '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_set' => [
     *                         '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_empty' => [
     *                         '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                 ],
     *             ],
     *             // OR with 'default' and 'specific' structure:
     *             'based_on_other_attribute_value' => [
     *                 'default' => [
     *                     '<formidable_forms_attribute_name>' => [
     *                         '<formidable_forms_attribute_value>' => [
     *                             '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                         ],
     *                     ],
     *                 ],
     *                 'specific' => [
     *                     '<formidable_forms_fieldtype>' => [
     *                         '<formidable_forms_attribute_name>' => [
     *                             '<formidable_forms_attribute_value>' => [
     *                                 '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                             ],
     *                         ],
     *                     ],
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<formidable_forms_attribute_value>' => [
     *                         '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_set' => [
     *                         '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_empty' => [
     *                         '<formidable_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'alert_unsupported'
     *
     * Optional boolean to generate an alert for unsupported
     *   attributes. Defaults to true if not specified.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'alert_unsupported' => <boolean>,
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'statamic_other_field_value'
     *
     * Optional array to set values of other Statamic field attributes when this
     * attribute has a non-empty value.
     *
     * Structure:
     *   - 'default': Array of attribute => value pairs for general use
     *   - 'specific': Array keyed by fieldtype with attribute => value pairs
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_other_field_value' => [
     *             'default' => [
     *                 '<statamic_attribute_name>' => '<statamic_attribute_value>',
     *             ],
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<statamic_attribute_name>' => '<statamic_attribute_value>',
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'statamic_other_field_value_based_on_attribute_value'
     *
     * Optional array to set other field values based on the value of this attribute.
     *
     * Structure:
     *   - 'default': Array keyed by attribute value with attribute => value pairs
     *   - 'specific': Array keyed by fieldtype, then by attribute value
     *
     * Special keys include:
     *   - '#not_set': Used when the attribute is not set in the XML export
     *   - '#not_empty': Used when the attribute is set and is not empty in the XML
     *     export
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_other_field_value_based_on_attribute_value' => [
     *             'default' => [
     *                 '<formidable_forms_attribute_value>' => [
     *                     '<statamic_attribute_name>' => '<statamic_attribute_value>',
     *                     '#not_set' => '<statamic_attribute_value>',
     *                     '#not_empty' => '<statamic_attribute_value>',
     *                 ],
     *             ],
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<formidable_forms_attribute_value>' => [
     *                         '<statamic_attribute_name>' => '<statamic_attribute_value>',
     *                         '#not_set' => '<statamic_attribute_value>',
     *                         '#not_empty' => '<statamic_attribute_value>',
     *                     ],
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'statamic_type_based_on_attribute_value'
     *
     * Optional array to change the field type based on the value of this attribute.
     *
     * Keys are attribute values (with wildcard support using `*`).
     *
     * Values are Statamic fieldtypes.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_type_based_on_attribute_value' => [
     *             '<formidable_forms_attribute_value>' => [
     *                 '<statamic_attribute_name>' => '<statamic_fieldtype>',
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'translate_field_references'
     *
     * Optional boolean to enable translation of field references (e.g., [329]) to
     * Statamic field handles.
     *
     * This feature translates field references in text values to their corresponding
     * Statamic field handles.
     *
     * It supports:
     *   - Numeric field IDs: [329] → field_handle_329
     *   - Field name references: [field_name] → current_field_name
     *   - Named field references: [field_label] → field_handle_id
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'translate_field_references' => <boolean>,
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'value_transformations'
     *
     * Optional array for applying transformations to specific values within complex
     * arrays (like choices).
     *
     * Supported transformation types:
     *   - `convert_dollar_amount`
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_transformations' => [
     *             '<formidable_forms_attribute_key>' => [
     *                 '<transformation_type>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_transformations' => [
     *             'default' => [
     *                 '<formidable_forms_attribute_key>' => [
     *                     '<transformation_type>' => <boolean>,
     *                 ],
     *             ],
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<formidable_forms_attribute_key>' => [
     *                         '<transformation_type>' => <boolean>,
     *                     ],
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'exclude_fieldtypes'
     *
     * Optional array of Statamic fieldtype handles for which this mapping should be
     * skipped.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'exclude_fieldtypes' => [
     *             '<formidable_forms_fieldtype>',
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'value_prepend'
     *
     * Optional string to prepend to the source value.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_prepend' => '<string>',
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_prepend' => [
     *             'default' => '<string>',
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => '<string>',
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'collapse_whitespace'
     *
     * Optional boolean to collapse all whitespace in the source value.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'collapse_whitespace' => <boolean>,
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'collapse_whitespace' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'as_array'
     *
     * Optional boolean to store the value as an array item.
     *
     * If true, adds the value to an array instead of storing as a string.
     *
     * Example: Converts 'extensions:jpg,jpeg,png,gif' to
     * ['extensions:jpg,jpeg,png,gif']
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'as_array' => <boolean>,
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'as_array' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'as_integer'
     *
     * Optional boolean to store the value as an integer.
     *
     * If true and the value is numeric, converts the value to an integer.
     *
     * Works with or without as_array option.
     *
     * Example: Converts "800000" to 800000 (integer)
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'as_integer' => <boolean>,
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'as_integer' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'convert_file_size'
     *
     * Optional string to convert file size to a specific unit.
     *
     * If a string, converts the file size from its current unit to the specified unit.
     *
     * Supported units:
     *   - B
     *   - KB
     *   - MB
     *   - GB
     *   - TB
     *
     * Example: 'KB' converts '800MB' to 800000
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'convert_file_size' => '<unit>',
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'convert_file_size' => [
     *             'default' => '<unit>',
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => '<unit>',
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'convert_dollar_amount'
     *
     * Optional boolean to convert dollar amounts to floats.
     *
     * If true and the value is a string, converts dollar amounts to float values.
     *
     * Removes '$' and ',' characters from the string before conversion.
     *
     * Example: Converts '$4,206.90' to 4206.9 (float)
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'convert_dollar_amount' => <boolean>,
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'convert_dollar_amount' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'check_against_list'
     *
     * Optional string to validate the attribute value against a predefined list.
     *
     * The string must be the key of an array within $validationLists in
     * FieldtypeMappingTrait.
     *
     * If a string, the attribute value is checked against the validation list with
     * that key.
     *
     * If the value is not in the list, the attribute is skipped and not mapped to
     * Statamic.
     *
     * Used for attributes that should only accept specific predefined values.
     *
     * Example: 'visibility_values' to validate visibility values
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'check_against_list' => '<validation_lists_key>',
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'check_against_list' => [
     *             'default' => '<validation_lists_key>',
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => '<validation_lists_key>',
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'transform_json_to_array'
     *
     * Boolean to transform JSON to arrays
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'transform_json_to_array' => <boolean>,
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'transform_json_to_array' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'separate_value_field_option'
     *
     * Identifies the Formidable Forms attribute used to separate values from keys.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'separate_value_field_option' => '<formidable_forms_attribute_name>',
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'separate_value_field_option' => [
     *             'default' => '<formidable_forms_attribute_name>',
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => '<formidable_forms_attribute_name>',
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'statamic_form_config'
     *
     * Optional string to map an attribute value to a Statamic form configuration
     * setting. When specified, the attribute value is mapped to a form configuration
     * key with the prefix `form_config_`.
     *
     * This is useful for attributes that should be applied at the form level rather
     * than the field level, such as form-wide settings, validation rules, or
     * submission behavior configurations.
     *
     * The mapped value will be stored as `form_config_<key>` in the Statamic field
     * configuration, where `<key>` is the value specified in this configuration.
     *
     * Only applies when the attribute has a non-empty value.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_form_config' => '<key>',
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'statamic_form_config' => [
     *             'default' => '<key>',
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => '<key>',
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'value_substitution_regex'
     *
     * Optional array to apply regular expression substitution patterns to a value.
     *
     * The array contains an associative array of regex patterns where the keys are
     * patterns and the values are replacements.
     *
     * Capturing groups from the regex pattern match can be referenced in the
     * replacement value using `$1` syntax.
     *
     * Note that variable-length lookbehind assertions are not supported, and will
     * cause the replacement to fail.
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_substitution_regex' => [
     *             '<regex_pattern_match>' => '<replacement_value>',
     *         ],
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_substitution_regex' => [
     *             'default' => [
     *                 '<regex_pattern_match>' => '<replacement_value>',
     *             ],
     *             'specific' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<regex_pattern_match>' => '<replacement_value>',
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'value_calculation'
     *
     * Optional array to apply calculations to a Formidable Forms value before it's
     * stored.
     *
     * Provide a mathematical expression, referencing the original value using
     * `[value]`.
     *
     * Can be:
     *   - A string: Direct calculation of the value
     *   - An array with 'default' and 'specific' keys for fieldtype-specific
     *     calculation
     *   - An array with 'based_on_other_attribute_value' for conditional calculation
     *       - Can be a direct mapping or use 'default' and 'specific' structure
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the XML export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the XML export
     *   - An array with 'specific_based_on_attribute_value' for fieldtype-specific
     *     calculation based on the value of the attribute
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the XML export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the XML export
     *
     * Example: '[value] * 2.5'
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_calculation' => '<calculation>',
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_calculation' => [
     *             'default' => '<calculation>',
     *             'specific' => [
     *                 '<formidable_forms_attribute_name>' => '<calculation>',
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 '<formidable_forms_attribute_name>' => [
     *                     '<formidable_forms_attribute_value>' => '<calculation>',
     *                     '#not_set' => '<calculation>',
     *                     '#not_empty' => '<calculation>',
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<formidable_forms_attribute_value>' => '<calculation>',
     *                     '#not_set' => '<calculation>',
     *                     '#not_empty' => '<calculation>',
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     *     '<formidable_forms_attribute_name>' => [
     *         'value_calculation' => [
     *             'default' => '<calculation>',
     *             'specific' => [
     *                 '<formidable_forms_attribute_name>' => '<calculation>',
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 'default' => [
     *                     '<formidable_forms_attribute_name>' => [
     *                         '<formidable_forms_attribute_value>' => '<calculation>',
     *                         '#not_set' => '<calculation>',
     *                         '#not_empty' => '<calculation>',
     *                     ],
     *                 ],
     *                 'specific' => [
     *                     '<formidable_forms_fieldtype>' => [
     *                         '<formidable_forms_attribute_name>' => [
     *                             '<formidable_forms_attribute_value>' => '<calculation>',
     *                             '#not_set' => '<calculation>',
     *                             '#not_empty' => '<calculation>',
     *                         ],
     *                     ],
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<formidable_forms_fieldtype>' => [
     *                     '<formidable_forms_attribute_value>' => '<calculation>',
     *                     '#not_set' => '<calculation>',
     *                     '#not_empty' => '<calculation>',
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'explode_string'
     *
     * Optional string to explode a comma-separated (or other delimiter) string into
     * an array.
     *
     * If set, the attribute value is exploded using the specified separator before
     * other transformations (like trim) are applied. This is useful for attributes
     * that contain multiple values separated by a delimiter.
     *
     * Can be:
     *   - A string: Direct separator for exploding the value
     *   - An array with 'default' and 'specific' keys for fieldtype-specific
     *     separators
     *
     * Example: ',' converts 'jpg,jpeg,png,gif' to ['jpg', 'jpeg', 'png', 'gif']
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'explode_string' => ',',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'explode_string' => [
     *             'default' => ',',
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => ';',
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'trim'
     *
     * Optional boolean to trim whitespace from attribute values.
     *
     * If true, removes leading and trailing whitespace from the attribute value.
     * When used with explode_string, trims each individual array element.
     *
     * Can be:
     *   - A boolean: Direct trimming of the value
     *   - An array with 'default' and 'specific' keys for fieldtype-specific trimming
     *
     * Example: true trims '  value  ' to 'value'
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'trim' => true,
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'trim' => [
     *             'default' => true,
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => false,
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeMapping = [
        // Default Value
        // --------------------------------------------------------------------------------
        // This attribute is editable as a text field in the Field Options, under the
        // "Advanced" tab.
        //
        // ## Fieldtypes with Subfields
        //
        //   The default value attribute for a fieldtype with subfields looks something like
        //   this:
        //       <default_value><![CDATA[{"first":"","middle":"","last":""}]]></default_value>
        //
        // ## Smart default values
        //
        //   Smart default values are default values that are dynamically generated. You
        //   can populate a field with a user's first and last name  from their profile,
        //   for example. Or you may populate a field with a user's address that was
        //   submitted in a previous entry.
        //
        //   There is no analog to this in Statamic. Strip these out when importing.
        //
        //   These are the available smart default values:
        //     - `[date]` (Current Date)
        //     - `[time]` (Current Time)
        //     - `[email]` (Email)
        //     - `[login]` (Login)
        //     - `[display_name]` (Display Name)
        //     - `[first_name]` (First Name)
        //     - `[last_name]` (Last Name)
        //     - `[user_id]` (User ID)
        //     - `[user_meta_key=whatever]` (User Meta)
        //     - `[user_role]` (User Role)
        //     - `[post_id]` (Post ID)
        //     - `[post_title]` (Post Title)
        //     - `[post_author_email]` (Author Email)
        //     - `[post_meta_key=whatever]` (Post Meta)
        //     - `[ip]` (IP Address)
        //     - `[auto_id start=1]` (Increment)
        //     - `[get param=whatever]` (GET/POST)
        //     - `[server param=whatever]` (SERVER)
        //
        // ## Calculated Default Values
        //
        //   Calculated default values are addressed by the `calc` attribute in the
        //   `$fieldAttributeOptionsMapping` array.
        //
        // ## Multiple Default Values for Checkboxes
        //
        //   If the fieldtype is a checkbox and more than one option is selected as a
        //   default value, the value of 'default_value' is a comma-separated list of the
        //   selected option values.
        //
        //   e.g.
        //     `<default_value><![CDATA[["Square","Circle"]]]></default_value>`
        //     as opposed to
        //     `<default_value><![CDATA[["Square"]]]></default_value>`
        //
        //   However, there are no instances of this happening in any of the active UAMS
        //   forms.
        // --------------------------------------------------------------------------------
        'default_value' => [
            'statamic_attribute' => [
                'default' => 'default',
                'based_on_attribute_value' => [
                    'default' => [
                        '[get param=*]' => 'calculation_formula_text',
                    ],
                ],
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                '[get param=*]' => [
                    'calculation_query' => true,
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => '[get param=*]',
                        'replace' => '[get_param:*]',
                    ],
                    [
                        'search' => '[date]',
                        'replace' => null,
                    ],
                    // [
                    //     'search' => '[time]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[email]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[login]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[display_name]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[first_name]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[last_name]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[user_id]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[user_meta_key=*]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[user_role]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[post_id]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[post_title]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[post_author_email]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[post_meta_key=*]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[ip]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[auto_id start=*]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                    // [
                    //     'search' => '[server param=*]', // Not used in any active UAMS forms
                    //     'replace' => null,
                    // ],
                ],
            ],
            'translate_field_references' => true, // Translates field references (e.g., `[329]`) to Statamic field handles (e.g., `test_field_329`)
        ],

        // Field Description
        'description' => [
            'statamic_attribute' => [
                'default' => 'instructions',
            ],
        ],

        // Field Key
        'field_key' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Field Options
        // --------------------------------------------------------------------------------
        // This attribute is automatically generated by Formidable Forms from a
        // combination of configuration fields.
        //
        // The mapping for these values is covered by $fieldAttributeOptionsMapping.
        // --------------------------------------------------------------------------------
        'field_options' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Field Order
        'field_order' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Form ID
        'form_id' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Field ID
        'id' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Field Label
        'name' => [
            'statamic_attribute' => [
                'default' => 'display',
            ],
        ],

        // Choices / Options
        'options' => [
            'statamic_attribute' => [
                'default' => 'options',
            ],
            'transform_json_to_array' => true,
            'separate_value_field_option' => 'separate_value',
            'key_translation' => [
                'label' => 'key',
                'value' => 'value',
                'image' => null, // Exclude image field
                'src' => null,   // Exclude src field
            ],
        ],

        // Required
        'required' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Field Type
        'type' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

    ];

    /**
     * Mapping configuration for Formidable Forms field attributes to Statamic field
     * attributes and related configuration options.
     *
     * This array defines how Formidable Forms field attributes should be mapped to
     * their Statamic equivalents, including value translations, conditional logic,
     * and other field configuration options.
     *
     * See the PHPDoc block for `$fieldAttributeMapping` for a detailed explanation
     * of the structure and configuration options.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeOptionsMapping = [
        // Section/Repeater: Section Buttons Field: Add New Label
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - repeater
        //   - section
        //
        // Label for the button to add another set in a `repeater` fieldtype.
        // --------------------------------------------------------------------------------
        'add_label' => [
            'statamic_attribute' => [
                'default' => 'button_label',
            ],
        ],

        // Address Type
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - address
        //
        // Value type: string (enum):
        //   - "europe" // not used in any active UAMS forms
        //   - "generic"
        //   - "international" // not used in any active UAMS forms
        //   - "us"
        // --------------------------------------------------------------------------------
        'address_type' => [
            'statamic_attribute' => [
                'default' => 'preset',
            ],
            'value_translation' => [
                'specific' => [
                    'address' => [
                        [
                            'search' => '#not_set',
                            'replace' => 'international',
                        ],
                        [
                            'search' => 'europe', // not used in any active UAMS forms
                            'replace' => 'international',
                        ],
                        [
                            'search' => 'generic',
                            'replace' => 'american',
                        ],
                        [
                            'search' => 'us',
                            'replace' => 'american',
                        ],
                    ],
                ],
            ],
        ],

        // Visibility
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: array<string(enum)>:
        //   - "administrator"
        //   - "editor" // not used in any active UAMS forms
        //   - "author" // not used in any active UAMS forms
        //   - "contributor" // not used in any active UAMS forms
        //   - "subscriber" // not used in any active UAMS forms
        //   - "newsletterglue_manager" // not used in any active UAMS forms
        //   - "loggedin" // not used in any active UAMS forms
        //   - "loggedout" // not used in any active UAMS forms
        //
        // If the value is blank (i.e., `[""]`), the field is visible to all users.
        // --------------------------------------------------------------------------------
        'admin_only' => [
            'statamic_attribute' => [
                'default' => 'visibility',
                'based_on_attribute_value' => [
                    'default' => [
                        '#empty' => null,
                        '[""]' => null, // Handle array with empty string
                        'loggedout' => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'administrator',
                        'replace' => 'hidden',
                    ],
                    [
                        'search' => 'editor',
                        'replace' => 'hidden',
                    ],
                    [
                        'search' => 'author',
                        'replace' => 'hidden',
                    ],
                    [
                        'search' => 'contributor',
                        'replace' => 'hidden',
                    ],
                    [
                        'search' => 'subscriber',
                        'replace' => 'hidden',
                    ],
                    [
                        'search' => 'newsletterglue_manager',
                        'replace' => 'hidden',
                    ],
                    [
                        'search' => 'loggedin',
                        'replace' => 'hidden',
                    ],
                ],
            ],
        ],

        // General — ???
        // Submit — Submit Button Position
        // --------------------------------------------------------------------------------
        // ## General — ???
        //
        //   Fieldtypes:
        //     - (general)
        //
        //   Value type: string (enum):
        //     - "block"
        //
        //   All instances of this attribute in UAMS forms have a value of "block".
        //
        //   There is no analog to this in Statamic.
        //
        // ## Submit — Submit Button Position
        //
        //   Fieldtypes:
        //     - submit
        //
        //   Value type: string (enum):
        //     - "" (Default)
        //     - "none" (None)
        //     - "center" (Center) // not used in any active UAMS forms
        //     - "full" (Full Width) // not used in any active UAMS forms
        //     - "inline" (Inline) // not used in any active UAMS forms
        //
        //   All instances of this attribute in UAMS forms have a value of "".
        //
        //   There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'align' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Conditional Logic: If Any vs. All
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: string (enum):
        //   - "any" (If Any)
        //   - "all" (All)
        // --------------------------------------------------------------------------------
        // Conditional logic mapping is handled by the `mapConditionalLogic` method.
        // --------------------------------------------------------------------------------
        'any_all' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // After Input
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        // --------------------------------------------------------------------------------
        'append' => [
            'statamic_attribute' => [
                'default' => 'append',
            ],
        ],

        // Attach this file to the email notification
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // Value type: boolean
        // --------------------------------------------------------------------------------
        'attach' => [
            'statamic_attribute' => [
                'default' => 'attach_to_email',
            ],
        ],

        // Auto Grow
        // --------------------------------------------------------------------------------
        // Automatically expand the height of the field when the text reaches the maximum
        // rows.
        //
        // The attribute appears on all fieldtypes, but it is only actually used by the
        // `textarea` fieldtype.
        //
        // Fieldtypes:
        //   - textarea
        //
        // Value type: integer (boolean)
        //
        // All of the examples in UAMS forms have a value of `0`.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'auto_grow' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Autocomplete (as autocom)
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: integer (boolean)
        //
        // All of the examples in UAMS forms have a value of `0`.
        //
        // This appears to be distinct from the `autocomplete` attribute.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'autocom' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Autocomplete (as autocomplete)
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        // --------------------------------------------------------------------------------
        'autocomplete' => [
            'statamic_attribute' => [
                'default' => 'autocomplete',
                'specific' => [
                    // <fieldtype> => <attribute>
                ],
                'based_on_attribute_value' => [
                    'specific' => [
                        'email' => [
                            'email' => null,
                        ],
                    ],
                ],
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [
                    '#not_empty' => [
                        'autocomplete_query' => true,
                    ],
                ],
                'specific' => [
                    'email' => [
                        'email' => [
                            'autocomplete_query' => null,
                        ],
                        '#not_empty' => [
                            'autocomplete_query' => true,
                        ],
                    ],
                ],
            ],
        ],

        // Validation Messages: Required
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'blank' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Bootstrap?
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // The instances of this attribute in UAMS forms are appear as one of the
        // following:
        //     "btsp":{"prepend":"","append":""}
        //     "btsp":""
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'btsp' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Default Value: Calculation
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // This configuration field replaces the `default` attribute configuration field
        // when "calculator" is selected for the default value.
        //
        // Associated config fields / attributes:
        //   - calc_type (Calculation Type)
        //   - calc_dec (Decimal Places)
        //   - is_currency (Format calculation as currency) // not used in any active UAMS forms
        //       - custom_currency (Use custom currency format) // not used in any active UAMS forms
        //           - custom_thousand_separator (Thousand separator) // not used in any active UAMS forms
        //           - custom_decimal_separator (Decimal separator) // not used in any active UAMS forms
        //           - custom_decimals (Decimals) // not used in any active UAMS forms
        //           - custom_symbol_left (Left symbol) // not used in any active UAMS forms
        //           - custom_symbol_right (Right symbol) // not used in any active UAMS forms
        //
        // Instances of this attribute in UAMS forms:
        //   - `text` fieldtype:
        //       - https://communications.uams.edu/web/
        //           - "UAMSHealth.com Provider Profile Data Collection Form" (ID: 30)
        //               - "Value Construction — First Last"
        //                   - "[433] [434]"
        //               - "Value Construction — First Middle Last"
        //                   - "[433] [424] [434]"
        //               - "Value Construction — First Nickname Last"
        //                   - "[433] \"[437]\" [434]"
        //               - "Value Construction — First Last Suffix"
        //                   - "[433] [434] [425]"
        //               - "Value Construction — First Middle Nickname Last"
        //                   - "[433] [424] \"[437]\" [434]""
        //               - "Value Construction — First Middle Last Suffix"
        //                   - "[433] [424] [434] [425]"
        //               - "Value Construction — First Nickname Last Suffix"
        //                   - "[433] \"[437]\" [434] [425]"
        //               - "Value Construction — First Middle Nickname Last Suffix"
        //                   - "[433] [424] \"[437]\" [434] [425]"
        //               - "Value Construction — Full Name"
        //                   - "[541][542][543][538][544][545][546][547]"
        //   - `number` fieldtype:
        //       - https://communications.uams.edu/web/
        //           - "Calculate a Time Estimate for Site Evaluation Prior to Theme Migration" (ID: 20)
        //               - "Estimated Total Hours Needed to Review Site Content"
        //                   - "([303]*[305]+[304]*[306]+[307]*[308]+[316]*[317])/60"
        //               - "Weeks Needed to Complete Site Review"
        //                   - "Math.ceil([309]/[310])"
        //           - "Calculate a Time Estimate for Post-migration Content Clean-up by Web Services" (ID: 21)
        //               - "Estimated Total Hours Needed to Review Site Content"
        //                   - "([318]*[319]+[320]*[321]+[322]*[323]+[324]*[325])/60"
        //               - "Weeks Needed to Complete Site Review"
        //                   - "Math.ceil([326]/[327])"
        //           - "Calculate a Time Estimate for Site Audit by Web Services" (ID: 22)
        //               - "Estimated Total Hours Needed to Review Site Content"
        //                   - "([329]*[330]+[331]*[332]+[333]*[334]+[335]*[336])/60"
        //               - "Weeks Needed to Complete Site Audit"
        //                   - "Math.ceil([337]/[338])"
        // --------------------------------------------------------------------------------
        'calc' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_other_attribute_value' => [
                    'default' => [
                        'calc_type' => [
                            '#not_set' => 'calculation_formula_math',
                            '#empty' => 'calculation_formula_math',
                            'text' => 'calculation_formula_text',
                        ],
                    ],
                ],
            ],
            'statamic_other_field_value' => [
                'default' => [ // For this attribute, generally
                    'calculation_query' => true,
                ],
                'specific' => [ // For this attribute on specific fieldtypes (the key is the fieldtype)
                    // <fieldtype> => [ <attribute> => <value> ]
                ],
            ],
            'translate_field_references' => true, // Translates field references (e.g., `[329]`) to Statamic field handles (e.g., `number_of_pages_329`)
        ],

        // Default Value: Calculation: Decimal Places
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - text
        //   - textarea
        //   - number
        //   - hidden
        //
        // This is not used in any active UAMS forms.
        //
        // See `calc` attribute for general to-do list. We'll need to change
        // `decimal_places` to `calculation_decimals`.
        // --------------------------------------------------------------------------------
        'calc_dec' => [
            'statamic_attribute' => [
                'default' => 'calculation_decimals',
            ],
        ],

        // Default Value: Calculation: Type
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - text
        //
        // Value type: string (enum):
        //   - "" (Math)
        //   - "text" (Text)
        //
        // Instances of this attribute in UAMS forms:
        //   - https://communications.uams.edu/web/
        //       - "UAMSHealth.com Provider Profile Data Collection Form" (ID: 30)
        //           - "Value Construction — First Last"
        //           - "Value Construction — First Middle Last"
        //           - "Value Construction — First Nickname Last"
        //           - "Value Construction — First Last Suffix"
        //           - "Value Construction — First Middle Nickname Last"
        //           - "Value Construction — First Middle Last Suffix"
        //           - "Value Construction — First Nickname Last Suffix"
        //           - "Value Construction — First Middle Nickname Last Suffix"
        //           - "Value Construction — Full Name"
        //
        // See `calc` attribute for general to-do list. We'll need to change
        // `display_format` to `calculation_type`, changing the options to
        // 'math' (Math) and 'text' (Text).
        // --------------------------------------------------------------------------------
        'calc_type' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [
                    'calc' => [
                        '#not_empty' => [
                            '#empty' => [
                                'calculation_type' => 'math',
                            ],
                            'text' => [
                                'calculation_type' => 'text',
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // Compact upload text
        // --------------------------------------------------------------------------------
        // The label shown when the file upload field is compacted with the frm_compact
        // CSS layout class.
        //
        // Fieldtypes:
        //   - file
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'choose_msg' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Address: City: Description
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - address
        //
        // This is not used in any active UAMS forms beyond the default Formidable Forms
        // behavior (i.e., "City"). We will not map this attribute to anything in Statamic
        // so that the standard default Statamic behavior is used.
        // --------------------------------------------------------------------------------
        'city_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // CSS Layout Classes
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // There is no direct analog to this in Statamic. But we will use it to set the
        // `visibility` and `width` values in Statamic where relevant.
        //
        // Instances of "d-none" in this attribute in UAMS forms:
        //   - https://communications.uams.edu/web/
        //       - "UAMSHealth.com Provider Profile Data Collection Form" (ID: 30)
        //           - "Value Construction — First Last"
        //           - "Value Construction — First Middle Last"
        //           - "Value Construction — First Nickname Last"
        //           - "Value Construction — First Last Suffix"
        //           - "Value Construction — First Middle Nickname Last"
        //           - "Value Construction — First Middle Last Suffix"
        //           - "Value Construction — First Nickname Last Suffix"
        //           - "Value Construction — First Middle Nickname Last Suffix"
        //           - "Value Construction — Full Name"
        // --------------------------------------------------------------------------------
        'classes' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [ // For this attribute, generally
                    //  <field_value> => [ <attribute> => <value> ]

                    // The field is hidden.
                    '*d-none*' => ['visibility' => 'hidden'],

                    // Field Widths:

                    // The field takes up one fourth of the width of the row.
                    '*frm3*' => ['width' => 25], // Not used in any active UAMS forms
                    '*frm_fourth*' => ['width' => 25], // Not used in any active UAMS forms

                    // The field takes up one third of the width of the row.
                    '*frm4*' => ['width' => 33], // Not used in any active UAMS forms
                    '*frm_third*' => ['width' => 33],

                    // The field takes up one half of the width of the row.
                    '*frm6*' => ['width' => 50],
                    '*frm_half*' => ['width' => 50],

                    // The field takes up two thirds of the width of the row.
                    '*frm8*' => ['width' => 66], // Not used in any active UAMS forms
                    '*frm_two_thirds*' => ['width' => 66], // Not used in any active UAMS forms

                    // The field takes up three fourths of the width of the row.
                    '*frm9*' => ['width' => 75], // Not used in any active UAMS forms
                    '*frm_three_fourths*' => ['width' => 75], // Not used in any active UAMS forms

                    // The 100% width classes are not mapped because they are the default width for
                    // Statamic fields:
                    //   - frm12
                    //   - frm_full

                    // Other width classes not used in any active UAMS forms and for which there is no
                    // Statamic equivalent:
                    //   - frm2 = The field takes up one sixth of the width of the row in a form
                    //     layout.
                    //   - frm_sixth = The field takes up one sixth of the width of the row.
                    //   - frm10 = The field takes up five sixths of the width of the row.

                    // Other Classes

                    // frm_inline = Align fields in a row without setting a specific width. Use
                    // frm_first frm_inline for the first field in the row and use frm_inline for any
                    // fields following that.
                    //
                    // The only instances of this in active UAMS forms are on pairs of "First" and
                    // "Last" name fields. So we'll just map them as 50% width fields.
                    '*frm_inline*' => ['width' => 50],

                    // frm_alignright = Align a field to the right side of the page.
                    //
                    // The only instances of this in active UAMS forms are on the right-most fields in
                    // a row of less-than-100% width fields. This class isn't accomplishing anything
                    // in those instances, so we will not map it to anything in Statamic.

                    // Other classes not set because there is no analog to them in Statamic:
                    //   - frm_total_big = Add this to a read-only field to display the text in large,
                    //     bold text without a border or background.
                    //   - frm_two_col = Put your radio button or checkbox options into two columns.
                    //   - frm_three_col = Put your radio button or checkbox options into three
                    //     columns.
                    //   - frm_four_col = Put your radio button or checkbox options into four columns.

                    // Other classes not set because Statamic handles the relevant behavior
                    // automatically:
                    //   - frm_first = Use frm_first whenever you want a field to be the first in a
                    //     row. This is designed to be used in conjunction with the field width
                    //     classes.

                    // Other classes not used in any active UAMS forms:
                    //   - frm_capitalize = Add this to automatically capitalize the first letter in
                    //     each word. Please note that this will not affect the saved value. It will
                    //     only affect how the value is displayed in the field.
                    //   - frm_color_block = Add a background color to the field or section.
                    //   - frm_full_upload = Make a Single File Upload field look like a Multi File
                    //     Upload field.
                    //   - frm_grid = Create a table of fields with alternating colors. Use frm_grid
                    //     on the even numbered rows.
                    //   - frm_grid_first = Create a table of fields with alternating colors. Use
                    //     frm_grid_first for the first field in the grid.
                    //   - frm_grid_odd = Create a table of fields with alternating colors. Use
                    //     frm_grid_odd on the odd numbered rows.
                    //   - frm_hidden = Add this to hide fields that you do not want visible, but are
                    //     not Hidden fields. Examples could include fields used in calculations but
                    //     that you might not want visible for aesthetic reasons, or certain uses of
                    //     Lookup fields.
                    //   - frm_inline_form = Use this class to make form content work better with
                    //     inlining. It gets added automatically if you use frm_inline_submit.
                    //   - frm_inline_submit = Use this class to make sure your inline submit button
                    //     lines up with the fields in your form.
                    //   - frm_label_justify = Use this class to increase the space between words in a
                    //     field label so that each line has equal width.
                    //   - frm_no_border_top = Add this layout class to a section or repeater field to
                    //     remove the section border styling.
                    //   - frm_pos_center = Add this class to an HTML field to horizontally center a
                    //     text.
                    //   - frm_scroll_box = If you have many checkbox or radio button options, use the
                    //     frm_scroll_box class to allow your user to easily scroll through the
                    //     options. Add this to an HTML field to allow your user to easily scroll
                    //     through text.
                    //   - frm_slidein = Add this class to the Form Class input box on the Customize
                    //     HTML page to enable a horizontal slide transition for the page turn in the
                    //     page break field.
                    //   - frm_slideup = Add this class to the Form Class input box on the Customize
                    //     HTML page to enable a vertical slide transition for the page turn in the
                    //     page break field.
                    //   - frm_total = Add this to a read-only field to display the text in bold
                    //     without a border or background.

                ],
                'specific' => [ // For this attribute on specific fieldtypes (the key is the fieldtype)
                    // <fieldtype> => [ <field_value> => [ <attribute> => <value> ] ]
                ],

            ],
        ],

        // Clear on Focus (Legacy Placeholder)
        // --------------------------------------------------------------------------------
        // The clear_on_focus attribute is a legacy field option in Formidable Forms
        // that was used to control placeholder behavior for form fields.
        //
        // Fieldtypes:
        //   - (general)
        //
        // Value type: integer (boolean)
        //
        // This is effectively a deprecated version of the `placeholder` attribute when
        // paired with the `default_value` attribute.
        // --------------------------------------------------------------------------------
        'clear_on_focus' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Time Format
        // --------------------------------------------------------------------------------
        // The clock attribute in Formidable Forms is used to specify the time format
        // for time fields. It determines whether the time field displays in 12-hour
        // format or 24-hour format.
        //
        // Fieldtypes:
        //   - time
        //
        // Value type: string (enum):
        //   - "12" (12 hour)
        //   - "24" (24 hour)
        //
        // There is no analog to this in Statamic, as Statamic's `time` fieldtype doesn't
        // support a time format attribute. It simply defaults to the 24 hour format.
        // --------------------------------------------------------------------------------
        'clock' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Confirmation Description
        // --------------------------------------------------------------------------------
        // The conf_desc attribute is used to store the description text for confirmation
        // fields in Formidable Forms. When a field has confirmation enabled, this
        // attribute contains the descriptive text that appears below the confirmation
        // field input on the frontend.
        //
        // The conf_field attribute must be enabled (i.e., have a non-blank value) in order
        // to use this attribute.
        //
        // Fieldtypes:
        //   - email
        //   - password
        //
        // Value type: string
        //
        // The conf_field attribute is only used in one active UAMS form: the "Photo Sign
        // In" form (ID: 2) on the https://communications.uams.edu/creative-services/
        // site. But that form does not appear to be used in production.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'conf_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Require Confirmation
        // --------------------------------------------------------------------------------
        // The conf_field attribute is used to enable confirmation fields in Formidable
        // Forms. When enabled, it allows users to add a confirmation input field that
        // requires users to re-enter their value to ensure accuracy. This is particularly
        // useful for important fields like email addresses, passwords, or other critical
        // information.
        //
        // Fieldtypes:
        //   - email
        //   - password
        //
        // Value type: string (enum):
        //   - "" (No Confirmation)
        //   - "inline" (Inline)
        //   - "below" (Below Field)
        //
        // Only used in one active UAMS form: the "Photo Sign In" form (ID: 2) on the
        // https://communications.uams.edu/creative-services/ site. But that form does not
        // appear to be used in production.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'conf_field' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Confirmation Placeholder
        // --------------------------------------------------------------------------------
        // The conf_input attribute is part of Formidable Forms' confirmation field
        // functionality. It serves as a placeholder text for the confirmation field input
        // that appears when a field has confirmation enabled.
        //
        // The conf_field attribute must be enabled (i.e., have a non-blank value) in order
        // to use this attribute.
        //
        // Fieldtypes:
        //   - email
        //   - password
        //
        // Value type: string
        //
        // The conf_field attribute is only used in one active UAMS form: the "Photo Sign
        // In" form (ID: 2) on the https://communications.uams.edu/creative-services/
        // site. But that form does not appear to be used in production.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'conf_input' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Validation Messages: Confirmation
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - email
        //   - ???
        //
        // Value type: string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'conf_msg' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Address: Country: Description
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - address
        //
        // This is not used in any active UAMS forms beyond the default Formidable Forms
        // behavior (i.e., "Country"). We will not map this attribute to anything in
        // Statamic so that the standard default Statamic behavior is used.
        // --------------------------------------------------------------------------------
        'country_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Default Value: Calculation: Currency: Custom Currency
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   -
        //
        // This attribute must be used in conjunction with `is_currency`. If that is
        // not enabled, the value of this attribute is ignored.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'custom_currency' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Default Value: Calculation: Currency: Custom Currency: Decimal Separator
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   -
        //
        // This attribute must be used in conjunction with `is_currency` and
        // `custom_currency`. If both are not enabled, the value of this attribute is
        // ignored.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'custom_decimal_separator' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Default Value: Calculation: Currency: Custom Currency: Decimals
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   -
        //
        // This attribute must be used in conjunction with `is_currency` and
        // `custom_currency`. If both are not enabled, the value of this attribute is
        // ignored.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'custom_decimals' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Custom Field (Post Meta)
        // --------------------------------------------------------------------------------
        // The custom_field attribute is used in Formidable Forms to specify the name of a
        // WordPress custom field (post meta) that should be associated with a form field
        // when creating or updating WordPress posts.
        //
        // Fieldtypes:
        //   - (general)
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'custom_field' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Customize HTML
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'custom_html' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Default Value: Calculation: Currency: Custom Currency: Left Symbol
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   -
        //
        // This attribute must be used in conjunction with `is_currency` and
        // `custom_currency`. If both are not enabled, the value of this attribute is
        // ignored.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'custom_symbol_left' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Default Value: Calculation: Currency: Custom Currency: Right Symbol
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   -
        //
        // This attribute must be used in conjunction with `is_currency` and
        // `custom_currency`. If both are not enabled, the value of this attribute is
        // ignored.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'custom_symbol_right' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Default Value: Calculation: Currency: Custom Currency: Thousand Separator
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   -
        //
        // This attribute must be used in conjunction with `is_currency` and
        // `custom_currency`. If both are not enabled, the value of this attribute is
        // ignored.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'custom_thousand_separator' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Permanently delete old files when replaced or when the entry is deleted
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // All of the examples in UAMS forms have a value of `false`.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'delete' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Draft
        // --------------------------------------------------------------------------------
        // The draft attribute is a field-level flag in Formidable Forms that controls
        // whether a field is visible on the frontend. The draft attribute is used to
        // temporarily hide newly added fields from frontend users while they're being
        // configured in the form builder.
        //
        // Fieldtypes:
        //   - (general)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'draft' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Upload text
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'drop_msg' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Dynamic Default Value
        // --------------------------------------------------------------------------------
        // The dyn_default_value attribute is used for dynamic default values in form
        // fields. It allows form builders to set default values that can contain
        // shortcodes and dynamic content that gets processed when the form is displayed.
        //
        // Fieldtypes:
        //   - (general)
        //
        // Value type: string
        //
        // Use in active UAMS forms:
        //   - Site: https://inside.uams.edu/cancer-service-line/
        //       - Form: Beacon Treatment Plan
        //           - Field: Disease State(s)
        //               - Value: Change Needed - No
        //   - Site: https://communications.uams.edu/web/
        //       - Form: "UAMSHealth.com Provider Profile Data Collection Form" (ID: 30)
        //           - Field: Do you know which Find-a-Doc editor is assigned to your service line or department?
        //               - Value: [get param="editor_email_query"]
        // --------------------------------------------------------------------------------
        'dyn_default_value' => [
            'statamic_attribute' => [
                'default' => 'calculation_formula',
                'based_on_attribute_value' => [
                    'default' => [
                        'Change Needed - No' => null, // Not sure what the https://inside.uams.edu/cancer-service-line/ "Beacon Treatment Plan" form is trying to do here, but it doesn't seem to do anything
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => '[get param="editor_email_query"]',
                        'replace' => '[get_param:editor_email_query]',
                    ],
                ],
            ],
            'statamic_other_field_value' => [
                'default' => [ // For this attribute, generally
                    'calculation_query' => true,
                ],
                'specific' => [ // For this attribute on specific fieldtypes (the key is the fieldtype)
                    // <fieldtype> => [ <attribute> => <value> ]
                    'radio' => [
                        'calculation_query' => true,
                    ],
                    'checkbox' => [
                        'calculation_query' => true,
                    ],
                    'select' => [
                        'calculation_query' => true,
                    ],
                ],
            ],
        ],

        // Submit: Update Button Text
        // --------------------------------------------------------------------------------
        // The edit_text attribute is used to control the text displayed on the submit
        // button when a form is in edit mode (when a user is editing an existing entry)
        // versus when they're creating a new entry. The default Formidable Forms value is
        // "Update".
        //
        // Fieldtypes:
        //   - submit
        //
        // All of the instances of this attribute in UAMS forms have a value of "Update"
        // or "".
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'edit_text' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Exclude Category
        // --------------------------------------------------------------------------------
        // The exclude_cat attribute is used to exclude specific categories from being
        // displayed or available in taxonomy-based fields. It allows you to filter out
        // certain categories from the options that users can select from.
        //
        // Fieldtypes:
        //   - (general?)
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'exclude_cat' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Name: First Name: Description
        // --------------------------------------------------------------------------------
        // The first_desc attribute stores the description text for the "First Name"
        // subfield within a Name field.
        //
        // Fieldtypes:
        //   - name
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'first_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Form Select
        // --------------------------------------------------------------------------------
        // The form_select attribute is used to specify which form should be embedded or
        // referenced by certain field types in Formidable Forms.
        //
        // ## Repeater
        //
        //   When you have a field of type 'divider' with the repeat option enabled,
        //   the form_select attribute specifies which form should be used for the
        //   repeating section.
        //
        //   This allows you to:
        //     - Create dynamic repeating sections where users can add multiple
        //       instances of the same form fields
        //     - Build forms that can handle variable amounts of data (like multiple
        //       addresses, job history, etc.)
        //
        //   Fieldtypes:
        //     - divider
        //
        //   Instances of this in active UAMS forms:
        //     - https://communications.uams.edu/web/
        //         - "UAMSHealth.com Provider Profile Data Collection Form" (ID: 30)
        //             - "Vanity Request" [ID: 2]
        //                 - "Individual Vanity / Tagged URL"
        //             - "Provider Clinical Title Request Form" [ID: 27]
        //                 - "Section A.2 — Specific Providers"
        //             - "UAMSHealth.com Provider Profile Data Collection Form" [ID: 30]
        //                 - "Faculty Titles"
        //                 - "Academic Administrative Titles"
        //                 - "Education and Training"
        //                 - "Specialty and Subspecialty Certifications"
        //                 - "Health Care Professional Association Memberships"
        //                 - "Reference Webpages"
        //             - "Web Edit Request" [ID: 40]
        //                 - "Repeater"
        //
        //   Mapping for this attribute for this fieldtype is handled elsewhere.
        //
        // ## Embed Form (This fieldtype is not used in any active UAMS forms.)
        //
        //   When you have a field of type 'form' (Embed Form), the form_select
        //   attribute contains the ID of the form that should be embedded within
        //   the current form.
        //
        //   This allows you to:
        //     - Reuse the same set of fields in multiple places
        //     - Create nested forms where one form contains another form
        //     - Build complex form structures
        //
        //   Fieldtypes:
        //     - form
        //
        //   The Embed Form fieldtype is not used in any active UAMS forms, so there are
        //   no instances of this in any active UAMS forms.
        //
        //   There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'form_select' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'divider' => null, // Mapping for this attribute for this fieldtype is handled elsewhere.
                    'form' => null, // This fieldtype is not used in any active UAMS forms.
                ],
            ],
        ],

        // Repeater: Repeat Layout
        // Repeater: Section Buttons Field: Repeat Links
        // Text: Format: Custom: Input Mask Pattern
        // Phone: Format: Custom: Input Mask Pattern
        // --------------------------------------------------------------------------------
        // ## Repeater: Repeat Layout
        //
        //     For divider and end_divider field types, the format attribute controls the
        //     display format of the repeater.
        //
        //     Fieldtypes:
        //       - divider (which is used by both the repeater and section fieldtypes)
        //
        //     Value type:
        //       - divider (repeater & section): string (enum):
        //           - "" (Default: No automatic formatting)
        //           - "inline" (Inline: Display each field and label in one row)
        //           - "grid" (Grid: Display labels as headings above rows of fields) // not used in any active UAMS forms
        //
        //     When the value is not set to either "inline" or "grid", the attribute does
        //     not appear in the XML for that field.
        //
        //     There is no analog to this in Statamic.
        //
        // ## Repeater: Section Buttons Field: Repeat Links
        //
        //     Fieldtypes:
        //       - end_divider (which is used by both the repeater and section fieldtypes)
        //
        //     Value type:
        //       - end_divider: string (enum):
        //           - "" (Icons) // not used in any active UAMS forms
        //           - "text" (Text links)
        //           - "both" (Text links with icons) // not used in any active UAMS forms
        //
        //     When the value is not set to either "text" or "both", the attribute does
        //     not appear in the XML for that field.
        //
        //     The `add_label` and `remove_label` attributes of the `end_divider` field
        //     are not impacted by the value of `format`.
        //
        //     There is no analog to this in Statamic.
        //
        // ## Text: Format: Custom: Input Mask Pattern
        //
        //     For the text field type, the format attribute provides custom input
        //     validation patterns.
        //
        //     Fieldtypes:
        //      - text
        //
        //     Value type:
        //       - text: string (input mask pattern)
        //
        //     Add support for `input_mask_pattern`
        //     in the mapping array. When a fieldtype is included in
        //     `input_mask_pattern['specific']`, then do the needful for that field.
        //     Automatically detect which iMask class to use based on the value of
        //     `format`.
        //
        //     The non-regex patterns present in active UAMS forms are:
        //         (999) 999-9999? x99999
        //           - The `phone` fieldtype could be updated to support extensions.
        //         **-99999?-99-99
        //           - The `identifier` fieldtype could be updated with a new preset to
        //             support whatever "Grant - Grant / WBS #" is.
        //         999
        //           - The `identifier` fieldtype could be updated with a new preset to
        //             support UAMS account / grant fund numbers
        //         9999999
        //           - The `identifier` fieldtype could be updated with a new preset to
        //             support UAMS cost center numbers.
        //
        //     The regex patterns present in active UAMS forms are:
        //         ^((https?:\/\/)?uams-triprofiles\.uams\.edu\/profiles\/display\/\d+/?)$
        //           - "What is the URL of your UAMS Translational Research Institute researcher profile?"
        //           - Could be replaced by a URL field with a new preset for UAMS TRI researcher profiles.
        //         ^([A-Z]{1,3}-\d{4}-(19|20)\d{2})$
        //           - "What is your Web of Science ResearcherID?"
        //           - Could be replaced by an identifier field with the preset for Web of Science ResearcherID.
        //         ^(\d{10})$
        //           - "What is your National Provider Identifier (NPI)?"
        //           - Could be replaced by an identifier field with the preset for NPI.
        //         ^(\d{4}-\d{4}-\d{4}-\d{3}[\dX])$
        //           - "What is your ORCID (Open Researcher and Contributor ID)?"
        //           - Could be replaced by an identifier field with the preset for ORCID.
        //
        // ## Phone: Format: Custom: Input Mask Pattern
        //
        //     For the phone field type, the format attribute provides phone number
        //     validation patterns.
        //
        //     Fieldtypes:
        //       - phone
        //
        //     Value type:
        //       - phone: string (input mask pattern)
        //
        //     All instances of this in active UAMS forms are set to "(999) 999-9999".
        //
        //     There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'format' => [
            'statamic_attribute' => [
                'default' => null,
                'specific_based_on_attribute_value' => [
                    'text' => [
                        '^*$' => 'input_mask_mask_MaskedRegExp',
                        '*' => 'input_mask_mask_MaskedPattern',
                    ],
                ],
            ],
            'statamic_other_field_value' => [
                'default' => [], // For this attribute, generally
                'specific' => [ // For this attribute on specific fieldtypes (the key is the fieldtype)
                    // <fieldtype> => [ <attribute> => <value> ]
                    'text' => [
                        'input_mask' => true,
                    ],
                ],
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [], // For this attribute, generally
                'specific' => [ // For this attribute on specific fieldtypes (the key is the fieldtype)
                    // <fieldtype> => [ <field_value> => [ <attribute> => <value> ] ]
                    'text' => [
                        //  <field_value> => [ <attribute> => <value> ]
                        '^*$' => ['input_mask_class' => 'MaskedRegExp'],
                        '*' => ['input_mask_class' => 'MaskedPattern'],
                    ],
                ],
            ],
            'value_substitution_regex' => [
                // Replace 9 with 0 (except when it's part of ^...$ pattern)
                '/9(?!.*\$$)/' => '0',
                // Replace ?(content) with [content] (except when it's part of ^...$ pattern)
                '/\?(?!.*\$$)(.*)/' => '[$1]',
            ],
        ],

        // Allowed file types
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // Value type: string (enum):
        //   - `"jpg|jpeg|jpe":"image/jpeg"` (jpg, jpeg, jpe)
        //   - `"png":"image/png"` (png)
        //   - `"gif":"image/gif"` (gif)
        //   - `"3g2|3gp2":"video/3gpp2"` (3g2, 3gp2)
        //   - `"3gp|3gpp":"video/3gpp"` (3gp, 3gpp)
        //   - `"avi":"video/avi"` (avi)
        //   - `"avif":"image/avif"` (avif)
        //   - `"doc":"application/msword"` (doc)
        //   - `"docm":"application/vnd.ms-word.document.macroEnabled.12"` (docm)
        //   - `"docx":"application/vnd.openxmlformats-officedocument.wordprocessingml.document"` (docx)
        //   - `"flac":"audio/flac"` (flac)
        //   - `"flv":"video/x-flv"` (flv)
        //   - `"gz|gzip":"application/x-gzip"` (gz, gzip)
        //   - `"key":"application/vnd.apple.keynote"` (key)
        //   - `"mid|midi":"audio/midi"` (mid, midi)
        //   - `"mov|qt":"video/quicktime"` (mov, qt)
        //   - `"mp3|m4a|m4b":"audio/mpeg"` (mp3, m4a, m4b)
        //   - `"mp4|m4v":"video/mp4"` (mp4, m4v)
        //   - `"mpeg|mpg|mpe":"video/mpeg"` (mpeg, mpg, mpe)
        //   - `"odt":"application/vnd.oasis.opendocument.text"` (odt)
        //   - `"ogg|oga":"audio/ogg"` (ogg, oga)
        //   - `"ogv":"video/ogg"` (ogv)
        //   - `"pdf":"application/pdf"` (pdf)
        //   - `"pot|pps|ppt":"application/vnd.ms-powerpoint"` (pot, pps, ppt)
        //   - `"ppsm":"application/vnd.ms-powerpoint.slideshow.macroEnabled.12"` (ppsm)
        //   - `"ppsx":"application/vnd.openxmlformats-officedocument.presentationml.slideshow"` (ppsx)
        //   - `"pptm":"application/vnd.ms-powerpoint.presentation.macroEnabled.12"` (pptm)
        //   - `"pptx":"application/vnd.openxmlformats-officedocument.presentationml.presentation"` (pptx)
        //   - `"wav":"audio/wav"` (wav)
        //   - `"webm":"video/webm"` (webm)
        //   - `"xla|xls|xlt|xlw":"application/vnd.ms-excel"` (xla, xls, xlt, xlw)
        //   - `"xlsb":"application/vnd.ms-excel.sheet.binary.macroEnabled.12"` (xlsb)
        //   - `"xlsm":"application/vnd.ms-excel.sheet.macroEnabled.12"` (xlsm)
        //   - `"xlsx":"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"` (xlsx)
        //   - `"zip":"application/zip"` (zip)
        //
        // The attribute appears like this:
        //     "ftypes":{"jpg|jpeg|jpe":"image\/jpeg","png":"image\/png","gif":"image\/gif","doc":"application\/msword","docx":"application\/vnd.openxmlformats-officedocument.wordprocessingml.document"}
        // --------------------------------------------------------------------------------
        'ftypes' => [
            'statamic_attribute' => [
                'default' => 'mime_types',
            ],
            // The `ftypes` value is a JSON object mapping extension groups to MIME
            // types ({"jpg|jpeg|jpe":"image/jpeg", ...}). Collect the object's values
            // (the MIME types) into a de-duplicated, translated `mime_types` list.
            'extract_object_values' => true,
            'value_translation' => [
                'default' => [
                    // [
                    //     'search' => 'application/msword',
                    //     'replace' => 'application/msword',
                    // ],
                    // [
                    //     'search' => 'application/pdf',
                    //     'replace' => 'application/pdf',
                    // ],
                    // [
                    //     'search' => 'application/vnd.apple.keynote',
                    //     'replace' => 'application/vnd.apple.keynote',
                    // ],
                    // [
                    //     'search' => 'application/vnd.ms-excel',
                    //     'replace' => 'application/vnd.ms-excel',
                    // ],
                    // [
                    //     'search' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
                    //     'replace' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
                    // ],
                    // [
                    //     'search' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
                    //     'replace' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
                    // ],
                    // [
                    //     'search' => 'application/vnd.ms-powerpoint',
                    //     'replace' => 'application/vnd.ms-powerpoint',
                    // ],
                    // [
                    //     'search' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
                    //     'replace' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
                    // ],
                    // [
                    //     'search' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
                    //     'replace' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
                    // ],
                    // [
                    //     'search' => 'application/vnd.ms-word.document.macroEnabled.12',
                    //     'replace' => 'application/vnd.ms-word.document.macroEnabled.12',
                    // ],
                    // [
                    //     'search' => 'application/vnd.oasis.opendocument.text',
                    //     'replace' => 'application/vnd.oasis.opendocument.text',
                    // ],
                    // [
                    //     'search' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    //     'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    // ],
                    // [
                    //     'search' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
                    //     'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
                    // ],
                    // [
                    //     'search' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    //     'replace' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    // ],
                    // [
                    //     'search' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    //     'replace' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    // ],
                    [
                        'search' => 'application/x-gzip',
                        'replace' => 'application/gzip',
                    ],
                    // [
                    //     'search' => 'application/zip',
                    //     'replace' => 'application/zip',
                    // ],
                    // [
                    //     'search' => 'audio/flac',
                    //     'replace' => 'audio/flac',
                    // ],
                    // [
                    //     'search' => 'audio/midi',
                    //     'replace' => 'audio/midi',
                    // ],
                    // [
                    //     'search' => 'audio/mpeg',
                    //     'replace' => 'audio/mpeg',
                    // ],
                    // [
                    //     'search' => 'audio/ogg',
                    //     'replace' => 'audio/ogg',
                    // ],
                    [
                        'search' => 'audio/wav',
                        'replace' => 'audio/x-wav',
                    ],
                    // [
                    //     'search' => 'image/avif',
                    //     'replace' => 'image/avif',
                    // ],
                    // [
                    //     'search' => 'image/gif',
                    //     'replace' => 'image/gif',
                    // ],
                    // [
                    //     'search' => 'image/jpeg',
                    //     'replace' => 'image/jpeg',
                    // ],
                    // [
                    //     'search' => 'image/png',
                    //     'replace' => 'image/png',
                    // ],
                    // [
                    //     'search' => 'video/3gpp',
                    //     'replace' => 'video/3gpp',
                    // ],
                    // [
                    //     'search' => 'video/3gpp2',
                    //     'replace' => 'video/3gpp2',
                    // ],
                    // [
                    //     'search' => 'video/avi',
                    //     'replace' => 'video/avi',
                    // ],
                    // [
                    //     'search' => 'video/mp4',
                    //     'replace' => 'video/mp4',
                    // ],
                    // [
                    //     'search' => 'video/mpeg',
                    //     'replace' => 'video/mpeg',
                    // ],
                    // [
                    //     'search' => 'video/ogg',
                    //     'replace' => 'video/ogg',
                    // ],
                    // [
                    //     'search' => 'video/quicktime',
                    //     'replace' => 'video/quicktime',
                    // ],
                    // [
                    //     'search' => 'video/webm',
                    //     'replace' => 'video/webm',
                    // ],
                    // [
                    //     'search' => 'video/x-flv',
                    //     'replace' => 'video/x-flv',
                    // ],
                ],
            ],
        ],

        // Default Value: Lookup: Get only the most recent value
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: integer (boolean) or numeric boolean (integer)
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'get_most_recent_value' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Default Value: Lookup: Search In: Select Field
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: integer or numeric string (integer)
        //
        // The value is the ID of the field to search in.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'get_values_field' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Default Value: Lookup: Search In: Select Form
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: integer or numeric string (integer)
        //
        // The value is the ID of the form to search in.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'get_values_form' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Conditional Logic: Rule Fields
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: array<string>
        //
        // The value is an array of the field IDs of the target fields for the conditional
        // logic rule.
        //
        // This attribute is paired with the `hide_field_cond` and `hide_opt` attributes.
        // --------------------------------------------------------------------------------
        // Conditional logic mapping is handled by the `mapConditionalLogic` method.
        // --------------------------------------------------------------------------------
        'hide_field' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Conditional Logic: Rule Operators
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: array<string(enum)>:
        //   - "==" (equals)
        //   - "!=" (does not equal)
        //   - ">" (is greater than)
        //   - "<" (is less than)
        //   - "LIKE" (contains)
        //   - "not LIKE" (does not contain)
        //   - "LIKE%" (starts with)
        //   - "%LIKE" (ends with)
        //
        // The value is an array of the operators for the conditional logic rule.
        //
        // This attribute is paired with the `hide_field` and `hide_field_cond`
        // attributes.
        // --------------------------------------------------------------------------------
        // Conditional logic mapping is handled by the `mapConditionalLogic` method.
        // --------------------------------------------------------------------------------
        'hide_field_cond' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Options: Display format: Images: Hide option labels
        // --------------------------------------------------------------------------------
        // The hide_image_text attribute is a field option setting that controls whether
        // text labels are displayed alongside images in radio button and checkbox fields
        // when they are configured to use images as options.
        //
        // Fieldtypes:
        //   - checkbox
        //   - radio
        //
        // Value type: numeric boolean (integer)
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'hide_image_text' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Conditional Logic: Rule Values
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: array<string>
        //
        // The value is an array of the target field values for the conditional logic
        // rule.
        //
        // This attribute is paired with the `hide_field` and `hide_field_cond`
        // attributes.
        // --------------------------------------------------------------------------------
        // Conditional logic mapping is handled by the `mapConditionalLogic` method.
        // --------------------------------------------------------------------------------
        'hide_opt' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Options: Display format
        // --------------------------------------------------------------------------------
        // The image_options attribute is a display format setting for radio and checkbox
        // fields in Formidable Forms. It controls how these choice fields are presented
        // to users on the front end.
        //
        // Fieldtypes:
        //   - radio
        //   - checkbox
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'image_options' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Options: Display format: Images: Image Size
        // --------------------------------------------------------------------------------
        // The image_size attribute in Formidable Forms is used to control the display
        // size of images in radio button and checkbox fields when they are configured to
        // show images instead of traditional radio/checkbox inputs.
        //
        // Fieldtypes:
        //   - radio
        //   - checkbox
        //
        // Value type: string (enum):
        //   - "small" (Small)
        //   - ???
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'image_size' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // In Section (field within Section/Repeater)
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: numeric string (integer) or integer
        //
        // The value is an integer corresponding to the ID of the `divider` fieldtype
        // instance for the section/repeater the field is contained within.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'in_section' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Validation Messages: Invalid Format
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'invalid' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Default Value: Calculation: Currency
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   -
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'is_currency' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Label Position
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: string (enum):
        //   - "" (Default)
        //   - "top" (Top)
        //   - "hidden" (Hidden [but leave the space])
        //   - "left" (Left) // Not used in any active UAMS forms
        //   - "right" (Right) // Not used in any active UAMS forms
        //   - "inline" (Inline [left without a set width]) // Not used in any active UAMS forms
        //   - "none" (None) // Not used in any active UAMS forms
        // --------------------------------------------------------------------------------
        'label' => [
            'statamic_attribute' => [
                'default' => 'hide_display',
                'based_on_attribute_value' => [
                    'default' => [
                        '#empty' => null,
                        'top' => null,
                        'left' => null, // Not used in any active UAMS forms
                        'right' => null, // Not used in any active UAMS forms
                        'inline' => null, // Not used in any active UAMS forms
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'hidden',
                        'replace' => true,
                    ],
                    [
                        'search' => 'none', // Not used in any active UAMS forms
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Name: Last Name: Description
        // --------------------------------------------------------------------------------
        // The last_desc attribute stores the description text for the "Last Name"
        // subfield within a Name field.
        //
        // Fieldtypes:
        //   - name
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'last_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Selection Maximum
        // --------------------------------------------------------------------------------
        // The limit_selections attribute limits the maximum number of options that can be
        // selected in fields that support multiple selections.
        //
        // Fieldtypes:
        //   - checkbox
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'limit_selections' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Address: Line 1: Description
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - address
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'line1_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Address: Line 2: Description
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - address
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'line2_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Limit options to those created by the current user
        // --------------------------------------------------------------------------------
        // The lookup_filter_current_user attribute is a boolean field option that
        // controls whether a Lookup field should filter its available options based on
        // the currently logged-in user.
        //
        // Fieldtypes:
        //   - lookup
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'lookup_filter_current_user' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Date: Max Characters
        // File: Max files per entry
        // Paragraph (textarea): Rows
        // Rich Text (rte): Rows
        // Signature: Field Size (pixels high)
        // Text: Max Characters
        // --------------------------------------------------------------------------------
        // ## Date: Max Characters
        //
        //     Fieldtypes:
        //       - date
        //       - text
        //
        //     Value type: numeric string or integer
        //
        //    This is not used in any active UAMS forms.
        //
        // ## Text: Max Characters
        //
        //     Fieldtypes:
        //       - date
        //       - text
        //
        //     Value type: numeric string or integer
        //
        // ## File: Max files per entry
        //
        //    Fieldtypes:
        //      - file
        //
        //    Value type: numeric string or integer
        //
        // ## Paragraph (textarea): Rows
        //    Rich Text (rte): Rows
        //
        //    Fieldtypes:
        //      - rte
        //      - textarea
        //
        //    Value type: numeric string or integer
        //
        //    There is no analog to this in Statamic.
        //
        // ## TODO (Post-MVP): Signature: Field Size (pixels high)
        //
        //     Fieldtypes:
        //       - signature
        //
        //     Value type: numeric string or integer
        //
        //     Figure out how to handle "Field Size" (pixels high) for `signature`.
        // --------------------------------------------------------------------------------
        'max' => [
            'statamic_attribute' => [
                'default' => 'character_limit',
                'specific' => [
                    'file' => 'max_files',
                    'rte' => null, // There is no analog to "Rows" in Statamic.
                    'textarea' => null, // There is no analog to "Rows" in Statamic.
                    'signature' => null,
                ],
            ],
            'as_integer' => true,
        ],

        // Limit length (Character Limit)
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - textarea
        //
        // This is effectively the same as the `max` attribute, but specific to the
        // `textarea` fieldtype. This config field is paired with the `max_limit_type`
        // config field.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'max_limit' => [
            'statamic_attribute' => [
                'default' => 'character_limit',
                'based_on_attribute_value' => [
                    'default' => [
                        '0' => null,
                    ],
                ],
            ],
            'value_calculation' => [
                'based_on_other_attribute_value' => [
                    'specific' => [
                        'textarea' => [
                            'max_limit_type' => [
                                '#not_set' => '[value]',
                                'char' => '[value]',
                                'word' => 'round([value] * 4.79)', // 4.79 is the average number of characters per word in the English language
                            ],
                        ],
                    ],
                ],
            ],
            'as_integer' => true,
        ],

        // Limit length Type (Character Limit)
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - textarea
        //
        // Value type: string (enum):
        //   - "char" (Characters)
        //   - "word" (Words)
        //
        // This is effectively the same as the `max` attribute, but specific to the
        // `textarea` fieldtype. This config field is paired with the `max_limit`
        // config field, which is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'max_limit_type' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Number Range: Maximum
        // --------------------------------------------------------------------------------
        // The maxnum attribute is a maximum number validation setting for number fields
        // in Formidable Forms
        //
        // Fieldtypes:
        //   - number
        // --------------------------------------------------------------------------------
        'maxnum' => [
            'statamic_attribute' => [
                'default' => 'max',
            ],
        ],

        // Name: Middle Name: Description
        // --------------------------------------------------------------------------------
        // The middle_desc attribute stores the description text for the "Middle Name"
        // subfield within a Name field.
        //
        // Fieldtypes:
        //   - name
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'middle_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Min file size (MB)
        // --------------------------------------------------------------------------------
        // Set the minimum file size limit for each file uploaded.
        //
        // Fieldtypes:
        //   - file
        // --------------------------------------------------------------------------------
        'min_size' => [
            'statamic_attribute' => [
                'default' => 'min_file_size',
            ],
        ],

        // Number Range: Minimum
        // --------------------------------------------------------------------------------
        // The minnum attribute is a minimum number validation setting for number fields
        // in Formidable Forms
        //
        // Fieldtypes:
        //   - number
        // --------------------------------------------------------------------------------
        'minnum' => [
            'statamic_attribute' => [
                'default' => 'min',
            ],
        ],

        // Allow multiple files to be uploaded
        // --------------------------------------------------------------------------------
        // The attribute appears on all fieldtypes, but it is only actually used by the
        // `file` fieldtype.
        //
        // Fieldtypes:
        //   - file
        //
        // Value type: integer (boolean)
        // --------------------------------------------------------------------------------
        'multiple' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [],
                'specific' => [
                    'file' => [
                        '#not_set' => [
                            'max_files' => 1,
                        ],
                        '0' => [
                            'max_files' => 1,
                        ],
                    ],
                ],
            ],
        ],

        // Automatically resize files before upload: New File Size (px)
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // Value type: numeric string (integer)
        //
        // The attribute defaults to '600', representing 600px.
        //
        // This attribute must be used in conjunction with `resize`. If that is
        // not enabled, the value of this attribute is ignored.
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'new_size' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Other Option
        // --------------------------------------------------------------------------------
        // The other attribute in Formidable Forms is a field option that allows users to
        // add an "Other" option to choice-based fields. When enabled, it:
        //   - Adds an "Other" option to the field's choices list
        //   - Creates a text input field that appears when the "Other" option is selected
        //   - Allows users to enter custom text that wasn't included in the predefined
        //     options
        //   - Stores the custom value separately from the predefined options
        //
        // Fieldtypes:
        //   - radio
        //   - checkbox
        //   - select
        //
        // Value type: integer (boolean) or boolean numeric string (integer)
        // --------------------------------------------------------------------------------
        'other' => [
            'statamic_attribute' => [
                'default' => 'enable_other',
                'specific' => [
                    'select' => 'taggable',
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        '0' => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Placeholder Text
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // This is not used for any of the fieldtypes with subfields (i.e., `address` and
        // `name`) in any active UAMS forms.
        //
        // It is used for the fieldtypes without subfields (e.g., `text` and `textarea`).
        //
        // NOTE: Array field options like `"placeholder":{"first":"","middle":"","last":""}`
        // for fieldtypes with subfields (e.g., `name`) are automatically filtered out
        // during mapping since they represent subfield-specific configurations rather
        // than general field attributes. The mapping system disregards these array
        // configurations to prevent incorrect attribute mapping.
        // --------------------------------------------------------------------------------
        'placeholder' => [
            'statamic_attribute' => [
                'default' => 'placeholder',
            ],
        ],

        // Post Field
        // --------------------------------------------------------------------------------
        // The post_field attribute is a field option that allows form fields to be mapped
        // to specific WordPress post properties when creating or updating posts through
        // Formidable Forms. It's part of the Pro version's post creation functionality.
        //
        // Fieldtypes:
        //   - (general)
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'post_field' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Before Input
        'prepend' => [
            'statamic_attribute' => [
                'default' => 'prepend',
            ],
        ],

        // Read Only
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        // --------------------------------------------------------------------------------
        'read_only' => [
            'statamic_attribute' => [
                'default' => 'visibility',
                'based_on_attribute_value' => [
                    'default' => [
                        '#empty' => null,
                        '0' => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => '1',
                        'replace' => 'read_only',
                    ],
                ],
            ],
        ],

        // Section/Repeater: Section Buttons Field: Remove Label
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - repeater
        //   - section
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'remove_label' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Repeat
        // --------------------------------------------------------------------------------
        // The repeat attribute is a field option that transforms a divider field into a
        // repeating section (also called a "repeater"). When enabled, it allows users to
        // dynamically add multiple instances of the same set of fields while filling out
        // a form.
        //
        // Fieldtypes:
        //   - divider
        //
        // Instances of this in active UAMS forms:
        //     - https://communications.uams.edu/creative-services/
        //         - "Release Form" [ID: 3]
        //             - "Add additional names as needed."
        //     - https://communications.uams.edu/web/
        //         - "Vanity Request" [ID: 2]
        //             - "Individual Vanity / Tagged URL"
        //         - "Provider Clinical Title Request Form" [ID: 27]
        //             - "Section A.2 — Specific Providers"
        //         - "UAMSHealth.com Provider Profile Data Collection Form" [ID: 30]
        //             - "Faculty Titles"
        //             - "Academic Administrative Titles"
        //             - "Education and Training"
        //             - "Specialty and Subspecialty Certifications"
        //             - "Health Care Professional Association Memberships"
        //             - "Reference Webpages"
        //         - "Web Edit Request" [ID: 40]
        //             - "Repeater"
        //
        // Mapping for this attribute is handled elsewhere.
        // --------------------------------------------------------------------------------
        'repeat' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Required Field Indicator
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // Value type: string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'required_indicator' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Automatically resize files before upload
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // All of the examples in UAMS forms have a value of `false`.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'resize' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Automatically resize files before upload: Resize Direction
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // Value type: string (enum):
        //   - 'width' (wide)
        //   - 'height' (high)
        //
        // The attribute defaults to 'width'.
        //
        // This attribute must be used in conjunction with `resize`. If that is
        // not enabled, the value of this attribute is ignored.
        //
        // The `resize` attribute is not enabled in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'resize_dir' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Allowed File Types
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - file
        //
        // Value type: string (enum):
        //   - "0" (Allow all file types)
        //   - "1" (Specify allowed types)
        //
        // When the attribute is set to "1", the `ftypes` attribute must be used to
        // specify the allowed file types.
        // --------------------------------------------------------------------------------
        'restrict' => [
            'statamic_attribute' => [
                'default' => 'file_type_restrictions',
                'based_on_attribute_value' => [
                    'default' => [
                        '0' => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => '1',
                        'replace' => 'mime_types',
                    ],
                ],
            ],
        ],

        // Options: Separate Values
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - checkbox
        //   - radio
        //   - select
        //
        // Value type: integer (boolean) or boolean numeric string (integer)
        //
        // There is no analog to this in Statamic, as the "Value" field is always visible.
        // --------------------------------------------------------------------------------
        'separate_value' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Conditional Logic: Show/Hide
        // --------------------------------------------------------------------------------
        //
        // --------------------------------------------------------------------------------
        // Conditional logic mapping is handled by the `mapConditionalLogic` method.
        // --------------------------------------------------------------------------------
        'show_hide' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Show Image
        // --------------------------------------------------------------------------------
        // The show_image attribute is a field option that controls whether URLs pointing
        // to images should be displayed as actual images (using <img> tags) instead of
        // just showing the URL text when displaying form entries.
        //
        // Fieldtypes:
        //   - radio?
        //   - checkbox?
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'show_image' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // General: Field Size (%, px, em)
        // File: Max file size (MB)
        // Signature: Field Size — pixels wide
        // --------------------------------------------------------------------------------
        // ## Field Size (%, px, em)
        //
        //   Fieldtypes:
        //     - (general, excluding file and signature)
        //
        //   Value type: string
        //
        //   There is no analog to this in Statamic.
        //
        // ## File: Max file size (MB)
        //
        //   Fieldtypes:
        //     - file
        //
        //   Value type: integer
        //
        // ## Signature: Field Size — pixels wide
        //
        //   Fieldtypes:
        //     - signature
        //
        //   Value type: numeric string
        //
        //   TODO (Post-MVP): Figure out how to handle Field Size — pixels wide.
        // --------------------------------------------------------------------------------
        'size' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'file' => 'max_file_size',
                    'signature' => null,
                ],
            ],
        ],

        // Submit: Add Start over button
        // --------------------------------------------------------------------------------
        // The start_over attribute is a form option that enables a "Start Over" button on
        // forms. When enabled, this button allows users to reset/clear all form fields
        // and start filling out the form from the beginning.
        //
        // Fieldtypes:
        //   - submit
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'start_over' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Start Over Button Text
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - submit
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'start_over_label' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Start Time
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - time
        //
        // Statamic v6 does not support the `min` attribute on the `time` fieldtype Vue
        // component, so this attribute is not mapped.
        // --------------------------------------------------------------------------------
        'start_time' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // End Time
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - time
        //
        // Statamic v6 does not support the max attribute on the time fieldtype Vue
        // component, so this attribute is not mapped.
        // --------------------------------------------------------------------------------
        'end_time' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Address: State/Province: Description
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - address
        //
        // This is not used in any active UAMS forms beyond the default Formidable Forms
        // behavior (i.e., "State/Province"). We will not map this attribute to anything
        // in Statamic so that the standard default Statamic behavior is used.
        // --------------------------------------------------------------------------------
        'state_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Step
        // --------------------------------------------------------------------------------
        // ## Number
        //
        // The step attribute in Formidable Forms is an HTML5 input attribute that
        // controls the increment/decrement value when users interact with number input
        // fields.
        //
        // The default value in the UI for this attribute field is "any", but if  left as
        // that, the attribute does not appear in the XML export at all.
        //
        // Fieldtypes:
        //   - number
        //
        // ## Time
        //
        // The step attribute in Formidable Forms is an HTML5 input attribute that
        // controls the increment/decrement value when users interact with time input
        // fields.
        //
        // There is a separate dropdown for the step attribute in the UI to set the unit
        // (minutes, seconds, milliseconds). Changing this field affects the time format
        // of the values in `start_time` and `end_time`; and adds the seconds selector to
        // the publish field.
        //
        // Statamic v6 does not support the step attribute on the time fieldtype Vue
        // component, so this attribute is not mapped when the fieldtype is time.
        //
        // Fieldtypes:
        //   - number (mapped to step)
        //   - time (not mapped)
        // --------------------------------------------------------------------------------
        'step' => [
            'statamic_attribute' => [
                'default' => 'step',
                'specific' => [
                    'time' => null,
                ],
            ],
        ],

        // Taxonomy
        // --------------------------------------------------------------------------------
        // The taxonomy attribute in Formidable Forms is used to specify which WordPress
        // taxonomy (like categories, tags, or custom taxonomies) a field should pull
        // data from. It's primarily used with the Dynamic field type (also called "data"
        // field type) to create fields that display taxonomy terms as options.
        //
        // Fieldtypes:
        //   - (general?)
        //
        // This does not appear to be used in any active UAMS forms. All instances of it
        // have the value `"category"`, but those attributes do not seem to be on relevant
        // fieldtypes.
        // --------------------------------------------------------------------------------
        'taxonomy' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Validation Messages: Unique
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'unique' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Validation Messages: Unique
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - (general)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'unique_msg' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Watch a Lookup Field
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - lookup
        //
        // This is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'watch_lookup' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],

        // Address: Zip/Postal: Description
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - address
        //
        // This is not used in any active UAMS forms beyond the default Formidable Forms
        // behavior (i.e., "Zip/Postal"). We will not map this attribute to anything in
        // Statamic so that the standard default Statamic behavior is used.
        // --------------------------------------------------------------------------------
        'zip_desc' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'alert_unsupported' => false,
        ],
    ];

    /**
     * Maps Formidable Forms subfield labels to Statamic subfield handles with
     * hierarchical attribute inheritance.
     *
     * This array defines how Formidable Forms subfield attributes are mapped to
     * Statamic config fields, supporting a three-level inheritance system to
     * eliminate code duplication and provide flexible configuration.
     *
     * Structure:
     *   - `attributes`: General attribute mappings that apply to ALL subfields of ALL
     *     fieldtypes
     *   - `fieldtypes[fieldType]['attributes']`: Fieldtype-specific mappings that
     *     override general attributes
     *   - `fieldtypes[fieldType]['subfields'][label]['attributes']`:
     *     Subfield-specific mappings that override fieldtype attributes
     *
     * Inheritance Order (most specific wins):
     *   1. Subfield-specific attributes (highest priority)
     *   2. Fieldtype-specific attributes (medium priority)
     *   3. General attributes (lowest priority)
     *
     * The 'statamic_attribute' value supports '*' as a placeholder for the subfield
     * handle, which gets replaced during mapping (e.g., '*_default' becomes
     * 'address_line_1_default').
     *
     * @var array{ attributes: array<string, array{ statamic_attribute: ?string, value_translation?: array<mixed, mixed>, value_prepend?: string, collapse_whitespace?: bool, as_integer?: bool }>, fieldtypes: array<string, array{ attributes?: array<string, array{ statamic_attribute: ?string, value_translation?: array<mixed, mixed>, value_prepend?: string, collapse_whitespace?: bool, as_integer?: bool }>, subfields: array<string, array{ handle: string, attributes?: array<string, array{ statamic_attribute: ?string, value_translation?: array<mixed, mixed>, value_prepend?: string, collapse_whitespace?: bool, as_integer?: bool }> }> }> }
     */
    protected static array $subfieldMapping = [
        'fieldtypes' => [
            // Add Formidable Forms fieldtype mappings as needed
        ],
        'attributes' => [
            // Add general attribute mappings for all subfields as needed
        ],
    ];

    /**
     * Get the Statamic fieldtype for a given Formidable Forms field type or field
     * array.
     *
     * @param  string|array<int|string, mixed>  $formidableField  Either the field type string or the full field array
     * @return string|null|false Statamic fieldtype, null if not mapped, or false if suppressed
     */
    public static function getStatamicFieldtype(
        string|array $formidableField
    ): string|null|false {
        return static::resolveFieldtypeFromMapping(
            static::$mapping,
            $formidableField
        );
    }

    /**
     * Get the mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array
     */
    public static function getMapping(): array
    {
        return static::$mapping;
    }

    /**
     * Get the field attribute mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for field attributes
     */
    public static function getFieldAttributeMapping(): array
    {
        return static::$fieldAttributeMapping;
    }

    /**
     * Get the field options mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for field options
     */
    public static function getFieldOptionsMapping(): array
    {
        return static::$fieldAttributeOptionsMapping;
    }

    /**
     * Extracts conditional logic data from Formidable Forms field data.
     *
     * @param  array<int|string, mixed>  $fieldData  The Formidable Forms field data
     * @return array<int|string, mixed>|null The conditional logic data or null if not present
     */
    protected static function extractConditionalLogicData(
        array $fieldData
    ): ?array {
        // Check if conditional logic is present in field_options
        if (isset($fieldData['field_options']) && \is_string($fieldData['field_options'])) {
            $fieldOptions = \json_decode(
                $fieldData['field_options'],
                true
            );

            if (\is_array($fieldOptions) && static::hasConditionalLogic($fieldOptions)) {
                return $fieldOptions;
            }
        }

        return null;
    }

    /**
     * Checks if the field options contain conditional logic configuration.
     *
     * @param  array<int|string, mixed>  $fieldOptions  The decoded field_options array
     * @return bool True if conditional logic is present
     */
    protected static function hasConditionalLogic(
        array $fieldOptions
    ): bool {
        $hideField = $fieldOptions['hide_field'] ?? null;

        return $hideField !== null
            && (
                \is_array($hideField)
                || $hideField === true
                || $hideField === '1'
                || $hideField === 1
            );
    }

    /**
     * Maps Formidable Forms conditional logic to Statamic conditional logic
     * attributes.
     *
     * This method transforms Formidable Forms conditional logic configuration into
     * Statamic's conditional logic format. It handles the conversion of:
     *   - show_hide: "show" | "hide" → if/unless attributes
     *   - any_all: "all" | "any" → if/if_any/unless/unless_any attributes
     *   - hide_field: array of field IDs → field handles
     *   - hide_field_cond: array of operators → condition strings
     *   - hide_opt: array of values → condition values
     *
     * Formidable Forms stores conditional logic in the field_options JSON with these
     * key properties:
     *   - show_hide: "show" or "hide" (action type, defaults to "show")
     *   - any_all: "all" or "any" (logic type, defaults to "all")
     *   - hide_field: Array of field IDs to check
     *   - hide_field_cond: Array of operators (==, !=, >, <, LIKE)
     *   - hide_opt: Array of values to compare against
     *
     * The method maps these to Statamic's conditional logic format:
     *   - if: Show when all conditions are met
     *   - if_any: Show when any condition is met
     *   - unless: Hide when all conditions are met
     *   - unless_any: Hide when any condition is met
     *
     * Example Formidable Forms field_options:
     *   {"show_hide":"show","any_all":"all","hide_field":["915"],"hide_opt":["red"]}
     *
     * Maps to Statamic:
     *   {"if": {"target_radio_field_1_915": "equals red"}}
     *
     * @param  array<int|string, mixed>  $fieldOptions  The Formidable Forms field_options array
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    public static function mapConditionalLogic(
        $fieldOptions,
        array $allFields = []
    ): array {
        // Extract action type (show/hide) with fallback to 'show'
        $actionType = \is_string($fieldOptions['show_hide'] ?? null)
            ? $fieldOptions['show_hide']
            : 'show';

        // Extract logic type (all/any) with fallback to 'all' when not specified
        $logicType = \is_string($fieldOptions['any_all'] ?? null)
            ? $fieldOptions['any_all']
            : 'any';

        // Extract field IDs array
        $fieldIds = \is_array($fieldOptions['hide_field'] ?? null)
            ? $fieldOptions['hide_field']
            : [];

        // Extract operators array
        $operators = \is_array($fieldOptions['hide_field_cond'] ?? null)
            ? $fieldOptions['hide_field_cond']
            : [];

        // Extract values array
        $values = \is_array($fieldOptions['hide_opt'] ?? null)
            ? $fieldOptions['hide_opt']
            : [];

        // If no field IDs, return empty array
        if ($fieldIds === []) {
            return [];
        }

        // Initialize array to store Statamic conditional logic
        $statamicConditions = [];

        // Process each condition
        foreach ($fieldIds as $index => $fieldId) {
            // Skip conditions that do not provide a scalar field ID.
            if (! \is_scalar($fieldId)) {
                continue;
            }

            // Normalize the field ID to a string for downstream lookups.
            $fieldIdString = (string) $fieldId;

            // Skip empty field IDs.
            if ($fieldIdString === '') {
                continue;
            }

            // Get operator for this condition (fallback to '==' if not set)
            $operator = \is_scalar($operators[$index] ?? null)
                ? (string) $operators[$index]
                : '==';

            // Get value for this condition (fallback to empty string if not set)
            $value = \is_scalar($values[$index] ?? null)
                ? (string) $values[$index]
                : '';

            // Find the field handle by field ID for cross-reference
            $fieldHandle = static::getFieldHandleById(
                $fieldIdString,
                $allFields
            );

            // Skip this condition if field handle not found
            if ($fieldHandle === null || $fieldHandle === '') {
                continue;
            }

            // Convert Formidable Forms operator to Statamic condition string
            $conditionString = static::convertOperatorToStatamicCondition(
                $operator,
                $value
            );

            // Skip this condition if operator is not supported
            if ($conditionString === null) {
                continue;
            }

            // Add the condition to the Statamic conditions array
            $statamicConditions[$fieldHandle] = $conditionString;
        }

        // Determine the appropriate Statamic conditional attribute based on action and logic type
        $statamicAttribute = static::getStatamicConditionalAttribute(
            $actionType,
            $logicType
        );

        // Return empty array if no valid attribute or no conditions
        if (
            $statamicAttribute === null
            || $statamicAttribute === ''
            || $statamicConditions === []
        ) {
            return [];
        }

        // Return the conditional logic in Statamic format
        return [$statamicAttribute => $statamicConditions];
    }

    /**
     * Maps Formidable Forms action conditional logic to Statamic conditional logic
     * attributes.
     *
     * This method handles the nested structure used in action conditions
     * (confirmations and email notifications), which is different from field-level
     * conditional logic.
     *
     * @param  array<int|string, mixed>  $conditions  The action conditions array
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    public static function mapActionConditionalLogic(
        array $conditions,
        array $allFields = []
    ): array {
        // Extract the action type, falling back to 'show'.
        //
        // `show_hide` is the FIELD-level key and is absent on every corpus email
        // action. An ACTION's directive lives in `send_stop` (`send` / `stop`), so
        // without reading it a "send unless" rule imported as "send when" — the
        // notification firing exactly when it should be suppressed. That inversion
        // was inert while this mapping only ran on the discarded processEmailView()
        // path; wiring it into the persisted path (wordpress-importer-archive#38) makes it shippable, so read
        // the key that actually governs an action. wordpress-importer-archive#39
        $actionType = 'show';
        if (\is_string($conditions['show_hide'] ?? null)) {
            $actionType = $conditions['show_hide'];
        } elseif (\is_string($conditions['send_stop'] ?? null)) {
            $actionType = $conditions['send_stop'] === 'stop' ? 'hide' : 'show';
        }

        // Extract logic type (all/any) with fallback to 'all' when not specified
        $logicType = \is_string($conditions['any_all'] ?? null)
            ? $conditions['any_all']
            : 'any';

        // Initialize array to store Statamic conditional logic
        $statamicConditions = [];

        // Process each condition - handle nested structure
        foreach ($conditions as $key => $value) {
            // Skip non-numeric keys (like 'send_stop', 'any_all')
            if (! \is_numeric($key) || ! \is_array($value)) {
                continue;
            }

            // Extract condition data from the nested structure
            $fieldId = $value['hide_field'] ?? null;
            $operator = \is_scalar($value['hide_field_cond'] ?? null)
                ? (string) $value['hide_field_cond']
                : '==';
            $conditionValue = \is_scalar($value['hide_opt'] ?? null)
                ? (string) $value['hide_opt']
                : '';

            // Skip if no field ID
            if (! \is_scalar($fieldId)) {
                continue;
            }

            // Normalize scalar field IDs to strings and skip empties.
            $fieldIdString = (string) $fieldId;
            if ($fieldIdString === '') {
                continue;
            }

            // Find the field handle by field ID for cross-reference
            $fieldHandle = static::getFieldHandleById(
                $fieldIdString,
                $allFields
            );

            // Skip this condition if field handle not found
            if ($fieldHandle === null || $fieldHandle === '') {
                continue;
            }

            // Convert Formidable Forms operator to Statamic condition string
            $conditionString = static::convertOperatorToStatamicCondition(
                $operator,
                $conditionValue
            );

            // Skip this condition if operator is not supported
            if ($conditionString === null) {
                continue;
            }

            // Add the condition to the Statamic conditions array
            $statamicConditions[$fieldHandle] = $conditionString;
        }

        // If no conditions were processed, return empty array
        if ($statamicConditions === []) {
            return [];
        }

        // Determine the appropriate Statamic conditional attribute based on action and logic type
        $statamicAttribute = static::getStatamicConditionalAttribute(
            $actionType,
            $logicType
        );

        // Return empty array if no valid attribute
        if ($statamicAttribute === null || $statamicAttribute === '') {
            return [];
        }

        // Return the conditional logic in Statamic format
        return [$statamicAttribute => $statamicConditions];
    }

    /**
     * Gets the Statamic conditional attribute based on action type and logic type.
     *
     * This method maps Formidable Forms action and logic types to Statamic
     * conditional logic attributes.
     *
     * @param  string  $actionType  The action type ('show' or 'hide')
     * @param  string  $logicType  The logic type ('all' or 'any')
     * @return ?string The Statamic conditional attribute or null if not supported
     */
    protected static function getStatamicConditionalAttribute(
        string $actionType,
        string $logicType
    ): ?string {
        // Map Formidable Forms action and logic types to Statamic conditional attributes
        return match ($actionType) {
            'show' => $logicType === 'any' ? 'if_any' : 'if',
            'hide' => $logicType === 'any' ? 'unless_any' : 'unless',
            default => null,
        };
    }

    /**
     * Gets the field name/label from a Formidable Forms field array.
     *
     * @param  array<int|string, mixed>  $field  The Formidable Forms field array
     * @return ?string The field name or null if not found
     */
    protected static function getFieldNameFromField(
        array $field
    ): ?string {
        $fieldName = $field['name'] ?? null;

        if (! \is_string($fieldName) || $fieldName === '') {
            return null;
        }

        return $fieldName;
    }

    /**
     * Converts Formidable Forms operator and value to Statamic condition string.
     *
     * This method maps Formidable Forms operators to Statamic condition strings:
     *   - == (equals): "equals {value}" or "equals empty" for empty values
     *   - != (not equals): "not {value}" or "not empty" for empty values
     *   - > (greater than): "> {value}"
     *   - >= (greater than or equal to): ">= {value}"
     *   - < (less than): "< {value}"
     *   - <= (less than or equal to): "<= {value}"
     *   - LIKE (contains): "contains {value}"
     *
     * These Formidable Forms operators are not supported in Statamic, but are also
     * not used in any active UAMS forms:
     *   - not LIKE (does not contain)
     *   - LIKE% (starts with)
     *   - %LIKE (ends with)
     *
     * @param  string  $operator  The Formidable Forms operator
     * @param  string  $value  The condition value
     * @return ?string The Statamic condition string or null if not supported
     */
    protected static function convertOperatorToStatamicCondition(
        string $operator,
        string $value
    ): ?string {
        // Convert Formidable Forms operators to Statamic condition strings
        return match ($operator) {
            '==' => $value === '' ? 'equals empty' : "equals {$value}",
            '!=' => $value === '' ? 'not empty' : "not {$value}",
            '>' => "> {$value}",
            '>=' => ">= {$value}",
            '<' => "< {$value}",
            '<=' => "<= {$value}",
            'LIKE' => "contains {$value}",
            'not LIKE' => null,
            'LIKE%' => null,
            '%LIKE' => null,
            default => null,
        };
    }
}
