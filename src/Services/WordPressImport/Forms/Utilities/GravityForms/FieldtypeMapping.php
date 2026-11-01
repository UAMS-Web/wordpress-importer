<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Provides mapping from Gravity Forms field types to Statamic fieldtypes.
 *
 * This class is responsible for:
 *   - Maintaining a mapping array of Gravity Forms field types to Statamic
 *     fieldtypes
 *   - Providing lookup methods for mapping
 *   - Identifying unmapped Gravity Forms fieldtypes in a forms export
 */
class FieldtypeMapping
{
    use FieldtypeMappingTrait;

    /**
     * Gravity Forms `product` input types the Statamic product fieldtype cannot express.
     *
     * `App\Fieldtypes\Product` offers a single base-priced item or a chooser of priced
     * options. Hidden, User Defined Price and Calculation products are none of those,
     * so each is reported rather than importing as a field with no selection type.
     * Corpus counts: `hiddenproduct` 2, `price` 1, `calculation` 0 — 3 of 309 product
     * fields (issue wordpress-importer-archive#299).
     *
     * @var list<string>
     */
    private const array UNSUPPORTED_PRODUCT_INPUT_TYPES = [
        'calculation',
        'hiddenproduct',
        'price',
    ];

    /**
     * Mapping from Gravity Forms field types to Statamic fieldtypes.
     *
     * Each mapping is an array with at least a 'default' key:
     *   'gravity_forms_type' => [ 'default' => 'statamic_fieldtype' ]
     *
     * For attribute-based mapping, use an 'attributes' key:
     *   'gravity_forms_type' => [
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
     *   'page' => [ 'default' => false ]
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $mapping = [
        // Standard Fields

        // Single Line Text
        // --------------------------------------------------------------------------------
        // The Single Line Text field allows users to submit a single line of text. This
        // basic field can be used for anything where no more than a single line of input
        // is required.
        // --------------------------------------------------------------------------------
        'text' => ['default' => 'text'],

        // Paragraph Text
        // --------------------------------------------------------------------------------
        // The Paragraph Text field allows a user to enter text in a textarea form field.
        // --------------------------------------------------------------------------------
        'textarea' => [
            'default' => 'textarea',
            'linked_field' => [
                'based_on_attribute_value' => [
                    'useRichTextEditor' => [
                        'true' => 'uamsweb_common.bard_simple',
                        1 => 'uamsweb_common.bard_simple',
                    ],
                ],
            ],
        ],

        // Drop Down
        // --------------------------------------------------------------------------------
        // The Drop Down field allows users to select one option from a list.
        // --------------------------------------------------------------------------------
        'select' => ['default' => 'select'],

        // Number
        // --------------------------------------------------------------------------------
        // The Number field allows a user to enter a number. The field than automatically
        // validates the form data to insure that a number was entered.
        // --------------------------------------------------------------------------------
        'number' => [
            'default' => 'float',
            'attributes' => [
                'numberFormat' => [
                    'currency' => 'currency',
                ],
            ],
        ],

        // Checkboxes
        // --------------------------------------------------------------------------------
        // The Checkboxes field allows you to present one or many checkboxes which allow
        // multiple selections.
        // --------------------------------------------------------------------------------
        'checkbox' => ['default' => 'checkboxes'],

        // Radio Buttons
        // --------------------------------------------------------------------------------
        // The Radio Buttons field allows users to select one option from a list. Only one
        // option from the available choices can be selected.
        // --------------------------------------------------------------------------------
        'radio' => ['default' => 'radio'],

        // Hidden
        // --------------------------------------------------------------------------------
        // The Hidden field can be used to store information that should be not visible to
        // the user but can be processed and stored with the user submission.
        // --------------------------------------------------------------------------------
        'hidden' => ['default' => 'hidden'],

        // HTML
        // --------------------------------------------------------------------------------
        // The HTML field allows you to place a block of free form HTML anywhere in your
        // form. This is useful for adding images or additional content.
        // --------------------------------------------------------------------------------
        'html' => ['default' => 'html'],

        // Section
        // --------------------------------------------------------------------------------
        // The Section Break field allows you to add a content separator to your form to
        // help organize groups of fields. This is a visual element rather than an actual
        // data collecting form field.
        // --------------------------------------------------------------------------------
        'section' => ['default' => 'subsection'],

        // Page Break
        // --------------------------------------------------------------------------------
        // The Page Break field allows the creation of multi-page forms. Adding a Page
        // Break field will automatically enable the multi-page functionality for your
        // form.
        // --------------------------------------------------------------------------------
        'page' => ['default' => false],

        // Multiple Choice
        // --------------------------------------------------------------------------------
        // The Gravity Forms Multiple Choice field offers both checkbox and radio button
        // form field functionality with a host of customization settings.
        // --------------------------------------------------------------------------------
        'multi_choice' => [
            'attributes' => [
                'inputType' => [
                    'checkbox' => 'checkboxes',
                    'radio' => 'radio',
                ],
            ],
            'default' => null,
        ],

        // Image Choice
        // --------------------------------------------------------------------------------
        // The Gravity Forms Image Choice field allows you to display images as choices
        // for your single or multi-select field.
        //
        // This fieldtype is not being used in any active UAMS forms (as of 2025-07-22).
        // --------------------------------------------------------------------------------
        'image_choice' => ['default' => null],

        // Advanced Fields

        // Name
        // --------------------------------------------------------------------------------
        // The Name field allows users to enter their name in the format you have
        // specified. This allows you to capture name information as individual fields.
        // --------------------------------------------------------------------------------
        'name' => ['default' => 'name'],

        // Date
        // --------------------------------------------------------------------------------
        // The Date field allows you to present a field that captures date data using the
        // jQuery UI date picker.
        // --------------------------------------------------------------------------------
        'date' => ['default' => 'date'],

        // Time
        // --------------------------------------------------------------------------------
        // The Time field allows users to submit a time as hours and minutes and
        // automatically validates that the information entered was formatted correctly.
        //
        // To completely map this field, it will require extending the Statamic `time`
        // fieldtype.
        // --------------------------------------------------------------------------------
        'time' => ['default' => 'time'],

        // Phone
        // --------------------------------------------------------------------------------
        // The Phone field allows users to enter a phone number and will automatically
        // format the entered data based on the selected phone format.
        // --------------------------------------------------------------------------------
        'phone' => ['default' => 'phone'],

        // Address
        // --------------------------------------------------------------------------------
        // The Address field makes it quick and easy to capture address information on a
        // form.
        // --------------------------------------------------------------------------------
        'address' => ['default' => 'address'],

        // Website
        // --------------------------------------------------------------------------------
        // The Website field allows users to enter a website URL and automatically
        // validates that the information entered was formatted correctly.
        // --------------------------------------------------------------------------------
        'website' => ['default' => 'url'],

        // Email
        // --------------------------------------------------------------------------------
        // The Email field allows you to present a field that captures email data.
        // --------------------------------------------------------------------------------
        'email' => ['default' => 'email'],

        // File Upload
        // --------------------------------------------------------------------------------
        // The File Upload field allows users to upload a file with their form submission.
        // You can limit which filetypes a user may upload.
        // --------------------------------------------------------------------------------
        'fileupload' => [
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

        // RCWD Uploader
        'rcwdupload' => [
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

        // CAPTCHA
        // --------------------------------------------------------------------------------
        // The CAPTCHA field allows you to add a captcha field to your form powered by the
        // free reCAPTCHA service. This helps determine if the form is being submitted by
        // a human or a scripted bot.
        // --------------------------------------------------------------------------------
        'captcha' => [
            'default' => null,
            'alert_unsupported' => false,
        ],

        // gf_no_captcha_recaptcha
        'gf_no_captcha_recaptcha' => [
            'default' => null,
            'alert_unsupported' => false,
        ],

        // List
        // --------------------------------------------------------------------------------
        // The List field allows the end user to add/remove additional rows (up to a
        // pre-set limit) of information per field. This allows for the capture of
        // multiple field values, or a variable "list" of data as defined by the end user.
        // --------------------------------------------------------------------------------
        'list' => [
            'default' => 'list',
            'attributes' => [
                'enableColumns' => [
                    'true' => 'grid',
                    1 => 'grid',
                    'false' => 'list',
                    0 => 'list',
                ],
            ],
        ],

        // Multi Select
        // --------------------------------------------------------------------------------
        // The Multi Select field allows users to select multiple options available in the
        // multi select box.
        // --------------------------------------------------------------------------------
        'multiselect' => [
            'default' => 'select',
            'set_attributes' => [
                'default' => [
                    'multiple' => true,
                ],
            ],
        ],

        // Consent
        // --------------------------------------------------------------------------------
        // The Consent field allows you to offer a "yes/no" consent checkbox and a
        // detailed description of what is being consented to.
        // --------------------------------------------------------------------------------
        'consent' => ['default' => 'consent'],

        // Add-Ons

        // Poll
        // --------------------------------------------------------------------------------
        // The Poll field will allow you to create polls to collect data from users.
        //
        // This field is available when using the Polls Add-On.
        // --------------------------------------------------------------------------------
        'poll' => [
            'attributes' => [
                'inputType' => [
                    'checkbox' => 'checkboxes',
                    'radio' => 'radio',
                    'select' => 'select',
                ],
            ],
            'default' => null,
        ],

        // Survey
        // --------------------------------------------------------------------------------
        // The Survey field allows you to create survey questions that users can answer.
        //
        // This field is available when using the Survey Add-On.
        //
        // To completely map this field, it will require additional custom fieldtypes.
        // --------------------------------------------------------------------------------
        'survey' => [
            'default' => null,
            'attributes' => [
                'useRichTextEditor' => [
                    'true' => 'bard',
                    1 => 'bard',
                ],
                'inputType' => [
                    'checkbox' => 'checkboxes',
                    'likert' => 'likert_scale',
                    'radio' => 'radio',
                    'rank' => null, // Will require a custom fieldtype // This input type is not being used on any active UAMS forms (as of 2025-07-23)
                    'rating' => 'radio',
                    'select' => 'select',
                    'text' => 'text',
                    'textarea' => 'textarea',
                ],
            ],
            'linked_field' => [
                'based_on_attribute_value' => [
                    'useRichTextEditor' => [
                        'true' => 'uamsweb_common.bard_simple',
                        1 => 'uamsweb_common.bard_simple',
                    ],
                ],
            ],
        ],

        // Form (Nested Forms)
        // --------------------------------------------------------------------------------
        // The Form field allows embedding one form within another, creating nested form
        // functionality. This is typically used with the Gravity Perks Nested Forms add-on
        // to create repeater-like functionality where users can add multiple instances of
        // a nested form.
        //
        // This fieldtype maps to Statamic's replicator fieldtype to provide similar
        // functionality for repeating nested form entries.
        // --------------------------------------------------------------------------------
        'form' => ['default' => 'replicator'],

        // Post Fields

        // Post Title
        // --------------------------------------------------------------------------------
        // The Post Title field allows users to submit data that is then used to populate
        // the title for a post. This field works in tandem with the other Post fields to
        // allow you to create a form that can generate post data from a user submission.
        // --------------------------------------------------------------------------------
        'post_title' => ['default' => 'text'],

        // Post Body
        // --------------------------------------------------------------------------------
        // The Post Body field allows users to submit data that is then used as the body
        // content for a post. This field works in tandem with the other Post fields to
        // allow you to create a form that can generate post data from a user submission.
        // --------------------------------------------------------------------------------
        'post_content' => [
            'default' => 'textarea',
            'linked_field' => [
                'based_on_attribute_value' => [
                    'useRichTextEditor' => [
                        'true' => 'uamsweb_common.bard_simple',
                        1 => 'uamsweb_common.bard_simple',
                    ],
                ],
            ],
        ],

        // Post Excerpt
        // --------------------------------------------------------------------------------
        // The Post Excerpt field allows users to submit data that is then used to
        // populate the excerpt of a post. This field works in tandem with the other Post
        // fields to allow you to create a form that can generate post data from a user
        // submission.
        // --------------------------------------------------------------------------------
        'post_excerpt' => [
            'default' => 'textarea',
            'linked_field' => [
                'based_on_attribute_value' => [
                    'useRichTextEditor' => [
                        'true' => 'uamsweb_common.bard_simple',
                        1 => 'uamsweb_common.bard_simple',
                    ],
                ],
            ],
        ],

        // Post Tags
        // --------------------------------------------------------------------------------
        // The Post Tags field allows users to submit data that is then used to populate
        // the tags for a post. This field works in tandem with the other Post fields to
        // allow you to create a form that can generate post data from a user submission.
        // --------------------------------------------------------------------------------
        'post_tags' => [
            'attributes' => [
                'useRichTextEditor' => [
                    'true' => 'bard',
                    1 => 'bard',
                ],
                'inputType' => [
                    '#empty' => 'list',
                    '#not_set' => 'list',
                    'checkbox' => 'checkboxes',
                    'radio' => 'radio',
                    'select' => 'select',
                    'multiselect' => 'select',
                    'text' => 'list',
                ],
            ],
            'default' => null,
        ],

        // Post Category
        // --------------------------------------------------------------------------------
        // The Post Category field allows the user to select a category for the post they
        // are creating. This field works in tandem with the other Post fields to allow
        // you to create a form that can generate post data from a user submission.
        //
        // The only UAMS form using this fieldtype is "GravityView - Website Directory" on
        // https://tri.uams.edu/. However, this form has no entries and does not appear to
        // be used on any of the https://tri.uams.edu/ webpages.
        //
        // This fieldtype is not used on any other UAMS forms (as of 2025-07-22).
        // --------------------------------------------------------------------------------
        'post_category' => [
            'attributes' => [
                'inputType' => [
                    'checkbox' => 'checkboxes',
                    'radio' => 'radio',
                    'select' => 'select',
                    'multiselect' => 'select',
                ],
            ],
            'default' => 'select',
        ],

        // Post Image
        // --------------------------------------------------------------------------------
        // The Post Image field allows users to upload an image that is added to the Media
        // Library and Gallery for the post that is created. This field works in tandem
        // with the other Post fields to allow you to create a form that can generate post
        // data from a user submission.
        // --------------------------------------------------------------------------------
        'post_image' => [
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

        // Post Custom Field
        // --------------------------------------------------------------------------------
        // The Post Custom field allows users to submit data that is then used to populate
        // a custom field value for a post. This field works in tandem with the other Post
        // fields to allow you to create a form that can generate post data from a user
        // submission.
        // --------------------------------------------------------------------------------
        'post_custom_field' => [
            'default' => 'text',
            'attributes' => [
                'enableColumns' => [
                    'true' => 'grid',
                    1 => 'grid',
                ],
                'inputType' => [
                    'text' => 'text',
                    'textarea' => 'textarea',
                    'select' => 'select',
                    'multiselect' => 'select',
                    'number' => 'float',
                    'checkbox' => 'checkboxes',
                    'radio' => 'radio',
                    'hidden' => 'hidden',
                    'date' => 'date',
                    'time' => 'time',
                    'phone' => 'phone',
                    'website' => 'url',
                    'email' => 'email',
                    'fileupload' => 'assets',
                    'rcwdupload' => 'assets',
                    'list' => 'list',
                ],
            ],
            'linked_field' => [
                'based_on_attribute_value' => [
                    'useRichTextEditor' => [
                        'true' => 'uamsweb_common.bard_simple',
                        1 => 'uamsweb_common.bard_simple',
                    ],
                ],
            ],
        ],

        // Add-Ons

        // Password
        // --------------------------------------------------------------------------------
        // The Password field provides two single line inputs (one to enter the password
        // and the second to confirm it) that will mask entered content with blobs or
        // asterisks. It is only available when the User Registration add-on is active.
        //
        // This field is available when using the User Registration Add-On.
        //
        // This fieldtype is being used on these UAMS sites (as of 2026-02-06):
        //   - https://uamscaregiving.org/teacher/
        //       - "User Registration"
        //           - Page URL: https://uamscaregiving.org/teacher/user-registration/
        //           - Form ID: 1
        // --------------------------------------------------------------------------------
        'password' => ['default' => 'password'],

        // Quiz
        // --------------------------------------------------------------------------------
        // The Quiz field for Gravity Forms allows you to quickly create quizzes that are
        // automatically graded or scored when the form is submitted.
        //
        // This field is available when using the Quiz Add-On.
        //
        // This field is seemingly used on forms for the following UAMS sites (as of
        // 2025-07-23):
        //   - https://idhi.uams.edu/stroke-program/
        //       - BE FAST Quiz July [ID 13]
        //       - Women's Stroke Awareness Quiz [ID 21]
        //   - https://inside.uams.edu/patientsafety/
        //       - Are You a Patient Safety Champion? [ID 7]
        //       - Safety Scale [ID 8]
        // --------------------------------------------------------------------------------
        'quiz' => ['default' => 'quiz'],

        // Signature
        // --------------------------------------------------------------------------------
        // The Signature field allows a user to sign the form. The signature gives legal
        // weight to the form.
        //
        // This field is available when using the Signature Add-On.
        //
        // This field is seemingly used on forms for the following UAMS sites (as of
        // 2025-07-23):
        //   - https://uamshealth.com/patients-and-guests/
        //       - "HEALERS Application" (ID: 13)
        //   - https://medicine.uams.edu/neurology/
        //       - "Resident Planned Sick Leave Request" (ID: 10)
        //   - https://inside.uams.edu/perioperativecare/
        //       - "Surgical Technologist IV - Application" (ID: 18)
        // --------------------------------------------------------------------------------
        'signature' => ['default' => 'signature'],

        // Username
        // --------------------------------------------------------------------------------
        // The Username field provides a single line input.
        //
        // This field is available when using the User Registration Add-On.
        //
        // This field is seemingly used on forms for the following UAMS sites (as of
        // 2025-07-23):
        //   - https://agec.uams.edu/
        //   - https://patientslearn.uams.edu/
        //
        // Resolves to the Statamic `username` fieldtype added in
        // `UAMS-Web/uams-statamic-archive#312` (`app/Fieldtypes/Username.php`). Before that
        // existed this mapped to null, and a null default falls back to the generic
        // `text` fieldtype — which silently dropped the username semantics the field
        // carries (`autocomplete="username"`, spellcheck / autocorrect / autocapitalize
        // off). Do not return it to null while that fieldtype exists (wordpress-importer-archive#300).
        // --------------------------------------------------------------------------------
        'username' => ['default' => 'username'],

        // Pricing Fields

        // Product
        // --------------------------------------------------------------------------------
        // The Product field allows the creation of products in the form. There are
        // several options for the type of field that should be used for the Product
        // Field: Single Product, Drop Down, Radio Buttons, and User Defined Price.
        //
        // This fieldtype is being used on these UAMS sites (as of 2025-07-22):
        //   - https://idhi.uams.edu/
        //       - IDHI Education Team Quote for Service
        //         (https://idhi.uams.edu/programs/education/quoteforservices/)
        //   - https://idhi.uams.edu/e-link
        //       - e-Link Quote
        //         (https://idhi.uams.edu/e-link/services-and-pricing/e-link-quote/)
        //   - https://regionalcampuses.uams.edu/
        //       - M*A*S*H SWAG Order Form
        //       - M*A*S*H T-shirt Order Form
        //       - 2025 M*A*S*H SWAG Order Form
        //
        // Imported shape (wordpress-importer-archive#299)
        // --------------------------------------------------------------------------------
        // Resolves to `App\Fieldtypes\Product` (`UAMS-Web/uams-statamic-archive#300`), which
        // offers a `single` selection type (base `price` + optional quantity) or
        // `multiple` (a chooser of `{ key, label, price }` option rows feeding the form
        // Total through a server-recomputed `line_total`).
        //
        //   - `inputType` -> `selection_type`: `singleproduct` takes the Statamic
        //     default `single`; `radio` / `select` become `multiple`; `hiddenproduct`,
        //     `price` and `calculation` have no analog and are reported by
        //     addUnsupportedProductModeWarning() rather than importing a field with no
        //     selection type.
        //   - `basePrice` -> `price`, `disableQuantity` -> `disable_quantity`.
        //   - `choices` -> `options`, one row per choice: the Gravity Forms choice
        //     `text` becomes the human `label`, the `key` is generated as
        //     `<field_handle>_<snake_case(text)>_<count>` so the value a submission
        //     carries is a stable slug rather than the raw label, and `price` is
        //     normalized from `$11,000.00` / `-$2,500.00` to a number. A choice with
        //     no price maps to `0`, which is a free item rather than an invalid row.
        //
        // The Gravity Forms choice `value` is dropped, as it was before wordpress-importer-archive#299. Four
        // corpus product fields set `enableChoiceValue`, so 14 of 41 choices carry a
        // `value` distinct from their `text` — but that value is the string the
        // WordPress form submitted, and it is no more a stable slug than the label is
        // (`First Choice Value`). The imported form is new, so the key only has to be
        // stable and unique within this field; deriving every key the same way keeps
        // that guarantee independent of whether an author happened to set a separate
        // value.
        // --------------------------------------------------------------------------------
        'product' => ['default' => 'product'],

        // Quantity
        // --------------------------------------------------------------------------------
        // The Quantity field allows a quantity to be specified for a Product.
        //
        // This fieldtype does not appear to be used in any active UAMS forms (as of
        // 2025-07-22).
        // --------------------------------------------------------------------------------
        // Dropping this changes what the form DOES -- it stops taking an order, and an
        // editor cannot tell that from a form that never did (wordpress-importer-archive#831).
        'quantity' => [
            'default' => null,
            'trace_when_skipped' => true,
        ],

        // Option
        // --------------------------------------------------------------------------------
        // The Option field allows the creation of options for products created by a
        // Product. Option fields have special functionality which displays how much
        // selecting the option will add (or subtract) from the total.
        //
        // This fieldtype is being used on these UAMS sites (as of 2026-07-30):
        //   - https://uamscaregiving.org/
        //       - Center Reservations - 2018 and prior
        //           - Form ID: 9
        //   - https://uamscaregiving.org/springdale/
        //       - Center Reservations - 2018 and prior
        //           - Form ID: 4
        //       - Calculate Reservation Rate
        //           - Form ID: 26
        //       - Event Reservation Agreement
        //           - Page URL: https://uamscaregiving.org/springdale/community-programs/center-reservations/
        //           - Form ID: 27
        //   - https://communications.uams.edu/web/ (non-production)
        //       - Test Form
        //           - Form ID: 6
        //
        // Pricing is genuinely used: the four production forms carry non-zero option
        // deltas of $25.00 to $150.00. The observed `inputType` values are `select`
        // and `radio`.
        //
        // Maps to the Statamic `option` fieldtype, which branches on its own
        // `input_type` config and therefore covers every Gravity Forms `inputType`
        // without degrading to `text` (`UAMS-Web/uams-statamic-archive#316`).
        // --------------------------------------------------------------------------------
        'option' => ['default' => 'option'],

        // Shipping
        // --------------------------------------------------------------------------------
        // The Shipping field allows a shipping fee to be added to the form total.
        //
        // This fieldtype does not appear to be used in any active UAMS forms (as of
        // 2025-07-22).
        // --------------------------------------------------------------------------------
        // Dropping this changes what the form DOES -- it stops taking an order, and an
        // editor cannot tell that from a form that never did (wordpress-importer-archive#831).
        'shipping' => [
            'default' => null,
            'trace_when_skipped' => true,
        ],

        // Total
        // --------------------------------------------------------------------------------
        // The Total field specifies where the order total should be displayed in the
        // form. As the user selects products and options, the total will automatically
        // update.
        //
        // This fieldtype is being used on these UAMS sites (as of 2025-07-22):
        //   - https://idhi.uams.edu/
        //       - IDHI Education Team Quote for Service
        //         (https://idhi.uams.edu/programs/education/quoteforservices/)
        //   - https://idhi.uams.edu/e-link
        //       - e-Link Quote
        //         (https://idhi.uams.edu/e-link/services-and-pricing/e-link-quote/)
        // --------------------------------------------------------------------------------
        'total' => ['default' => 'total'],

        // Add-Ons

        // 2Checkout
        // --------------------------------------------------------------------------------
        //
        // --------------------------------------------------------------------------------
        // 'unknown' => [ 'default' => null ],

        // Coupons
        // --------------------------------------------------------------------------------
        //
        // --------------------------------------------------------------------------------
        // 'unknown' => [ 'default' => null ],

        // Mollie
        // --------------------------------------------------------------------------------
        //
        // --------------------------------------------------------------------------------
        // 'unknown' => [ 'default' => null ],

        // Square
        // --------------------------------------------------------------------------------
        //
        // --------------------------------------------------------------------------------
        // 'unknown' => [ 'default' => null ],

        // Stripe
        // --------------------------------------------------------------------------------
        //
        // --------------------------------------------------------------------------------
        // 'unknown' => [ 'default' => null ],
    ];

    /**
     * Maps Gravity Forms field attributes to Statamic field attributes.
     *
     * This array defines how Gravity Forms field attributes should be mapped to
     * Statamic field attributes during form import. Each top-level key represents
     * a Gravity Forms field attribute name.
     *
     * Each Gravity Forms attribute can have the following configuration keys:
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
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *   - An array with 'specific_based_on_attribute_value' for fieldtype-specific
     *     mapping based on the value of the attribute
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *   - null: No mapping (attribute is not supported in Statamic)
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_attribute' => '<statamic_attribute_name>',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_attribute' => null,
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_attribute' => [
     *             'default' => '<statamic_attribute_name>',
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => '<statamic_attribute_name>',
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 '<gravity_forms_attribute_name>' => [
     *                     '<gravity_forms_attribute_value>' => '<statamic_attribute_name>',
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                     '<gravity_forms_attribute_value>' => '<statamic_attribute_name>',
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'value_translation'
     *
     * Optional array for mapping Gravity Forms option values to Statamic values.
     * Keys are Gravity Forms values, values are Statamic values.
     *
     * Can be:
     *   - An array: Direct mapping of Gravity Forms values to Statamic values
     *   - An array with 'default' and 'specific' keys for fieldtype-specific mapping
     *   - An array with 'based_on_other_attribute_value' for conditional mapping
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *   - An array with 'specific_based_on_attribute_value' for fieldtype-specific
     *     mapping based on the value of the attribute
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *
     * Special keys include:
     *   - '#not_set': Used when the attribute is not set in the JSON export
     *   - '#not_empty': Used when the attribute is set and is not empty in the JSON
     *     export
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_translation' => [
     *           '<gravity_forms_attribute_value>' => '<statamic_attribute_value>',
     *           '<gravity_forms_attribute_value>' => null,
     *           '#not_set' => '<statamic_attribute_value>',
     *           '#not_empty' => '<statamic_attribute_value>',
     *         ],
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_translation' => [
     *             'default' => [
     *               '<gravity_forms_attribute_value>' => '<statamic_attribute_value>',
     *               '#not_set' => '<statamic_attribute_value>',
     *               '#not_empty' => '<statamic_attribute_value>',
     *             ],
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                   '<gravity_forms_attribute_value>' => '<statamic_attribute_value>',
     *                   '#not_set' => '<statamic_attribute_value>',
     *                   '#not_empty' => '<statamic_attribute_value>',
     *                 ],
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 '<gravity_forms_attribute_name>' => [
     *                     '<gravity_forms_attribute_value>' => '<statamic_attribute_value>',
     *                     '#not_set' => '<statamic_attribute_value>',
     *                     '#not_empty' => '<statamic_attribute_value>',
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                     '<gravity_forms_attribute_value>' => '<statamic_attribute_value>',
     *                     '#not_set' => '<statamic_attribute_value>',
     *                     '#not_empty' => '<statamic_attribute_value>',
     *                 ],
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'key_translation'
     *
     * Optional array for mapping Gravity Forms keys to Statamic keys.
     *
     * Can be:
     *   - An array: Direct mapping of Gravity Forms keys to Statamic keys
     *   - An array with 'default' and 'specific' keys for fieldtype-specific mapping
     *   - An array with 'based_on_other_attribute_value' for conditional mapping
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *   - An array with 'specific_based_on_attribute_value' for fieldtype-specific
     *     mapping based on the value of the attribute
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'key_translation' => [
     *           '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *           '<gravity_forms_attribute_key>' => null,
     *         ],
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'key_translation' => [
     *             'default' => [
     *               '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *               '<gravity_forms_attribute_key>' => null,
     *             ],
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                   '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *                   '<gravity_forms_attribute_key>' => null,
     *                 ],
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 '<gravity_forms_attribute_name>' => [
     *                     '<gravity_forms_attribute_value>' => [
     *                         '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_set' => [
     *                         '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_empty' => [
     *                         '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                     '<gravity_forms_attribute_value>' => [
     *                         '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_set' => [
     *                         '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
     *                     ],
     *                     '#not_empty' => [
     *                         '<gravity_forms_attribute_key>' => '<statamic_attribute_key>',
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
     * Consulted only where 'statamic_attribute' is null. An entry spelled
     *   ['default' => null] is an array, never reaches the alert, and is silent
     *   by structure whatever this flag says, so the flag is not written there:
     *   on such an entry it would read as live while doing nothing (wordpress-importer-archive#1163).
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'alert_unsupported' => <boolean>,
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'alert_unsupported_default'
     *
     * Optional value suppressing the unsupported-attribute alert while the attribute
     *   equals it, so an attribute that matters *when set* keeps its warning for
     *   exactly the case that needs it. Compared strictly.
     *
     * Use this rather than 'alert_unsupported' => false wherever a non-default value
     *   would be operator-actionable; suppressing outright trades noise for a blind
     *   spot. Note that `false` is not an empty value to the emptiness test, which is
     *   why a boolean attribute that is false everywhere alerts without this (wordpress-importer-archive#1157).
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'alert_unsupported_default' => <mixed>,
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
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_other_field_value' => [
     *             'default' => [
     *                 '<statamic_attribute_name>' => '<statamic_attribute_value>',
     *             ],
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => [
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
     *   - '#not_set': Used when the attribute is not set in the JSON export
     *   - '#not_empty': Used when the attribute is set and is not empty in the JSON
     *     export
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_other_field_value_based_on_attribute_value' => [
     *             'default' => [
     *                 '<gravity_forms_attribute_value>' => [
     *                     '<statamic_attribute_name>' => '<statamic_attribute_value>',
     *                     '#not_set' => '<statamic_attribute_value>',
     *                     '#not_empty' => '<statamic_attribute_value>',
     *                 ],
     *             ],
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                     '<gravity_forms_attribute_value>' => [
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
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_type_based_on_attribute_value' => [
     *             '<gravity_forms_attribute_value>' => [
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
     *     '<gravity_forms_attribute_name>' => [
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
     *     '<gravity_forms_attribute_name>' => [
     *         'value_transformations' => [
     *             '<gravity_forms_attribute_key>' => [
     *                 '<transformation_type>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_transformations' => [
     *             'default' => [
     *                 '<gravity_forms_attribute_key>' => [
     *                     '<transformation_type>' => <boolean>,
     *                 ],
     *             ],
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                     '<gravity_forms_attribute_key>' => [
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
     *     '<gravity_forms_attribute_name>' => [
     *         'exclude_fieldtypes' => [
     *             '<gravity_forms_fieldtype>',
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'value_prepend'
     *
     * Optional string to prepend to the source value.
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_prepend' => '<string>',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_prepend' => [
     *             'default' => '<string>',
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => '<string>',
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
     *     '<gravity_forms_attribute_name>' => [
     *         'collapse_whitespace' => <boolean>,
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'collapse_whitespace' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => <boolean>,
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
     *     '<gravity_forms_attribute_name>' => [
     *         'as_array' => <boolean>,
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'as_array' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => <boolean>,
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
     *     '<gravity_forms_attribute_name>' => [
     *         'as_integer' => <boolean>,
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'as_integer' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => <boolean>,
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
     *     '<gravity_forms_attribute_name>' => [
     *         'convert_file_size' => '<unit>',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'convert_file_size' => [
     *             'default' => '<unit>',
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => '<unit>',
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
     *     '<gravity_forms_attribute_name>' => [
     *         'convert_dollar_amount' => <boolean>,
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'convert_dollar_amount' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => <boolean>,
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
     *     '<gravity_forms_attribute_name>' => [
     *         'check_against_list' => '<validation_lists_key>',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'check_against_list' => [
     *             'default' => '<validation_lists_key>',
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => '<validation_lists_key>',
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
     *     '<gravity_forms_attribute_name>' => [
     *         'transform_json_to_array' => <boolean>,
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'transform_json_to_array' => [
     *             'default' => <boolean>,
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => <boolean>,
     *             ],
     *         ],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * ## 'separate_value_field_option'
     *
     * Identifies the Gravity Forms attribute used to separate values from keys.
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'separate_value_field_option' => '<gravity_forms_attribute_name>',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'separate_value_field_option' => [
     *             'default' => '<gravity_forms_attribute_name>',
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => '<gravity_forms_attribute_name>',
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
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_form_config' => '<key>',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'statamic_form_config' => [
     *             'default' => '<key>',
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => '<key>',
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
     *     '<gravity_forms_attribute_name>' => [
     *         'value_substitution_regex' => [
     *             '<regex_pattern_match>' => '<replacement_value>',
     *         ],
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_substitution_regex' => [
     *             'default' => [
     *                 '<regex_pattern_match>' => '<replacement_value>',
     *             ],
     *             'specific' => [
     *                 '<gravity_forms_fieldtype>' => [
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
     * Optional array to apply calculations to a Gravity Forms value before it's
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
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *   - An array with 'specific_based_on_attribute_value' for fieldtype-specific
     *     calculation based on the value of the attribute
     *       - Special keys include:
     *           - '#not_set': Used when the attribute is not set in the JSON export
     *           - '#not_empty': Used when the attribute is set and is not empty in
     *             the JSON export
     *
     * Example: '[value] * 2.5'
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_calculation' => '<calculation>',
     *     ],
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'value_calculation' => [
     *             'default' => '<calculation>',
     *             'specific' => [
     *                 '<gravity_forms_attribute_name>' => '<calculation>',
     *             ],
     *             'based_on_other_attribute_value' => [
     *                 'default' => [
     *                     '<gravity_forms_attribute_name>' => [
     *                         '<gravity_forms_attribute_value>' => '<calculation>',
     *                         '#not_set' => '<calculation>',
     *                         '#not_empty' => '<calculation>',
     *                     ],
     *                 ],
     *                 'specific' => [
     *                     '<gravity_forms_fieldtype>' => [
     *                         '<gravity_forms_attribute_name>' => [
     *                             '<gravity_forms_attribute_value>' => '<calculation>',
     *                             '#not_set' => '<calculation>',
     *                             '#not_empty' => '<calculation>',
     *                         ],
     *                     ],
     *                 ],
     *             ],
     *             'specific_based_on_attribute_value' => [
     *                 '<gravity_forms_fieldtype>' => [
     *                     '<gravity_forms_attribute_value>' => '<calculation>',
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
     * Optional array of specific characters to trim from the beginning and end of
     * attribute values.
     *
     * If provided, removes the specified characters from the beginning and end of the
     * attribute value. When used with explode_string, trims each individual array
     * element. This is independent of the trim property and functions similarly.
     *
     * Example: ['.', ','] trims '.value,' to 'value'
     *
     *     '<gravity_forms_attribute_name>' => [
     *         'trim_characters' => ['.', ','],
     *     ],
     *
     * --------------------------------------------------------------------------------
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeMapping = [
        // Common Attributes

        // Admin Field Label
        'adminLabel' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'unescape_slashes' => true,
        ],

        // Allow field to be populated dynamically
        // --------------------------------------------------------------------------------
        // The Gravity Forms `allowsPrepopulate` attribute is a boolean value. When true,
        // it allows the field to be populated dynamically. It also displays the
        // "Parameter Name" configuration field.
        // --------------------------------------------------------------------------------
        'allowsPrepopulate' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'checkbox' => 'calculation_query',
                    'email' => 'calculation_query',
                    'hidden' => 'calculation_query',
                    'multiselect' => 'calculation_query',
                    'number' => 'calculation_query',
                    'password' => 'calculation_query',
                    'phone' => 'calculation_query',
                    'poll' => 'calculation_query',
                    'quiz' => 'calculation_query',
                    'radio' => 'calculation_query',
                    'select' => 'calculation_query',
                    'survey' => 'calculation_query',
                    'text' => 'calculation_query',
                    'time' => 'calculation_query',
                    'website' => 'calculation_query',
                ],
                'based_on_other_attribute_value' => [
                    'default' => [
                        'inputName' => [
                            '#empty' => null,
                            '#not_set' => null,
                        ],
                    ],
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        '#empty' => null,
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Custom CSS Class
        'cssClass' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [
                    // The field is hidden.
                    '*d-none*' => ['visibility' => 'hidden'],

                    // Field Widths:
                    '*gf_first_quarter*' => ['width' => 25],
                    '*gf_fourth_quarter*' => ['width' => 25],
                    '*gf_left_half*' => ['width' => 50],
                    '*gf_left_third*' => ['width' => 33],
                    '*gf_middle_third*' => ['width' => 33],
                    '*gf_right_half*' => ['width' => 50],
                    '*gf_right_third*' => ['width' => 33],
                    '*gf_second_quarter*' => ['width' => 25],
                    '*gf_third_quarter*' => ['width' => 25],
                ],
            ],
        ],

        // Default Value
        'defaultValue' => [
            'statamic_attribute' => [
                'default' => 'default',
                'based_on_attribute_value' => [
                    'default' => [
                        '{date_mdy}' => null,
                    ],
                ],
            ],
            'unescape_slashes' => true,
            'get_selected_choice' => [
                'default' => null,
                'specific' => [
                    'radio' => 'choices.isSelected',
                    'checkbox' => 'choices.isSelected',
                    'select' => 'choices.isSelected',
                    'multiselect' => 'choices.isSelected',
                ],
                'based_on_other_attribute_value' => [
                    'default' => [
                        'inputType' => [
                            'radio' => 'choices.isSelected',
                            'checkbox' => 'choices.isSelected',
                            'select' => 'choices.isSelected',
                            'multiselect' => 'choices.isSelected',
                        ],
                    ],
                ],
            ],
        ],

        // Description
        'description' => [
            'statamic_attribute' => [
                'default' => 'instructions',
                'specific' => [
                    'consent' => 'consent_agreement',
                ],
            ],
            'preserve_newlines' => [
                'default' => false,
                'specific' => [
                    'consent' => true,
                ],
            ],
            'unescape_slashes' => true,
        ],

        // Description Placement
        'descriptionPlacement' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Allow field to be duplicated in the form editor
        // --------------------------------------------------------------------------------
        // An editor affordance rather than form behavior: it records whether the Gravity
        // Forms admin offers a duplicate control while someone is building the form, and
        // nothing about the rendered form, its validation or its submissions depends on it.
        // True on 211 of 214 fields measured across 19 forms, including 40 `html`, 2
        // `section` and 5 `page` fields that a person filling in a form cannot duplicate.
        // The three exceptions are `captcha` and `post_title`, exactly the types Gravity
        // Forms refuses to duplicate in its own editor — which confirms the reading (wordpress-importer-archive#1157).
        // --------------------------------------------------------------------------------
        'duplicatable' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported' => false,
        ],

        // Custom Validation Message
        'errorMessage' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // fields
        'fields' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // formId
        'formId' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // id
        'id' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Input Mask
        'inputMask' => [
            'statamic_attribute' => [
                'default' => 'input_mask',
                'based_on_other_attribute_value' => [
                    'default' => [
                        'inputMaskValue' => [
                            '#empty' => null,
                            '#not_set' => null,
                        ],
                    ],
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Input Mask: Mask Type: Custom: Enter a custom mask
        'inputMaskIsCustom' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Input Mask: Mask Type: Standard: Select a Mask
        'inputMaskValue' => [
            'statamic_attribute' => [
                'default' => 'input_mask_mask_MaskedPattern',
            ],
            'value_substitution_regex' => [
                // Replace 9 with 0
                '/9/' => '0',
                // Replace ?(content) with [content]
                '/\?(.*)/' => '[$1]',
                // Replace * with #
                '/\*/' => '#',
            ],
            'unescape_slashes' => true,
        ],

        // Allow field to be populated dynamically: Parameter Name
        // --------------------------------------------------------------------------------
        // The Gravity Forms `inputName` attribute is a string value. It sets the name of
        // query string parameter to use to populate the field. This attribute is only
        // visible when the `allowsPrepopulate` attribute is true.
        // --------------------------------------------------------------------------------
        'inputName' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'checkbox' => 'calculation_formula_text',
                    'date' => 'calculation_formula_text',
                    'email' => 'calculation_formula_text',
                    'hidden' => 'calculation_formula_text',
                    'multi_choice' => 'calculation_formula_text',
                    'number' => 'calculation_formula_math',
                    'password' => 'calculation_formula_text',
                    'phone' => 'calculation_formula_text',
                    'poll' => 'calculation_formula_text',
                    'radio' => 'calculation_formula_text',
                    'select' => 'calculation_formula_text',
                    'survey' => 'calculation_formula_text',
                    'text' => 'calculation_formula_text',
                    'textarea' => 'calculation_formula_text',
                    'time' => 'calculation_formula_text',
                    'website' => 'calculation_formula_text',
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        '{today}' => null,
                        'today()' => null,
                        '26' => null,
                    ],
                ],
            ],
            'value_substitution_regex' => [
                '/(^.+$)/' => '[get_param:$1]',
            ],
        ],

        // Rules: Required
        // --------------------------------------------------------------------------------
        // Mapping for this attribute is being handled elsewhere.
        // --------------------------------------------------------------------------------
        'isRequired' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Field participates in payment calculation
        // --------------------------------------------------------------------------------
        // Reported only when true. A payment field is a genuine migration concern and
        // Statamic has no analog, so the notice is valuable in exactly the case that does
        // not currently occur: `is_payment` is false on all 214 fields measured, and a
        // marker scan of all 34 sites on the network found 0 occurrences of it set true.
        // Suppressing the attribute outright would trade today's noise for a blind spot in
        // the one estate state that would need the warning (wordpress-importer-archive#1157).
        // --------------------------------------------------------------------------------
        'is_payment' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Field Label
        'label' => [
            'statamic_attribute' => [
                'default' => 'display',
            ],
            'unescape_slashes' => true,
        ],

        // Field Label Visibility
        // --------------------------------------------------------------------------------
        // The Gravity Forms `labelPlacement` attribute is an enumerated string with the
        // following options:
        // - null (Visible (Top aligned))
        // - 'hidden_label' (Hidden)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'labelPlacement' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Maximum Characters
        // --------------------------------------------------------------------------------
        // The Gravity Forms `maxLength` attribute is a string value. It sets the maximum
        // number of characters that can be entered into the field.
        // --------------------------------------------------------------------------------
        'maxLength' => [
            'statamic_attribute' => [
                'default' => 'character_limit',
            ],
            'as_integer' => true,
        ],

        // No Duplicates
        // --------------------------------------------------------------------------------
        // The Gravity Forms `noDuplicates` attribute is a boolean value. When true, it
        // prevents duplicate entries from being submitted.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'noDuplicates' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Reject URLs in the value
        // --------------------------------------------------------------------------------
        // Reported only when true. Set true, the field refuses a submission containing a URL,
        // which is a validation rule an editor has to recreate by hand. False on 3,922 and
        // true on 385 of 4,307 fields measured on 6 sites. The plugin that adds it is not in
        // the reference WordPress install, so the reading rests on the name and the values.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163): the default is the majority value observed.
        // --------------------------------------------------------------------------------
        'noURLs' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Personal Data Erase
        // --------------------------------------------------------------------------------
        // Reported only when true. Gravity Forms core (`includes/class-personal-data.php`)
        // erases a field marked true when a personal-data erasure request is processed, and
        // Statamic has no equivalent, so a marked field is a privacy obligation someone has
        // to carry across by hand. False on 835 and true on 69 of 904 fields measured (wordpress-importer-archive#1163).
        // --------------------------------------------------------------------------------
        'personalDataErase' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Personal Data Export
        // --------------------------------------------------------------------------------
        // The Gravity Forms `personalDataExport` attribute is a boolean value that
        // indicates whether the field should be included in personal data export
        // requests (GDPR compliance). This is not supported in Statamic and is ignored.
        // --------------------------------------------------------------------------------
        'personalDataExport' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Product Field Mapping
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'productField' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // repeatable
        // --------------------------------------------------------------------------------
        // Deliberately left reporting at every value: classified as unknown (wordpress-importer-archive#1163). It
        // arrives on the same 4,307 fields as `noURLs`, true on 4,210 and false on 97, and
        // the plugin that adds it is not in the reference WordPress install. It is true on
        // 377 `html` fields a respondent cannot repeat, which suggests an editor affordance
        // like `duplicatable`, but that is an inference and suppressing on it would hide the
        // attribute if the inference is wrong. The entry records the classification; it
        // behaves exactly as an absent entry does.
        // --------------------------------------------------------------------------------
        'repeatable' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
        ],

        // Field Size
        // --------------------------------------------------------------------------------
        // The Gravity Forms `size` attribute is an enumerated string with the following
        // options:
        // - 'small' (Small)
        // - 'medium' (Medium)
        // - 'large' (Large)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'size' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Sub-Label Placement
        'subLabelPlacement' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Fieldtype
        // --------------------------------------------------------------------------------
        // Mapping for this attribute is being handled elsewhere.
        // --------------------------------------------------------------------------------
        'type' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Visibility
        // --------------------------------------------------------------------------------
        // The Gravity Forms `visibility` attribute is an enumerated string with the
        // following options:
        // - 'visible' (Visible)
        // - 'hidden' (Hidden)
        // - 'administrative' (Administrative)
        // --------------------------------------------------------------------------------
        'visibility' => [
            'statamic_attribute' => [
                'default' => 'visibility',
                'based_on_attribute_value' => [
                    'default' => [
                        'visible' => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'administrative',
                        'replace' => 'hidden',
                    ],
                ],
            ],
            'check_against_list' => 'visibility_values',
        ],

        // Uncommon Attributes

        // Background Color (Signature fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `backgroundColor` attribute is a hex color string used for
        // the signature canvas background color.
        // --------------------------------------------------------------------------------
        'backgroundColor' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Border Color (Signature fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `borderColor` attribute is a hex color string used for
        // the signature canvas border color.
        // --------------------------------------------------------------------------------
        'borderColor' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Border Width (Signature fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `borderWidth` attribute is a string value representing the
        // border width. Values can be empty string, "0", "1", "2", or "3".
        // --------------------------------------------------------------------------------
        'borderWidth' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Border Style (Signature fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `borderStyle` attribute is a string value representing the
        // border style. Values can be empty string, "solid", "dashed", "dotted", etc.
        // --------------------------------------------------------------------------------
        'borderStyle' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Box Width (Signature fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `boxWidth` attribute is a string value representing the
        // signature canvas width in pixels.
        // --------------------------------------------------------------------------------
        'boxWidth' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Pen Color (Signature fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `penColor` attribute is a hex color string used for the
        // signature pen color.
        // --------------------------------------------------------------------------------
        'penColor' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Pen Size (Signature fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `penSize` attribute is a string value representing the pen
        // stroke width. Values can be empty string, "1" (Small), "2" (Medium), or "3"
        // (Large).
        // --------------------------------------------------------------------------------
        'penSize' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Address Type
        // --------------------------------------------------------------------------------
        // The Gravity Forms `addressType` attribute is an enumerated string with the
        // following options:
        // - 'international' (International)
        // - 'us' (United States)
        // - 'canadian' (Canadian)
        // --------------------------------------------------------------------------------
        'addressType' => [
            'statamic_attribute' => [
                'default' => 'preset',
                'based_on_attribute_value' => [
                    'default' => [
                        'us' => null, // 'american' is the default value for the `address` fieldtype, so exclude preset
                    ],
                ],
            ],
            'placeholder_translation' => [
                'us' => 'american', // Use 'american' for placeholder replacement even when preset should be excluded
                'canadian' => 'canadian',
                'international' => 'international',
            ],
        ],

        // addIconUrl
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'addIconUrl' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Allowed file extensions
        // --------------------------------------------------------------------------------
        // The Gravity Forms `allowedExtensions` attribute is a string value. It sets the
        // allowed file extensions for the file upload field. The extensions are separated
        // with commas.
        // --------------------------------------------------------------------------------
        'allowedExtensions' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'fileupload' => 'mime_types',
                    'rcwdupload' => null,
                    'post_image' => 'mime_types',
                ],
                'based_on_other_attribute_value' => [
                    'default' => [
                        'inputType' => [
                            'fileupload' => 'mime_types',
                            'rcwdupload' => null,
                        ],
                    ],
                ],
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [
                    '#not_empty' => [
                        'file_type_restrictions' => 'mime_types',
                    ],
                ],
            ],
            'explode_string' => ',',
            'trim' => true,
            'trim_characters' => [
                '.',
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => '0fs',
                        'replace' => 'application/vnd.erofs',
                    ],
                    [
                        'search' => '123',
                        'replace' => 'application/vnd.lotus-1-2-3',
                    ],
                    [
                        'search' => '1905.1',
                        'replace' => 'application/vnd.ieee.1905',
                    ],
                    [
                        'search' => '1clr',
                        'replace' => 'application/clr',
                    ],
                    [
                        'search' => '1km',
                        'replace' => 'application/vnd.1000minds.decision-model+xml',
                    ],
                    [
                        'search' => '210',
                        'replace' => 'application/p21',
                    ],
                    [
                        'search' => '3dml',
                        'replace' => 'text/vnd.in3d.3dml',
                    ],
                    [
                        'search' => '3ds',
                        'replace' => 'image/x-3ds',
                    ],
                    [
                        'search' => '3g2',
                        'replace' => 'video/3gpp2',
                    ],
                    [
                        'search' => '3gp',
                        'replace' => 'video/3gpp',
                    ],
                    [
                        'search' => '3gpp',
                        'replace' => 'video/3gpp',
                    ],
                    [
                        'search' => '3mf',
                        'replace' => 'model/3mf',
                    ],
                    [
                        'search' => '3tz',
                        'replace' => 'application/vnd.maxar.archive.3tz+zip',
                    ],
                    [
                        'search' => '726',
                        'replace' => 'audio/32kadpcm',
                    ],
                    [
                        'search' => '7z',
                        'replace' => 'application/x-7z-compressed',
                    ],
                    [
                        'search' => 'a',
                        'replace' => 'text/vnd.a',
                    ],
                    [
                        'search' => 'a2l',
                        'replace' => 'application/a2l',
                    ],
                    [
                        'search' => 'aa3',
                        'replace' => 'audio/atrac3',
                    ],
                    [
                        'search' => 'aab',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'aac',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'aal',
                        'replace' => 'audio/atrac-advanced-lossless',
                    ],
                    [
                        'search' => 'aam',
                        'replace' => 'application/x-authorware-map',
                    ],
                    [
                        'search' => 'aas',
                        'replace' => 'application/x-authorware-seg',
                    ],
                    [
                        'search' => 'abc',
                        'replace' => 'text/vnd.abc',
                    ],
                    [
                        'search' => 'abw',
                        'replace' => 'application/x-abiword',
                    ],
                    [
                        'search' => 'ac',
                        'replace' => 'application/pkix-attr-cert',
                    ],
                    [
                        'search' => 'ac2',
                        'replace' => 'application/vnd.banana-accounting',
                    ],
                    [
                        'search' => 'acc',
                        'replace' => 'application/vnd.americandynamics.acc',
                    ],
                    [
                        'search' => 'ace',
                        'replace' => 'application/x-ace-compressed',
                    ],
                    [
                        'search' => 'acu',
                        'replace' => 'application/vnd.acucobol',
                    ],
                    [
                        'search' => 'acutc',
                        'replace' => 'application/vnd.acucorp',
                    ],
                    [
                        'search' => 'adp',
                        'replace' => 'audio/adpcm',
                    ],
                    [
                        'search' => 'adts',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'aep',
                        'replace' => 'application/vnd.audiograph',
                    ],
                    [
                        'search' => 'afm',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'afp',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'age',
                        'replace' => 'application/vnd.age',
                    ],
                    [
                        'search' => 'ahead',
                        'replace' => 'application/vnd.ahead.space',
                    ],
                    [
                        'search' => 'ai',
                        'replace' => 'application/postscript',
                    ],
                    [
                        'search' => 'aif',
                        'replace' => 'audio/x-aiff',
                    ],
                    [
                        'search' => 'aifc',
                        'replace' => 'audio/x-aiff',
                    ],
                    [
                        'search' => 'aiff',
                        'replace' => 'audio/x-aiff',
                    ],
                    [
                        'search' => 'aion',
                        'replace' => 'application/vnd.veritone.aion+json',
                    ],
                    [
                        'search' => 'air',
                        'replace' => 'application/vnd.adobe.air-application-installer-package+zip',
                    ],
                    [
                        'search' => 'ait',
                        'replace' => 'application/vnd.dvb.ait',
                    ],
                    [
                        'search' => 'ami',
                        'replace' => 'application/vnd.amiga.ami',
                    ],
                    [
                        'search' => 'aml',
                        'replace' => 'application/automationml-aml+xml',
                    ],
                    [
                        'search' => 'amlx',
                        'replace' => 'application/automationml-amlx+zip',
                    ],
                    [
                        'search' => 'amr',
                        'replace' => 'audio/amr',
                    ],
                    [
                        'search' => 'apex',
                        'replace' => 'application/vnd.apexlang',
                    ],
                    [
                        'search' => 'apexlang',
                        'replace' => 'application/vnd.apexlang',
                    ],
                    [
                        'search' => 'apk',
                        'replace' => 'application/vnd.android.package-archive',
                    ],
                    [
                        'search' => 'apkg',
                        'replace' => 'application/vnd.anki',
                    ],
                    [
                        'search' => 'apng',
                        'replace' => 'image/apng',
                    ],
                    [
                        'search' => 'appcache',
                        'replace' => 'text/cache-manifest',
                    ],
                    [
                        'search' => 'appinstaller',
                        'replace' => 'application/appinstaller',
                    ],
                    [
                        'search' => 'application',
                        'replace' => 'application/x-ms-application',
                    ],
                    [
                        'search' => 'appx',
                        'replace' => 'application/appx',
                    ],
                    [
                        'search' => 'appxbundle',
                        'replace' => 'application/appxbundle',
                    ],
                    [
                        'search' => 'apr',
                        'replace' => 'application/vnd.lotus-approach',
                    ],
                    [
                        'search' => 'apxml',
                        'replace' => 'application/auth-policy+xml',
                    ],
                    [
                        'search' => 'arc',
                        'replace' => 'application/x-freearc',
                    ],
                    [
                        'search' => 'arj',
                        'replace' => 'application/x-arj',
                    ],
                    [
                        'search' => 'arrow',
                        'replace' => 'application/vnd.apache.arrow.file',
                    ],
                    [
                        'search' => 'arrows',
                        'replace' => 'application/vnd.apache.arrow.stream',
                    ],
                    [
                        'search' => 'artisan',
                        'replace' => 'application/vnd.artisan+json',
                    ],
                    [
                        'search' => 'asc',
                        'replace' => 'application/pgp-signature',
                    ],
                    [
                        'search' => 'ascii',
                        'replace' => 'text/vnd.ascii-art',
                    ],
                    [
                        'search' => 'asf',
                        'replace' => 'video/x-ms-asf',
                    ],
                    [
                        'search' => 'asice',
                        'replace' => 'application/vnd.etsi.asic-e+zip',
                    ],
                    [
                        'search' => 'asics',
                        'replace' => 'application/vnd.etsi.asic-s+zip',
                    ],
                    [
                        'search' => 'asm',
                        'replace' => 'text/x-asm',
                    ],
                    [
                        'search' => 'aso',
                        'replace' => 'application/vnd.accpac.simply.aso',
                    ],
                    [
                        'search' => 'ass',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'asx',
                        'replace' => 'video/x-ms-asf',
                    ],
                    [
                        'search' => 'at3',
                        'replace' => 'audio/atrac3',
                    ],
                    [
                        'search' => 'atc',
                        'replace' => 'application/vnd.acucorp',
                    ],
                    [
                        'search' => 'atf',
                        'replace' => 'application/atf',
                    ],
                    [
                        'search' => 'atfx',
                        'replace' => 'application/atfx',
                    ],
                    [
                        'search' => 'atom',
                        'replace' => 'application/atom+xml',
                    ],
                    [
                        'search' => 'atomcat',
                        'replace' => 'application/atomcat+xml',
                    ],
                    [
                        'search' => 'atomdeleted',
                        'replace' => 'application/atomdeleted+xml',
                    ],
                    [
                        'search' => 'atomsvc',
                        'replace' => 'application/atomsvc+xml',
                    ],
                    [
                        'search' => 'atx',
                        'replace' => 'application/vnd.antix.game-component',
                    ],
                    [
                        'search' => 'atxml',
                        'replace' => 'application/atxml',
                    ],
                    [
                        'search' => 'au',
                        'replace' => 'audio/basic',
                    ],
                    [
                        'search' => 'auc',
                        'replace' => 'application/tamp-apex-update-confirm',
                    ],
                    [
                        'search' => 'avci',
                        'replace' => 'image/avci',
                    ],
                    [
                        'search' => 'avcs',
                        'replace' => 'image/avcs',
                    ],
                    [
                        'search' => 'avi',
                        'replace' => 'video/x-msvideo',
                    ],
                    [
                        'search' => 'avif',
                        'replace' => 'image/avif',
                    ],
                    [
                        'search' => 'aw',
                        'replace' => 'application/applixware',
                    ],
                    [
                        'search' => 'awb',
                        'replace' => 'audio/amr-wb',
                    ],
                    [
                        'search' => 'axdl',
                        'replace' => 'application/vnd.apexlang',
                    ],
                    [
                        'search' => 'axx',
                        'replace' => 'application/vnd.xecrets-encrypted',
                    ],
                    [
                        'search' => 'azf',
                        'replace' => 'application/vnd.airzip.filesecure.azf',
                    ],
                    [
                        'search' => 'azs',
                        'replace' => 'application/vnd.airzip.filesecure.azs',
                    ],
                    [
                        'search' => 'azv',
                        'replace' => 'image/vnd.airzip.accelerator.azv',
                    ],
                    [
                        'search' => 'azw',
                        'replace' => 'application/vnd.amazon.ebook',
                    ],
                    [
                        'search' => 'azw3',
                        'replace' => 'application/vnd.amazon.mobi8-ebook',
                    ],
                    [
                        'search' => 'b16',
                        'replace' => 'image/vnd.pco.b16',
                    ],
                    [
                        'search' => 'bar',
                        'replace' => 'application/vnd.qualcomm.brew-app-res',
                    ],
                    [
                        'search' => 'bary',
                        'replace' => 'model/vnd.bary',
                    ],
                    [
                        'search' => 'bat',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'bcpio',
                        'replace' => 'application/x-bcpio',
                    ],
                    [
                        'search' => 'bdf',
                        'replace' => 'application/x-font-bdf',
                    ],
                    [
                        'search' => 'bdm',
                        'replace' => 'application/vnd.syncml.dm+wbxml',
                    ],
                    [
                        'search' => 'bdo',
                        'replace' => 'application/vnd.nato.bindingdataobject+xml',
                    ],
                    [
                        'search' => 'bdob',
                        'replace' => 'application/vnd.nato.bindingdataobject+cbor',
                    ],
                    [
                        'search' => 'bdoc',
                        'replace' => 'application/bdoc',
                    ],
                    [
                        'search' => 'bdoj',
                        'replace' => 'application/vnd.nato.bindingdataobject+json',
                    ],
                    [
                        'search' => 'bed',
                        'replace' => 'application/vnd.realvnc.bed',
                    ],
                    [
                        'search' => 'bh2',
                        'replace' => 'application/vnd.fujitsu.oasysprs',
                    ],
                    [
                        'search' => 'bik',
                        'replace' => 'video/vnd.radgamettools.bink',
                    ],
                    [
                        'search' => 'bin',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'bk2',
                        'replace' => 'video/vnd.radgamettools.bink',
                    ],
                    [
                        'search' => 'bkm',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'blb',
                        'replace' => 'application/x-blorb',
                    ],
                    [
                        'search' => 'blend',
                        'replace' => 'application/x-blender',
                    ],
                    [
                        'search' => 'blorb',
                        'replace' => 'application/x-blorb',
                    ],
                    [
                        'search' => 'bmed',
                        'replace' => 'multipart/vnd.bint.med-plus',
                    ],
                    [
                        'search' => 'bmi',
                        'replace' => 'application/vnd.bmi',
                    ],
                    [
                        'search' => 'bmml',
                        'replace' => 'application/vnd.balsamiq.bmml+xml',
                    ],
                    [
                        'search' => 'bmp',
                        'replace' => 'image/bmp',
                    ],
                    [
                        'search' => 'bmpr',
                        'replace' => 'application/vnd.balsamiq.bmpr',
                    ],
                    [
                        'search' => 'book',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'box',
                        'replace' => 'application/vnd.previewsystems.box',
                    ],
                    [
                        'search' => 'boz',
                        'replace' => 'application/x-bzip2',
                    ],
                    [
                        'search' => 'bpd',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'bpf',
                        'replace' => 'application/vnd.bpf',
                    ],
                    [
                        'search' => 'bpk',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'brush',
                        'replace' => 'application/vnd.procreate.brush',
                    ],
                    [
                        'search' => 'brushset',
                        'replace' => 'application/vnd.procrate.brushset',
                    ],
                    [
                        'search' => 'bsp',
                        'replace' => 'model/vnd.valve.source.compiled-map',
                    ],
                    [
                        'search' => 'btf',
                        'replace' => 'image/prs.btif',
                    ],
                    [
                        'search' => 'btif',
                        'replace' => 'image/prs.btif',
                    ],
                    [
                        'search' => 'buffer',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'bz',
                        'replace' => 'application/x-bzip',
                    ],
                    [
                        'search' => 'bz2',
                        'replace' => 'application/x-bzip2',
                    ],
                    [
                        'search' => 'bz3',
                        'replace' => 'application/vnd.bzip3',
                    ],
                    [
                        'search' => 'c',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'c11amc',
                        'replace' => 'application/vnd.cluetrust.cartomobile-config',
                    ],
                    [
                        'search' => 'c11amz',
                        'replace' => 'application/vnd.cluetrust.cartomobile-config-pkg',
                    ],
                    [
                        'search' => 'c2pa',
                        'replace' => 'application/c2pa',
                    ],
                    [
                        'search' => 'c3ex',
                        'replace' => 'application/cccex',
                    ],
                    [
                        'search' => 'c4d',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4f',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4g',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4p',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4u',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c9r',
                        'replace' => 'application/vnd.cryptomator.encrypted',
                    ],
                    [
                        'search' => 'c9s',
                        'replace' => 'application/vnd.cryptomator.encrypted',
                    ],
                    [
                        'search' => 'cab',
                        'replace' => 'application/vnd.ms-cab-compressed',
                    ],
                    [
                        'search' => 'caf',
                        'replace' => 'audio/x-caf',
                    ],
                    [
                        'search' => 'cap',
                        'replace' => 'application/vnd.tcpdump.pcap',
                    ],
                    [
                        'search' => 'car',
                        'replace' => 'application/vnd.curl.car',
                    ],
                    [
                        'search' => 'carjson',
                        'replace' => 'application/vnd.eu.kasparian.car+json',
                    ],
                    [
                        'search' => 'cat',
                        'replace' => 'application/vnd.ms-pki.seccat',
                    ],
                    [
                        'search' => 'cb7',
                        'replace' => 'application/x-cbr',
                    ],
                    [
                        'search' => 'cba',
                        'replace' => 'application/x-cbr',
                    ],
                    [
                        'search' => 'cbor',
                        'replace' => 'application/cbor',
                    ],
                    [
                        'search' => 'cbr',
                        'replace' => 'application/vnd.comicbook-rar',
                    ],
                    [
                        'search' => 'cbt',
                        'replace' => 'application/x-cbr',
                    ],
                    [
                        'search' => 'cbz',
                        'replace' => 'application/vnd.comicbook+zip',
                    ],
                    [
                        'search' => 'cc',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'ccc',
                        'replace' => 'text/vnd.net2phone.commcenter.command',
                    ],
                    [
                        'search' => 'ccmp',
                        'replace' => 'application/ccmp+xml',
                    ],
                    [
                        'search' => 'cco',
                        'replace' => 'application/x-cocoa',
                    ],
                    [
                        'search' => 'cct',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'ccxml',
                        'replace' => 'application/ccxml+xml',
                    ],
                    [
                        'search' => 'cdbcmsg',
                        'replace' => 'application/vnd.contact.cmsg',
                    ],
                    [
                        'search' => 'cdf',
                        'replace' => 'application/x-netcdf',
                    ],
                    [
                        'search' => 'cdfx',
                        'replace' => 'application/cdfx+xml',
                    ],
                    [
                        'search' => 'cdkey',
                        'replace' => 'application/vnd.mediastation.cdkey',
                    ],
                    [
                        'search' => 'cdmia',
                        'replace' => 'application/cdmi-capability',
                    ],
                    [
                        'search' => 'cdmic',
                        'replace' => 'application/cdmi-container',
                    ],
                    [
                        'search' => 'cdmid',
                        'replace' => 'application/cdmi-domain',
                    ],
                    [
                        'search' => 'cdmio',
                        'replace' => 'application/cdmi-object',
                    ],
                    [
                        'search' => 'cdmiq',
                        'replace' => 'application/cdmi-queue',
                    ],
                    [
                        'search' => 'cdx',
                        'replace' => 'chemical/x-cdx',
                    ],
                    [
                        'search' => 'cdxml',
                        'replace' => 'application/vnd.chemdraw+xml',
                    ],
                    [
                        'search' => 'cdy',
                        'replace' => 'application/vnd.cinderella',
                    ],
                    [
                        'search' => 'cea',
                        'replace' => 'application/cea',
                    ],
                    [
                        'search' => 'cel',
                        'replace' => 'application/vnd.cel',
                    ],
                    [
                        'search' => 'cellml',
                        'replace' => 'application/cellml+xml',
                    ],
                    [
                        'search' => 'cer',
                        'replace' => 'application/pkix-cert',
                    ],
                    [
                        'search' => 'cfs',
                        'replace' => 'application/x-cfs-compressed',
                    ],
                    [
                        'search' => 'cgm',
                        'replace' => 'image/cgm',
                    ],
                    [
                        'search' => 'chat',
                        'replace' => 'application/x-chat',
                    ],
                    [
                        'search' => 'chm',
                        'replace' => 'application/vnd.ms-htmlhelp',
                    ],
                    [
                        'search' => 'chrt',
                        'replace' => 'application/vnd.kde.kchart',
                    ],
                    [
                        'search' => 'cif',
                        'replace' => 'chemical/x-cif',
                    ],
                    [
                        'search' => 'cii',
                        'replace' => 'application/vnd.anser-web-certificate-issue-initiation',
                    ],
                    [
                        'search' => 'cil',
                        'replace' => 'application/vnd.ms-artgalry',
                    ],
                    [
                        'search' => 'cjs',
                        'replace' => 'application/node',
                    ],
                    [
                        'search' => 'cl',
                        'replace' => 'application/simple-filter+xml',
                    ],
                    [
                        'search' => 'cla',
                        'replace' => 'application/vnd.claymore',
                    ],
                    [
                        'search' => 'class',
                        'replace' => 'application/java-vm',
                    ],
                    [
                        'search' => 'cld',
                        'replace' => 'model/vnd.cld',
                    ],
                    [
                        'search' => 'clip',
                        'replace' => 'image/vnd.clip',
                    ],
                    [
                        'search' => 'clkk',
                        'replace' => 'application/vnd.crick.clicker.keyboard',
                    ],
                    [
                        'search' => 'clkp',
                        'replace' => 'application/vnd.crick.clicker.palette',
                    ],
                    [
                        'search' => 'clkt',
                        'replace' => 'application/vnd.crick.clicker.template',
                    ],
                    [
                        'search' => 'clkw',
                        'replace' => 'application/vnd.crick.clicker.wordbank',
                    ],
                    [
                        'search' => 'clkx',
                        'replace' => 'application/vnd.crick.clicker',
                    ],
                    [
                        'search' => 'clp',
                        'replace' => 'application/x-msclip',
                    ],
                    [
                        'search' => 'clue',
                        'replace' => 'application/clue_info+xml',
                    ],
                    [
                        'search' => 'cmc',
                        'replace' => 'application/vnd.cosmocaller',
                    ],
                    [
                        'search' => 'cmdf',
                        'replace' => 'chemical/x-cmdf',
                    ],
                    [
                        'search' => 'cml',
                        'replace' => 'chemical/x-cml',
                    ],
                    [
                        'search' => 'cmp',
                        'replace' => 'application/vnd.yellowriver-custom-menu',
                    ],
                    [
                        'search' => 'cmsc',
                        'replace' => 'application/cms',
                    ],
                    [
                        'search' => 'cmx',
                        'replace' => 'image/x-cmx',
                    ],
                    [
                        'search' => 'cnd',
                        'replace' => 'text/jcr-cnd',
                    ],
                    [
                        'search' => 'cod',
                        'replace' => 'application/vnd.rim.cod',
                    ],
                    [
                        'search' => 'coffee',
                        'replace' => 'text/coffeescript',
                    ],
                    [
                        'search' => 'com',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'conf',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'coswid',
                        'replace' => 'application/swid+cbor',
                    ],
                    [
                        'search' => 'cpio',
                        'replace' => 'application/x-cpio',
                    ],
                    [
                        'search' => 'cpj',
                        'replace' => 'application/vnd.xarin.cpj',
                    ],
                    [
                        'search' => 'cpkg',
                        'replace' => 'application/vnd.xmpie.cpkg',
                    ],
                    [
                        'search' => 'cpl',
                        'replace' => 'application/cpl+xml',
                    ],
                    [
                        'search' => 'cpp',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'cpt',
                        'replace' => 'application/mac-compactpro',
                    ],
                    [
                        'search' => 'cql',
                        'replace' => 'text/cql',
                    ],
                    [
                        'search' => 'crd',
                        'replace' => 'application/x-mscardfile',
                    ],
                    [
                        'search' => 'crl',
                        'replace' => 'application/pkix-crl',
                    ],
                    [
                        'search' => 'crt',
                        'replace' => 'application/x-x509-ca-cert',
                    ],
                    [
                        'search' => 'crtr',
                        'replace' => 'application/vnd.multiad.creator',
                    ],
                    [
                        'search' => 'crx',
                        'replace' => 'application/x-chrome-extension',
                    ],
                    [
                        'search' => 'cryptomator',
                        'replace' => 'application/vnd.cryptomator.vault',
                    ],
                    [
                        'search' => 'cryptonote',
                        'replace' => 'application/vnd.rig.cryptonote',
                    ],
                    [
                        'search' => 'csh',
                        'replace' => 'application/x-csh',
                    ],
                    [
                        'search' => 'csl',
                        'replace' => 'application/vnd.citationstyles.style+xml',
                    ],
                    [
                        'search' => 'csml',
                        'replace' => 'chemical/x-csml',
                    ],
                    [
                        'search' => 'csp',
                        'replace' => 'application/vnd.commonspace',
                    ],
                    [
                        'search' => 'csrattrs',
                        'replace' => 'application/csrattrs',
                    ],
                    [
                        'search' => 'css',
                        'replace' => 'text/css',
                    ],
                    [
                        'search' => 'cst',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'csv',
                        'replace' => 'text/csv',
                    ],
                    [
                        'search' => 'csvs',
                        'replace' => 'text/csv-schema',
                    ],
                    [
                        'search' => 'cu',
                        'replace' => 'application/cu-seeme',
                    ],
                    [
                        'search' => 'cuc',
                        'replace' => 'application/tamp-community-update-confirm',
                    ],
                    [
                        'search' => 'curl',
                        'replace' => 'text/vnd.curl',
                    ],
                    [
                        'search' => 'cw',
                        'replace' => 'application/prs.cww',
                    ],
                    [
                        'search' => 'cwl',
                        'replace' => 'application/cwl',
                    ],
                    [
                        'search' => 'cwl.json',
                        'replace' => 'application/cwl+json',
                    ],
                    [
                        'search' => 'cwl.yaml',
                        'replace' => 'application/cwl+yaml',
                    ],
                    [
                        'search' => 'cww',
                        'replace' => 'application/prs.cww',
                    ],
                    [
                        'search' => 'cxt',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'cxx',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'dae',
                        'replace' => 'model/vnd.collada+xml',
                    ],
                    [
                        'search' => 'daf',
                        'replace' => 'application/vnd.mobius.daf',
                    ],
                    [
                        'search' => 'dart',
                        'replace' => 'application/vnd.dart',
                    ],
                    [
                        'search' => 'dataless',
                        'replace' => 'application/vnd.fdsn.seed',
                    ],
                    [
                        'search' => 'davmount',
                        'replace' => 'application/davmount+xml',
                    ],
                    [
                        'search' => 'db',
                        'replace' => 'application/vnd.sqlite3',
                    ],
                    [
                        'search' => 'dbf',
                        'replace' => 'application/vnd.dbf',
                    ],
                    [
                        'search' => 'dbk',
                        'replace' => 'application/docbook+xml',
                    ],
                    [
                        'search' => 'dcd',
                        'replace' => 'application/dcd',
                    ],
                    [
                        'search' => 'dcm',
                        'replace' => 'application/dicom',
                    ],
                    [
                        'search' => 'dcmp',
                        'replace' => 'application/vnd.dcmp+xml',
                    ],
                    [
                        'search' => 'dcr',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'dcurl',
                        'replace' => 'text/vnd.curl.dcurl',
                    ],
                    [
                        'search' => 'dd2',
                        'replace' => 'application/vnd.oma.dd2+xml',
                    ],
                    [
                        'search' => 'ddd',
                        'replace' => 'application/vnd.fujixerox.ddd',
                    ],
                    [
                        'search' => 'ddf',
                        'replace' => 'application/vnd.syncml.dmddf+xml',
                    ],
                    [
                        'search' => 'dds',
                        'replace' => 'image/vnd.ms-dds',
                    ],
                    [
                        'search' => 'deb',
                        'replace' => 'application/x-debian-package',
                    ],
                    [
                        'search' => 'def',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'deploy',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'der',
                        'replace' => 'application/x-x509-ca-cert',
                    ],
                    [
                        'search' => 'dfac',
                        'replace' => 'application/vnd.dreamfactory',
                    ],
                    [
                        'search' => 'dgc',
                        'replace' => 'application/x-dgc-compressed',
                    ],
                    [
                        'search' => 'dib',
                        'replace' => 'image/bmp',
                    ],
                    [
                        'search' => 'dic',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'did',
                        'replace' => 'application/did',
                    ],
                    [
                        'search' => 'dii',
                        'replace' => 'application/dii',
                    ],
                    [
                        'search' => 'dim',
                        'replace' => 'application/vnd.fastcopy-disk-image',
                    ],
                    [
                        'search' => 'dir',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'dis',
                        'replace' => 'application/vnd.mobius.dis',
                    ],
                    [
                        'search' => 'disposition-notification',
                        'replace' => 'message/disposition-notification',
                    ],
                    [
                        'search' => 'dist',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'distz',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'dit',
                        'replace' => 'application/dit',
                    ],
                    [
                        'search' => 'dive',
                        'replace' => 'application/vnd.patentdive',
                    ],
                    [
                        'search' => 'djv',
                        'replace' => 'image/vnd.djvu',
                    ],
                    [
                        'search' => 'djvu',
                        'replace' => 'image/vnd.djvu',
                    ],
                    [
                        'search' => 'dl',
                        'replace' => 'application/vnd.datalog',
                    ],
                    [
                        'search' => 'dll',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'dls',
                        'replace' => 'audio/dls',
                    ],
                    [
                        'search' => 'dmg',
                        'replace' => 'application/x-apple-diskimage',
                    ],
                    [
                        'search' => 'dmp',
                        'replace' => 'application/vnd.tcpdump.pcap',
                    ],
                    [
                        'search' => 'dms',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'dna',
                        'replace' => 'application/vnd.dna',
                    ],
                    [
                        'search' => 'dng',
                        'replace' => 'image/x-adobe-dng',
                    ],
                    [
                        'search' => 'do',
                        'replace' => 'application/vnd.httphone',
                    ],
                    [
                        'search' => 'doc',
                        'replace' => 'application/msword',
                    ],
                    [
                        'search' => 'docjson',
                        'replace' => 'application/vnd.document+json',
                    ],
                    [
                        'search' => 'docm',
                        'replace' => 'application/vnd.ms-word.document.macroenabled.12',
                    ],
                    [
                        'search' => 'docx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ],
                    [
                        'search' => 'dor',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'dot',
                        'replace' => 'application/msword',
                    ],
                    [
                        'search' => 'dotm',
                        'replace' => 'application/vnd.ms-word.template.macroenabled.12',
                    ],
                    [
                        'search' => 'dotx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
                    ],
                    [
                        'search' => 'dp',
                        'replace' => 'application/vnd.osgi.dp',
                    ],
                    [
                        'search' => 'dpg',
                        'replace' => 'application/vnd.dpgraph',
                    ],
                    [
                        'search' => 'dpx',
                        'replace' => 'image/dpx',
                    ],
                    [
                        'search' => 'dra',
                        'replace' => 'audio/vnd.dra',
                    ],
                    [
                        'search' => 'drle',
                        'replace' => 'image/dicom-rle',
                    ],
                    [
                        'search' => 'drm',
                        'replace' => 'application/vnd.procreate.dream',
                    ],
                    [
                        'search' => 'dsc',
                        'replace' => 'text/prs.lines.tag',
                    ],
                    [
                        'search' => 'dsm',
                        'replace' => 'application/vnd.desmume.movie',
                    ],
                    [
                        'search' => 'dspx',
                        'replace' => 'application/vnd.openvpi.dspx+json',
                    ],
                    [
                        'search' => 'dssc',
                        'replace' => 'application/dssc+der',
                    ],
                    [
                        'search' => 'dtb',
                        'replace' => 'application/x-dtbook+xml',
                    ],
                    [
                        'search' => 'dtd',
                        'replace' => 'application/xml-dtd',
                    ],
                    [
                        'search' => 'dts',
                        'replace' => 'audio/vnd.dts',
                    ],
                    [
                        'search' => 'dtshd',
                        'replace' => 'audio/vnd.dts.hd',
                    ],
                    [
                        'search' => 'dump',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'dvb',
                        'replace' => 'video/vnd.dvb.file',
                    ],
                    [
                        'search' => 'dvc',
                        'replace' => 'application/dvcs',
                    ],
                    [
                        'search' => 'dvi',
                        'replace' => 'application/x-dvi',
                    ],
                    [
                        'search' => 'dwd',
                        'replace' => 'application/atsc-dwd+xml',
                    ],
                    [
                        'search' => 'dwf',
                        'replace' => 'model/vnd.dwf',
                    ],
                    [
                        'search' => 'dwg',
                        'replace' => 'image/vnd.dwg',
                    ],
                    [
                        'search' => 'dxf',
                        'replace' => 'image/vnd.dxf',
                    ],
                    [
                        'search' => 'dxp',
                        'replace' => 'application/vnd.spotfire.dxp',
                    ],
                    [
                        'search' => 'dxr',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'dzr',
                        'replace' => 'application/vnd.dzr',
                    ],
                    [
                        'search' => 'ear',
                        'replace' => 'application/java-archive',
                    ],
                    [
                        'search' => 'ebuild',
                        'replace' => 'application/vnd.gentoo.ebuild',
                    ],
                    [
                        'search' => 'ecelp4800',
                        'replace' => 'audio/vnd.nuera.ecelp4800',
                    ],
                    [
                        'search' => 'ecelp7470',
                        'replace' => 'audio/vnd.nuera.ecelp7470',
                    ],
                    [
                        'search' => 'ecelp9600',
                        'replace' => 'audio/vnd.nuera.ecelp9600',
                    ],
                    [
                        'search' => 'ecig',
                        'replace' => 'application/vnd.evolv.ecig.settings',
                    ],
                    [
                        'search' => 'ecigprofile',
                        'replace' => 'application/vnd.evolv.ecig.profile',
                    ],
                    [
                        'search' => 'ecigtheme',
                        'replace' => 'application/vnd.evolv.ecig.theme',
                    ],
                    [
                        'search' => 'eclass',
                        'replace' => 'application/vnd.gentoo.eclass',
                    ],
                    [
                        'search' => 'ecma',
                        'replace' => 'application/ecmascript',
                    ],
                    [
                        'search' => 'edm',
                        'replace' => 'application/vnd.novadigm.edm',
                    ],
                    [
                        'search' => 'edx',
                        'replace' => 'application/vnd.novadigm.edx',
                    ],
                    [
                        'search' => 'efi',
                        'replace' => 'application/efi',
                    ],
                    [
                        'search' => 'efif',
                        'replace' => 'application/vnd.picsel',
                    ],
                    [
                        'search' => 'ei6',
                        'replace' => 'application/vnd.pg.osasli',
                    ],
                    [
                        'search' => 'elc',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'eln',
                        'replace' => 'application/vnd.eln+zip',
                    ],
                    [
                        'search' => 'emf',
                        'replace' => 'image/emf',
                    ],
                    [
                        'search' => 'eml',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'emm',
                        'replace' => 'application/vnd.ibm.electronic-media',
                    ],
                    [
                        'search' => 'emma',
                        'replace' => 'application/emma+xml',
                    ],
                    [
                        'search' => 'emotionml',
                        'replace' => 'application/emotionml+xml',
                    ],
                    [
                        'search' => 'emz',
                        'replace' => 'application/x-msmetafile',
                    ],
                    [
                        'search' => 'ent',
                        'replace' => 'application/xml-external-parsed-entity',
                    ],
                    [
                        'search' => 'entity',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'enw',
                        'replace' => 'audio/evrcnw',
                    ],
                    [
                        'search' => 'eol',
                        'replace' => 'audio/vnd.digital-winds',
                    ],
                    [
                        'search' => 'eot',
                        'replace' => 'application/vnd.ms-fontobject',
                    ],
                    [
                        'search' => 'ep',
                        'replace' => 'application/vnd.bluetooth.ep.oob',
                    ],
                    [
                        'search' => 'eps',
                        'replace' => 'application/postscript',
                    ],
                    [
                        'search' => 'epub',
                        'replace' => 'application/epub+zip',
                    ],
                    [
                        'search' => 'erofs',
                        'replace' => 'application/vnd.erofs',
                    ],
                    [
                        'search' => 'es',
                        'replace' => 'application/ecmascript',
                    ],
                    [
                        'search' => 'es3',
                        'replace' => 'application/vnd.eszigno3+xml',
                    ],
                    [
                        'search' => 'esa',
                        'replace' => 'application/vnd.osgi.subsystem',
                    ],
                    [
                        'search' => 'esf',
                        'replace' => 'application/vnd.epson.esf',
                    ],
                    [
                        'search' => 'espass',
                        'replace' => 'application/vnd.espass-espass+zip',
                    ],
                    [
                        'search' => 'et3',
                        'replace' => 'application/vnd.eszigno3+xml',
                    ],
                    [
                        'search' => 'etx',
                        'replace' => 'text/x-setext',
                    ],
                    [
                        'search' => 'eva',
                        'replace' => 'application/x-eva',
                    ],
                    [
                        'search' => 'evb',
                        'replace' => 'audio/evrcb',
                    ],
                    [
                        'search' => 'evc',
                        'replace' => 'audio/evrc',
                    ],
                    [
                        'search' => 'evw',
                        'replace' => 'audio/evrcwb',
                    ],
                    [
                        'search' => 'evy',
                        'replace' => 'application/x-envoy',
                    ],
                    [
                        'search' => 'exe',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'exi',
                        'replace' => 'application/exi',
                    ],
                    [
                        'search' => 'exp',
                        'replace' => 'application/express',
                    ],
                    [
                        'search' => 'exr',
                        'replace' => 'image/aces',
                    ],
                    [
                        'search' => 'ext',
                        'replace' => 'application/vnd.novadigm.ext',
                    ],
                    [
                        'search' => 'ez',
                        'replace' => 'application/andrew-inset',
                    ],
                    [
                        'search' => 'ez2',
                        'replace' => 'application/vnd.ezpix-album',
                    ],
                    [
                        'search' => 'ez3',
                        'replace' => 'application/vnd.ezpix-package',
                    ],
                    [
                        'search' => 'f',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'f4v',
                        'replace' => 'video/x-f4v',
                    ],
                    [
                        'search' => 'f77',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'f90',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'facta',
                        'replace' => 'audio/vnd.blockfact.facta',
                    ],
                    [
                        'search' => 'facti',
                        'replace' => 'image/vnd.blockfact.facti',
                    ],
                    [
                        'search' => 'factv',
                        'replace' => 'video/vnd.blockfact.factv',
                    ],
                    [
                        'search' => 'fbs',
                        'replace' => 'image/vnd.fastbidsheet',
                    ],
                    [
                        'search' => 'fbx',
                        'replace' => 'application/vnd.autodesk.fbx',
                    ],
                    [
                        'search' => 'fcdt',
                        'replace' => 'application/vnd.adobe.formscentral.fcdt',
                    ],
                    [
                        'search' => 'fcs',
                        'replace' => 'application/vnd.isac.fcs',
                    ],
                    [
                        'search' => 'fdf',
                        'replace' => 'application/vnd.fdf',
                    ],
                    [
                        'search' => 'fdt',
                        'replace' => 'application/fdt+xml',
                    ],
                    [
                        'search' => 'fe_launch',
                        'replace' => 'application/vnd.denovo.fcselayout-link',
                    ],
                    [
                        'search' => 'fg5',
                        'replace' => 'application/vnd.fujitsu.oasysgp',
                    ],
                    [
                        'search' => 'fgb',
                        'replace' => 'application/vnd.fgb',
                    ],
                    [
                        'search' => 'fgd',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'fh',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fh4',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fh5',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fh7',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fhc',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fig',
                        'replace' => 'application/x-xfig',
                    ],
                    [
                        'search' => 'finf',
                        'replace' => 'application/fastinfoset',
                    ],
                    [
                        'search' => 'fits',
                        'replace' => 'image/fits',
                    ],
                    [
                        'search' => 'fla',
                        'replace' => 'application/vnd.dtg.local.flash',
                    ],
                    [
                        'search' => 'flac',
                        'replace' => 'audio/flac',
                    ],
                    [
                        'search' => 'flb',
                        'replace' => 'application/vnd.ficlab.flb+zip',
                    ],
                    [
                        'search' => 'fli',
                        'replace' => 'video/x-fli',
                    ],
                    [
                        'search' => 'flo',
                        'replace' => 'application/vnd.micrografx.flo',
                    ],
                    [
                        'search' => 'flt',
                        'replace' => 'text/vnd.ficlab.flt',
                    ],
                    [
                        'search' => 'flv',
                        'replace' => 'video/x-flv',
                    ],
                    [
                        'search' => 'flw',
                        'replace' => 'application/vnd.kde.kivio',
                    ],
                    [
                        'search' => 'flx',
                        'replace' => 'text/vnd.fmi.flexstor',
                    ],
                    [
                        'search' => 'fly',
                        'replace' => 'text/vnd.fly',
                    ],
                    [
                        'search' => 'fm',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'fnc',
                        'replace' => 'application/vnd.frogans.fnc',
                    ],
                    [
                        'search' => 'fo',
                        'replace' => 'application/vnd.software602.filler.form+xml',
                    ],
                    [
                        'search' => 'font',
                        'replace' => 'font/ttf',
                    ],
                    [
                        'search' => 'for',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'fpx',
                        'replace' => 'image/vnd.fpx',
                    ],
                    [
                        'search' => 'frame',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'frm',
                        'replace' => 'application/vnd.xfdl',
                    ],
                    [
                        'search' => 'fsc',
                        'replace' => 'application/vnd.fsc.weblaunch',
                    ],
                    [
                        'search' => 'fst',
                        'replace' => 'image/vnd.fst',
                    ],
                    [
                        'search' => 'ftc',
                        'replace' => 'application/vnd.fluxtime.clip',
                    ],
                    [
                        'search' => 'fti',
                        'replace' => 'application/vnd.anser-web-funds-transfer-initiation',
                    ],
                    [
                        'search' => 'fvt',
                        'replace' => 'video/vnd.fvt',
                    ],
                    [
                        'search' => 'fxp',
                        'replace' => 'application/vnd.adobe.fxp',
                    ],
                    [
                        'search' => 'fxpl',
                        'replace' => 'application/vnd.adobe.fxp',
                    ],
                    [
                        'search' => 'fzs',
                        'replace' => 'application/vnd.fuzzysheet',
                    ],
                    [
                        'search' => 'g2w',
                        'replace' => 'application/vnd.geoplan',
                    ],
                    [
                        'search' => 'g3',
                        'replace' => 'image/g3fax',
                    ],
                    [
                        'search' => 'g3fc',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc0',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc1',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc2',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc3',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc4',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc5',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc6',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc7',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc8',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc9',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3w',
                        'replace' => 'application/vnd.geospace',
                    ],
                    [
                        'search' => 'gac',
                        'replace' => 'application/vnd.groove-account',
                    ],
                    [
                        'search' => 'gam',
                        'replace' => 'application/x-tads',
                    ],
                    [
                        'search' => 'gbr',
                        'replace' => 'application/rpki-ghostbusters',
                    ],
                    [
                        'search' => 'gca',
                        'replace' => 'application/x-gca-compressed',
                    ],
                    [
                        'search' => 'gdl',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'gdoc',
                        'replace' => 'application/vnd.google-apps.document',
                    ],
                    [
                        'search' => 'gdraw',
                        'replace' => 'application/vnd.google-apps.drawing',
                    ],
                    [
                        'search' => 'gdz',
                        'replace' => 'application/vnd.familysearch.gedcom+zip',
                    ],
                    [
                        'search' => 'ged',
                        'replace' => 'text/vnd.familysearch.gedcom',
                    ],
                    [
                        'search' => 'genozip',
                        'replace' => 'application/vnd.genozip',
                    ],
                    [
                        'search' => 'geo',
                        'replace' => 'application/vnd.dynageo',
                    ],
                    [
                        'search' => 'geojson',
                        'replace' => 'application/geo+json',
                    ],
                    [
                        'search' => 'gex',
                        'replace' => 'application/vnd.geometry-explorer',
                    ],
                    [
                        'search' => 'gff3',
                        'replace' => 'text/gff3',
                    ],
                    [
                        'search' => 'gform',
                        'replace' => 'application/vnd.google-apps.form',
                    ],
                    [
                        'search' => 'ggb',
                        'replace' => 'application/vnd.geogebra.file',
                    ],
                    [
                        'search' => 'ggp',
                        'replace' => 'application/vnd.geogebra.pinboard',
                    ],
                    [
                        'search' => 'ggs',
                        'replace' => 'application/vnd.geogebra.slides',
                    ],
                    [
                        'search' => 'ggt',
                        'replace' => 'application/vnd.geogebra.tool',
                    ],
                    [
                        'search' => 'ghf',
                        'replace' => 'application/vnd.groove-help',
                    ],
                    [
                        'search' => 'gif',
                        'replace' => 'image/gif',
                    ],
                    [
                        'search' => 'gim',
                        'replace' => 'application/vnd.groove-identity-message',
                    ],
                    [
                        'search' => 'gjam',
                        'replace' => 'application/vnd.google-apps.jam',
                    ],
                    [
                        'search' => 'glb',
                        'replace' => 'model/gltf-binary',
                    ],
                    [
                        'search' => 'glbin',
                        'replace' => 'application/gltf-buffer',
                    ],
                    [
                        'search' => 'glbuf',
                        'replace' => 'application/gltf-buffer',
                    ],
                    [
                        'search' => 'gltf',
                        'replace' => 'model/gltf+json',
                    ],
                    [
                        'search' => 'gmap',
                        'replace' => 'application/vnd.google-apps.map',
                    ],
                    [
                        'search' => 'gml',
                        'replace' => 'application/gml+xml',
                    ],
                    [
                        'search' => 'gmx',
                        'replace' => 'application/vnd.gmx',
                    ],
                    [
                        'search' => 'gnumeric',
                        'replace' => 'application/x-gnumeric',
                    ],
                    [
                        'search' => 'gph',
                        'replace' => 'application/vnd.flographit',
                    ],
                    [
                        'search' => 'gpkg',
                        'replace' => 'application/geopackage+sqlite3',
                    ],
                    [
                        'search' => 'gpkg.tar',
                        'replace' => 'application/vnd.gentoo.gpkg',
                    ],
                    [
                        'search' => 'gpx',
                        'replace' => 'application/gpx+xml',
                    ],
                    [
                        'search' => 'gqf',
                        'replace' => 'application/vnd.grafeq',
                    ],
                    [
                        'search' => 'gqs',
                        'replace' => 'application/vnd.grafeq',
                    ],
                    [
                        'search' => 'gram',
                        'replace' => 'application/srgs',
                    ],
                    [
                        'search' => 'gramps',
                        'replace' => 'application/x-gramps-xml',
                    ],
                    [
                        'search' => 'grd',
                        'replace' => 'application/vnd.gentics.grd+json',
                    ],
                    [
                        'search' => 'gre',
                        'replace' => 'application/vnd.geometry-explorer',
                    ],
                    [
                        'search' => 'grv',
                        'replace' => 'application/vnd.groove-injector',
                    ],
                    [
                        'search' => 'grxml',
                        'replace' => 'application/srgs+xml',
                    ],
                    [
                        'search' => 'gscript',
                        'replace' => 'application/vnd.google-apps.script',
                    ],
                    [
                        'search' => 'gsf',
                        'replace' => 'application/x-font-ghostscript',
                    ],
                    [
                        'search' => 'gsheet',
                        'replace' => 'application/vnd.google-apps.spreadsheet',
                    ],
                    [
                        'search' => 'gsite',
                        'replace' => 'application/vnd.google-apps.site',
                    ],
                    [
                        'search' => 'gslides',
                        'replace' => 'application/vnd.google-apps.presentation',
                    ],
                    [
                        'search' => 'gsm',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'gtar',
                        'replace' => 'application/x-gtar',
                    ],
                    [
                        'search' => 'gtm',
                        'replace' => 'application/vnd.groove-tool-message',
                    ],
                    [
                        'search' => 'gtw',
                        'replace' => 'model/vnd.gtw',
                    ],
                    [
                        'search' => 'gv',
                        'replace' => 'text/vnd.graphviz',
                    ],
                    [
                        'search' => 'gxf',
                        'replace' => 'application/gxf',
                    ],
                    [
                        'search' => 'gxt',
                        'replace' => 'application/vnd.geonext',
                    ],
                    [
                        'search' => 'gz',
                        'replace' => 'application/gzip',
                    ],
                    [
                        'search' => 'h',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'h261',
                        'replace' => 'video/h261',
                    ],
                    [
                        'search' => 'h263',
                        'replace' => 'video/h263',
                    ],
                    [
                        'search' => 'h264',
                        'replace' => 'video/h264',
                    ],
                    [
                        'search' => 'h5',
                        'replace' => 'application/sipc',
                    ],
                    [
                        'search' => 'hal',
                        'replace' => 'application/vnd.hal+xml',
                    ],
                    [
                        'search' => 'hans',
                        'replace' => 'text/vnd.hans',
                    ],
                    [
                        'search' => 'haptic',
                        'replace' => 'haptics/ivs',
                    ],
                    [
                        'search' => 'hbc',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'hbci',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'hbs',
                        'replace' => 'text/x-handlebars-template',
                    ],
                    [
                        'search' => 'hdd',
                        'replace' => 'application/x-virtualbox-hdd',
                    ],
                    [
                        'search' => 'hdf',
                        'replace' => 'application/x-hdf',
                    ],
                    [
                        'search' => 'hdr',
                        'replace' => 'image/vnd.radiance',
                    ],
                    [
                        'search' => 'hdt',
                        'replace' => 'application/vnd.hdt',
                    ],
                    [
                        'search' => 'heic',
                        'replace' => 'image/heic',
                    ],
                    [
                        'search' => 'heics',
                        'replace' => 'image/heic-sequence',
                    ],
                    [
                        'search' => 'heif',
                        'replace' => 'image/heif',
                    ],
                    [
                        'search' => 'heifs',
                        'replace' => 'image/heif-sequence',
                    ],
                    [
                        'search' => 'hej2',
                        'replace' => 'image/hej2k',
                    ],
                    [
                        'search' => 'held',
                        'replace' => 'application/atsc-held+xml',
                    ],
                    [
                        'search' => 'heldxml',
                        'replace' => 'application/held+xml',
                    ],
                    [
                        'search' => 'hgl',
                        'replace' => 'text/vnd.hgl',
                    ],
                    [
                        'search' => 'hh',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'hif',
                        'replace' => 'image/heic',
                    ],
                    [
                        'search' => 'hjson',
                        'replace' => 'application/hjson',
                    ],
                    [
                        'search' => 'hlp',
                        'replace' => 'application/winhlp',
                    ],
                    [
                        'search' => 'hpgl',
                        'replace' => 'application/vnd.hp-hpgl',
                    ],
                    [
                        'search' => 'hpi',
                        'replace' => 'application/vnd.hp-hpid',
                    ],
                    [
                        'search' => 'hpid',
                        'replace' => 'application/vnd.hp-hpid',
                    ],
                    [
                        'search' => 'hps',
                        'replace' => 'application/vnd.hp-hps',
                    ],
                    [
                        'search' => 'hpub',
                        'replace' => 'application/prs.hpub+zip',
                    ],
                    [
                        'search' => 'hqx',
                        'replace' => 'application/mac-binhex40',
                    ],
                    [
                        'search' => 'hsl',
                        'replace' => 'application/vnd.hsl',
                    ],
                    [
                        'search' => 'htc',
                        'replace' => 'text/x-component',
                    ],
                    [
                        'search' => 'htke',
                        'replace' => 'application/vnd.kenameaapp',
                    ],
                    [
                        'search' => 'htm',
                        'replace' => 'text/html',
                    ],
                    [
                        'search' => 'html',
                        'replace' => 'text/html',
                    ],
                    [
                        'search' => 'hvd',
                        'replace' => 'application/vnd.yamaha.hv-dic',
                    ],
                    [
                        'search' => 'hvp',
                        'replace' => 'application/vnd.yamaha.hv-voice',
                    ],
                    [
                        'search' => 'hvs',
                        'replace' => 'application/vnd.yamaha.hv-script',
                    ],
                    [
                        'search' => 'i2g',
                        'replace' => 'application/vnd.intergeo',
                    ],
                    [
                        'search' => 'ica',
                        'replace' => 'application/vnd.commerce-battelle',
                    ],
                    [
                        'search' => 'icc',
                        'replace' => 'application/vnd.iccprofile',
                    ],
                    [
                        'search' => 'icd',
                        'replace' => 'application/vnd.commerce-battelle',
                    ],
                    [
                        'search' => 'ice',
                        'replace' => 'x-conference/x-cooltalk',
                    ],
                    [
                        'search' => 'icf',
                        'replace' => 'application/vnd.commerce-battelle',
                    ],
                    [
                        'search' => 'icm',
                        'replace' => 'application/vnd.iccprofile',
                    ],
                    [
                        'search' => 'ico',
                        'replace' => 'image/vnd.microsoft.icon',
                    ],
                    [
                        'search' => 'ics',
                        'replace' => 'text/calendar',
                    ],
                    [
                        'search' => 'ief',
                        'replace' => 'image/ief',
                    ],
                    [
                        'search' => 'iepd',
                        'replace' => 'application/vnd.nato.openxmlformats-package.iepd+zip',
                    ],
                    [
                        'search' => 'ifb',
                        'replace' => 'text/calendar',
                    ],
                    [
                        'search' => 'ifc',
                        'replace' => 'application/p21',
                    ],
                    [
                        'search' => 'ifm',
                        'replace' => 'application/vnd.shana.informed.formdata',
                    ],
                    [
                        'search' => 'iges',
                        'replace' => 'model/iges',
                    ],
                    [
                        'search' => 'igl',
                        'replace' => 'application/vnd.igloader',
                    ],
                    [
                        'search' => 'igm',
                        'replace' => 'application/vnd.insors.igm',
                    ],
                    [
                        'search' => 'ign',
                        'replace' => 'application/vnd.coreos.ignition+json',
                    ],
                    [
                        'search' => 'ignition',
                        'replace' => 'application/vnd.coreos.ignition+json',
                    ],
                    [
                        'search' => 'igs',
                        'replace' => 'model/iges',
                    ],
                    [
                        'search' => 'igx',
                        'replace' => 'application/vnd.micrografx.igx',
                    ],
                    [
                        'search' => 'iif',
                        'replace' => 'application/vnd.shana.informed.interchange',
                    ],
                    [
                        'search' => 'imf',
                        'replace' => 'application/vnd.imagemeter.folder+zip',
                    ],
                    [
                        'search' => 'img',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'imgcal',
                        'replace' => 'application/vnd.3lightssoftware.imagescal',
                    ],
                    [
                        'search' => 'imi',
                        'replace' => 'application/vnd.imagemeter.image+zip',
                    ],
                    [
                        'search' => 'imp',
                        'replace' => 'application/vnd.accpac.simply.imp',
                    ],
                    [
                        'search' => 'ims',
                        'replace' => 'application/vnd.ms-ims',
                    ],
                    [
                        'search' => 'imscc',
                        'replace' => 'application/vnd.ims.imsccv1p3',
                    ],
                    [
                        'search' => 'in',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'ini',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'ink',
                        'replace' => 'application/inkml+xml',
                    ],
                    [
                        'search' => 'inkml',
                        'replace' => 'application/inkml+xml',
                    ],
                    [
                        'search' => 'install',
                        'replace' => 'application/x-install-instructions',
                    ],
                    [
                        'search' => 'iota',
                        'replace' => 'application/vnd.astraea-software.iota',
                    ],
                    [
                        'search' => 'ipfix',
                        'replace' => 'application/ipfix',
                    ],
                    [
                        'search' => 'ipk',
                        'replace' => 'application/vnd.shana.informed.package',
                    ],
                    [
                        'search' => 'ipns-record',
                        'replace' => 'application/vnd.ipfs.ipns-record',
                    ],
                    [
                        'search' => 'ipynb',
                        'replace' => 'application/x-ipynb+json',
                    ],
                    [
                        'search' => 'irm',
                        'replace' => 'application/vnd.ibm.rights-management',
                    ],
                    [
                        'search' => 'irp',
                        'replace' => 'application/vnd.irepository.package+xml',
                    ],
                    [
                        'search' => 'ism',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'iso',
                        'replace' => 'application/x-iso9660-image',
                    ],
                    [
                        'search' => 'istc',
                        'replace' => 'application/vnd.veryant.thin',
                    ],
                    [
                        'search' => 'isws',
                        'replace' => 'application/vnd.veryant.thin',
                    ],
                    [
                        'search' => 'itp',
                        'replace' => 'application/vnd.shana.informed.formtemplate',
                    ],
                    [
                        'search' => 'its',
                        'replace' => 'application/its+xml',
                    ],
                    [
                        'search' => 'ivp',
                        'replace' => 'application/vnd.immervision-ivp',
                    ],
                    [
                        'search' => 'ivu',
                        'replace' => 'application/vnd.immervision-ivu',
                    ],
                    [
                        'search' => 'j2c',
                        'replace' => 'image/j2c',
                    ],
                    [
                        'search' => 'j2k',
                        'replace' => 'image/j2c',
                    ],
                    [
                        'search' => 'jad',
                        'replace' => 'text/vnd.sun.j2me.app-descriptor',
                    ],
                    [
                        'search' => 'jade',
                        'replace' => 'text/jade',
                    ],
                    [
                        'search' => 'jaii',
                        'replace' => 'image/jaii',
                    ],
                    [
                        'search' => 'jais',
                        'replace' => 'image/jais',
                    ],
                    [
                        'search' => 'jam',
                        'replace' => 'application/vnd.jam',
                    ],
                    [
                        'search' => 'jar',
                        'replace' => 'application/java-archive',
                    ],
                    [
                        'search' => 'jardiff',
                        'replace' => 'application/x-java-archive-diff',
                    ],
                    [
                        'search' => 'java',
                        'replace' => 'text/x-java-source',
                    ],
                    [
                        'search' => 'jfif',
                        'replace' => 'image/pjpeg',
                    ],
                    [
                        'search' => 'jhc',
                        'replace' => 'image/jphc',
                    ],
                    [
                        'search' => 'jisp',
                        'replace' => 'application/vnd.jisp',
                    ],
                    [
                        'search' => 'jls',
                        'replace' => 'image/jls',
                    ],
                    [
                        'search' => 'jlt',
                        'replace' => 'application/vnd.hp-jlyt',
                    ],
                    [
                        'search' => 'jng',
                        'replace' => 'image/x-jng',
                    ],
                    [
                        'search' => 'jnlp',
                        'replace' => 'application/x-java-jnlp-file',
                    ],
                    [
                        'search' => 'joda',
                        'replace' => 'application/vnd.joost.joda-archive',
                    ],
                    [
                        'search' => 'jp2',
                        'replace' => 'image/jp2',
                    ],
                    [
                        'search' => 'jpe',
                        'replace' => 'image/jpeg',
                    ],
                    [
                        'search' => 'jpeg',
                        'replace' => 'image/jpeg',
                    ],
                    [
                        'search' => 'jpf',
                        'replace' => 'image/jpx',
                    ],
                    [
                        'search' => 'jpg',
                        'replace' => 'image/jpeg',
                    ],
                    [
                        'search' => 'jpg2',
                        'replace' => 'image/jp2',
                    ],
                    [
                        'search' => 'jpgm',
                        'replace' => 'image/jpm',
                    ],
                    [
                        'search' => 'jpgv',
                        'replace' => 'video/jpeg',
                    ],
                    [
                        'search' => 'jph',
                        'replace' => 'image/jph',
                    ],
                    [
                        'search' => 'jpm',
                        'replace' => 'image/jpm',
                    ],
                    [
                        'search' => 'jpx',
                        'replace' => 'image/jpx',
                    ],
                    [
                        'search' => 'jrd',
                        'replace' => 'application/jrd+json',
                    ],
                    [
                        'search' => 'js',
                        'replace' => 'text/javascript',
                    ],
                    [
                        'search' => 'json',
                        'replace' => 'application/json',
                    ],
                    [
                        'search' => 'json-patch',
                        'replace' => 'application/json-patch+json',
                    ],
                    [
                        'search' => 'json5',
                        'replace' => 'application/json5',
                    ],
                    [
                        'search' => 'jsonld',
                        'replace' => 'application/ld+json',
                    ],
                    [
                        'search' => 'jsonml',
                        'replace' => 'application/jsonml+json',
                    ],
                    [
                        'search' => 'jsontd',
                        'replace' => 'application/td+json',
                    ],
                    [
                        'search' => 'jsontm',
                        'replace' => 'application/tm+json',
                    ],
                    [
                        'search' => 'jsx',
                        'replace' => 'text/jsx',
                    ],
                    [
                        'search' => 'jt',
                        'replace' => 'model/jt',
                    ],
                    [
                        'search' => 'jtd',
                        'replace' => 'text/vnd.esmertec.theme-descriptor',
                    ],
                    [
                        'search' => 'jxl',
                        'replace' => 'image/jxl',
                    ],
                    [
                        'search' => 'jxr',
                        'replace' => 'image/jxr',
                    ],
                    [
                        'search' => 'jxra',
                        'replace' => 'image/jxra',
                    ],
                    [
                        'search' => 'jxrs',
                        'replace' => 'image/jxrs',
                    ],
                    [
                        'search' => 'jxs',
                        'replace' => 'image/jxs',
                    ],
                    [
                        'search' => 'jxsc',
                        'replace' => 'image/jxsc',
                    ],
                    [
                        'search' => 'jxsi',
                        'replace' => 'image/jxsi',
                    ],
                    [
                        'search' => 'jxss',
                        'replace' => 'image/jxss',
                    ],
                    [
                        'search' => 'kar',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'karbon',
                        'replace' => 'application/vnd.kde.karbon',
                    ],
                    [
                        'search' => 'kcl',
                        'replace' => 'text/vnd.zoo.kcl',
                    ],
                    [
                        'search' => 'kcm',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'kdbx',
                        'replace' => 'application/x-keepass2',
                    ],
                    [
                        'search' => 'kdl',
                        'replace' => 'application/vnd.kdl',
                    ],
                    [
                        'search' => 'key',
                        'replace' => 'application/vnd.apple.keynote',
                    ],
                    [
                        'search' => 'kfo',
                        'replace' => 'application/vnd.kde.kformula',
                    ],
                    [
                        'search' => 'kia',
                        'replace' => 'application/vnd.kidspiration',
                    ],
                    [
                        'search' => 'kml',
                        'replace' => 'application/vnd.google-earth.kml+xml',
                    ],
                    [
                        'search' => 'kmp',
                        'replace' => 'application/vnd.keyman.kmp+zip',
                    ],
                    [
                        'search' => 'kmx',
                        'replace' => 'application/vnd.keyman.kmx',
                    ],
                    [
                        'search' => 'kmz',
                        'replace' => 'application/vnd.google-earth.kmz',
                    ],
                    [
                        'search' => 'kne',
                        'replace' => 'application/vnd.kinar',
                    ],
                    [
                        'search' => 'knp',
                        'replace' => 'application/vnd.kinar',
                    ],
                    [
                        'search' => 'kom',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'kon',
                        'replace' => 'application/vnd.kde.kontour',
                    ],
                    [
                        'search' => 'koz',
                        'replace' => 'audio/vnd.audiokoz',
                    ],
                    [
                        'search' => 'kpr',
                        'replace' => 'application/vnd.kde.kpresenter',
                    ],
                    [
                        'search' => 'kpt',
                        'replace' => 'application/vnd.kde.kpresenter',
                    ],
                    [
                        'search' => 'kpxx',
                        'replace' => 'application/vnd.ds-keypoint',
                    ],
                    [
                        'search' => 'ksp',
                        'replace' => 'application/vnd.kde.kspread',
                    ],
                    [
                        'search' => 'ktr',
                        'replace' => 'application/vnd.kahootz',
                    ],
                    [
                        'search' => 'ktx',
                        'replace' => 'image/ktx',
                    ],
                    [
                        'search' => 'ktx2',
                        'replace' => 'image/ktx2',
                    ],
                    [
                        'search' => 'ktz',
                        'replace' => 'application/vnd.kahootz',
                    ],
                    [
                        'search' => 'kwd',
                        'replace' => 'application/vnd.kde.kword',
                    ],
                    [
                        'search' => 'kwt',
                        'replace' => 'application/vnd.kde.kword',
                    ],
                    [
                        'search' => 'las',
                        'replace' => 'application/vnd.las',
                    ],
                    [
                        'search' => 'lasjson',
                        'replace' => 'application/vnd.las.las+json',
                    ],
                    [
                        'search' => 'lasxml',
                        'replace' => 'application/vnd.las.las+xml',
                    ],
                    [
                        'search' => 'latex',
                        'replace' => 'application/x-latex',
                    ],
                    [
                        'search' => 'laz',
                        'replace' => 'application/vnd.laszip',
                    ],
                    [
                        'search' => 'lbc',
                        'replace' => 'audio/ilbc',
                    ],
                    [
                        'search' => 'lbd',
                        'replace' => 'application/vnd.llamagraphics.life-balance.desktop',
                    ],
                    [
                        'search' => 'lbe',
                        'replace' => 'application/vnd.llamagraphics.life-balance.exchange+xml',
                    ],
                    [
                        'search' => 'lca',
                        'replace' => 'application/vnd.logipipe.circuit+zip',
                    ],
                    [
                        'search' => 'lcs',
                        'replace' => 'application/vnd.logipipe.circuit+zip',
                    ],
                    [
                        'search' => 'le',
                        'replace' => 'application/vnd.bluetooth.le.oob',
                    ],
                    [
                        'search' => 'les',
                        'replace' => 'application/vnd.hhe.lesson-player',
                    ],
                    [
                        'search' => 'less',
                        'replace' => 'text/less',
                    ],
                    [
                        'search' => 'lgr',
                        'replace' => 'application/lgr+xml',
                    ],
                    [
                        'search' => 'lha',
                        'replace' => 'application/x-lzh-compressed',
                    ],
                    [
                        'search' => 'lhzd',
                        'replace' => 'application/vnd.belightsoft.lhzd+zip',
                    ],
                    [
                        'search' => 'lhzl',
                        'replace' => 'application/vnd.belightsoft.lhzl+zip',
                    ],
                    [
                        'search' => 'line',
                        'replace' => 'application/vnd.nebumind.line',
                    ],
                    [
                        'search' => 'link66',
                        'replace' => 'application/vnd.route66.link66+xml',
                    ],
                    [
                        'search' => 'list',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'list3820',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'listafp',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'litcoffee',
                        'replace' => 'text/coffeescript',
                    ],
                    [
                        'search' => 'lmp',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'lnk',
                        'replace' => 'application/x-ms-shortcut',
                    ],
                    [
                        'search' => 'loas',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'log',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'loom',
                        'replace' => 'application/vnd.loom',
                    ],
                    [
                        'search' => 'lostsyncxml',
                        'replace' => 'application/lostsync+xml',
                    ],
                    [
                        'search' => 'lostxml',
                        'replace' => 'application/lost+xml',
                    ],
                    [
                        'search' => 'lot',
                        'replace' => 'video/lottie+json',
                    ],
                    [
                        'search' => 'lottie',
                        'replace' => 'application/zip+dotlottie',
                    ],
                    [
                        'search' => 'lpf',
                        'replace' => 'application/lpf+zip',
                    ],
                    [
                        'search' => 'lrf',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'lrm',
                        'replace' => 'application/vnd.ms-lrm',
                    ],
                    [
                        'search' => 'ltf',
                        'replace' => 'application/vnd.frogans.ltf',
                    ],
                    [
                        'search' => 'lua',
                        'replace' => 'text/x-lua',
                    ],
                    [
                        'search' => 'luac',
                        'replace' => 'application/x-lua-bytecode',
                    ],
                    [
                        'search' => 'lvp',
                        'replace' => 'audio/vnd.lucent.voice',
                    ],
                    [
                        'search' => 'lwp',
                        'replace' => 'application/vnd.lotus-wordpro',
                    ],
                    [
                        'search' => 'lxf',
                        'replace' => 'application/lxf',
                    ],
                    [
                        'search' => 'lzh',
                        'replace' => 'application/x-lzh-compressed',
                    ],
                    [
                        'search' => 'm',
                        'replace' => 'application/vnd.wolfram.mathematica.package',
                    ],
                    [
                        'search' => 'm13',
                        'replace' => 'application/x-msmediaview',
                    ],
                    [
                        'search' => 'm14',
                        'replace' => 'application/x-msmediaview',
                    ],
                    [
                        'search' => 'm1v',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'm21',
                        'replace' => 'application/mp21',
                    ],
                    [
                        'search' => 'm2a',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'm2t',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'm2ts',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'm2v',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'm3a',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'm3u',
                        'replace' => 'audio/x-mpegurl',
                    ],
                    [
                        'search' => 'm3u8',
                        'replace' => 'application/vnd.apple.mpegurl',
                    ],
                    [
                        'search' => 'm4a',
                        'replace' => 'audio/mp4',
                    ],
                    [
                        'search' => 'm4b',
                        'replace' => 'audio/mp4',
                    ],
                    [
                        'search' => 'm4p',
                        'replace' => 'application/mp4',
                    ],
                    [
                        'search' => 'm4s',
                        'replace' => 'video/iso.segment',
                    ],
                    [
                        'search' => 'm4u',
                        'replace' => 'video/vnd.mpegurl',
                    ],
                    [
                        'search' => 'm4v',
                        'replace' => 'video/x-m4v',
                    ],
                    [
                        'search' => 'ma',
                        'replace' => 'application/mathematica',
                    ],
                    [
                        'search' => 'mads',
                        'replace' => 'application/mads+xml',
                    ],
                    [
                        'search' => 'maei',
                        'replace' => 'application/mmt-aei+xml',
                    ],
                    [
                        'search' => 'mag',
                        'replace' => 'application/vnd.ecowin.chart',
                    ],
                    [
                        'search' => 'maker',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'man',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'manifest',
                        'replace' => 'text/cache-manifest',
                    ],
                    [
                        'search' => 'map',
                        'replace' => 'application/json',
                    ],
                    [
                        'search' => 'mar',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'markdown',
                        'replace' => 'text/markdown',
                    ],
                    [
                        'search' => 'mathml',
                        'replace' => 'application/mathml+xml',
                    ],
                    [
                        'search' => 'mayf',
                        'replace' => 'application/prs.mayfile',
                    ],
                    [
                        'search' => 'mb',
                        'replace' => 'application/mathematica',
                    ],
                    [
                        'search' => 'mbk',
                        'replace' => 'application/vnd.mobius.mbk',
                    ],
                    [
                        'search' => 'mbox',
                        'replace' => 'application/mbox',
                    ],
                    [
                        'search' => 'mbsdf',
                        'replace' => 'application/vnd.mdl-mbsdf',
                    ],
                    [
                        'search' => 'mc1',
                        'replace' => 'application/vnd.medcalcdata',
                    ],
                    [
                        'search' => 'mc2',
                        'replace' => 'text/vnd.senx.warpscript',
                    ],
                    [
                        'search' => 'mcd',
                        'replace' => 'application/vnd.mcd',
                    ],
                    [
                        'search' => 'mcurl',
                        'replace' => 'text/vnd.curl.mcurl',
                    ],
                    [
                        'search' => 'md',
                        'replace' => 'text/markdown',
                    ],
                    [
                        'search' => 'mdb',
                        'replace' => 'application/x-msaccess',
                    ],
                    [
                        'search' => 'mdc',
                        'replace' => 'application/vnd.marlin.drm.mdcf',
                    ],
                    [
                        'search' => 'mdi',
                        'replace' => 'image/vnd.ms-modi',
                    ],
                    [
                        'search' => 'mdl',
                        'replace' => 'application/vnd.mdl',
                    ],
                    [
                        'search' => 'mdx',
                        'replace' => 'text/mdx',
                    ],
                    [
                        'search' => 'me',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'mermaid',
                        'replace' => 'application/vnd.mermaid',
                    ],
                    [
                        'search' => 'mesh',
                        'replace' => 'model/mesh',
                    ],
                    [
                        'search' => 'meta4',
                        'replace' => 'application/metalink4+xml',
                    ],
                    [
                        'search' => 'metalink',
                        'replace' => 'application/metalink+xml',
                    ],
                    [
                        'search' => 'mets',
                        'replace' => 'application/mets+xml',
                    ],
                    [
                        'search' => 'mf4',
                        'replace' => 'application/mf4',
                    ],
                    [
                        'search' => 'mfm',
                        'replace' => 'application/vnd.mfmp',
                    ],
                    [
                        'search' => 'mft',
                        'replace' => 'application/rpki-manifest',
                    ],
                    [
                        'search' => 'mgp',
                        'replace' => 'application/vnd.osgeo.mapguide.package',
                    ],
                    [
                        'search' => 'mgz',
                        'replace' => 'application/vnd.proteus.magazine',
                    ],
                    [
                        'search' => 'mhas',
                        'replace' => 'audio/mhas',
                    ],
                    [
                        'search' => 'mht',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'mhtml',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'mid',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'midi',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'midi2',
                        'replace' => 'audio/midi-clip',
                    ],
                    [
                        'search' => 'mie',
                        'replace' => 'application/x-mie',
                    ],
                    [
                        'search' => 'mif',
                        'replace' => 'application/vnd.mif',
                    ],
                    [
                        'search' => 'mime',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'miz',
                        'replace' => 'text/mizar',
                    ],
                    [
                        'search' => 'mj2',
                        'replace' => 'video/mj2',
                    ],
                    [
                        'search' => 'mjp2',
                        'replace' => 'video/mj2',
                    ],
                    [
                        'search' => 'mjs',
                        'replace' => 'text/javascript',
                    ],
                    [
                        'search' => 'mk3d',
                        'replace' => 'video/matroska-3d',
                    ],
                    [
                        'search' => 'mka',
                        'replace' => 'audio/matroska',
                    ],
                    [
                        'search' => 'mkd',
                        'replace' => 'text/x-markdown',
                    ],
                    [
                        'search' => 'mks',
                        'replace' => 'video/x-matroska',
                    ],
                    [
                        'search' => 'mkv',
                        'replace' => 'video/matroska',
                    ],
                    [
                        'search' => 'ml2',
                        'replace' => 'application/vnd.sybyl.mol2',
                    ],
                    [
                        'search' => 'mlp',
                        'replace' => 'application/vnd.dolby.mlp',
                    ],
                    [
                        'search' => 'mmd',
                        'replace' => 'application/vnd.chipnuts.karaoke-mmd',
                    ],
                    [
                        'search' => 'mmdb',
                        'replace' => 'application/vnd.maxmind.maxmind-db',
                    ],
                    [
                        'search' => 'mmf',
                        'replace' => 'application/vnd.smaf',
                    ],
                    [
                        'search' => 'mml',
                        'replace' => 'text/mathml',
                    ],
                    [
                        'search' => 'mmr',
                        'replace' => 'image/vnd.fujixerox.edmics-mmr',
                    ],
                    [
                        'search' => 'mng',
                        'replace' => 'video/x-mng',
                    ],
                    [
                        'search' => 'mny',
                        'replace' => 'application/x-msmoney',
                    ],
                    [
                        'search' => 'mobi',
                        'replace' => 'application/x-mobipocket-ebook',
                    ],
                    [
                        'search' => 'mod',
                        'replace' => 'application/xml-dtd',
                    ],
                    [
                        'search' => 'model-inter',
                        'replace' => 'application/vnd.vd-study',
                    ],
                    [
                        'search' => 'modl',
                        'replace' => 'application/vnd.modl',
                    ],
                    [
                        'search' => 'mods',
                        'replace' => 'application/mods+xml',
                    ],
                    [
                        'search' => 'mol2',
                        'replace' => 'application/vnd.sybyl.mol2',
                    ],
                    [
                        'search' => 'mov',
                        'replace' => 'video/quicktime',
                    ],
                    [
                        'search' => 'movie',
                        'replace' => 'video/x-sgi-movie',
                    ],
                    [
                        'search' => 'mp1',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp2',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp21',
                        'replace' => 'application/mp21',
                    ],
                    [
                        'search' => 'mp2a',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp3',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp4',
                        'replace' => 'video/mp4',
                    ],
                    [
                        'search' => 'mp4a',
                        'replace' => 'audio/mp4',
                    ],
                    [
                        'search' => 'mp4s',
                        'replace' => 'application/mp4',
                    ],
                    [
                        'search' => 'mp4v',
                        'replace' => 'video/mp4',
                    ],
                    [
                        'search' => 'mpc',
                        'replace' => 'application/vnd.mophun.certificate',
                    ],
                    [
                        'search' => 'mpd',
                        'replace' => 'application/dash+xml',
                    ],
                    [
                        'search' => 'mpdd',
                        'replace' => 'application/dashdelta',
                    ],
                    [
                        'search' => 'mpe',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'mpeg',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'mpf',
                        'replace' => 'application/media-policy-dataset+xml',
                    ],
                    [
                        'search' => 'mpg',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'mpg4',
                        'replace' => 'video/mp4',
                    ],
                    [
                        'search' => 'mpga',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mpkg',
                        'replace' => 'application/vnd.apple.installer+xml',
                    ],
                    [
                        'search' => 'mpm',
                        'replace' => 'application/vnd.blueice.multipass',
                    ],
                    [
                        'search' => 'mpn',
                        'replace' => 'application/vnd.mophun.application',
                    ],
                    [
                        'search' => 'mpp',
                        'replace' => 'application/vnd.ms-project',
                    ],
                    [
                        'search' => 'mpt',
                        'replace' => 'application/vnd.ms-project',
                    ],
                    [
                        'search' => 'mpw',
                        'replace' => 'application/vnd.exstream-empower+zip',
                    ],
                    [
                        'search' => 'mpy',
                        'replace' => 'application/vnd.ibm.minipay',
                    ],
                    [
                        'search' => 'mqy',
                        'replace' => 'application/vnd.mobius.mqy',
                    ],
                    [
                        'search' => 'mrc',
                        'replace' => 'application/marc',
                    ],
                    [
                        'search' => 'mrcx',
                        'replace' => 'application/marcxml+xml',
                    ],
                    [
                        'search' => 'ms',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'msa',
                        'replace' => 'application/vnd.msa-disk-image',
                    ],
                    [
                        'search' => 'mscml',
                        'replace' => 'application/mediaservercontrol+xml',
                    ],
                    [
                        'search' => 'mseed',
                        'replace' => 'application/vnd.fdsn.mseed',
                    ],
                    [
                        'search' => 'mseed3',
                        'replace' => 'application/vnd.fdsn.mseed',
                    ],
                    [
                        'search' => 'mseq',
                        'replace' => 'application/vnd.mseq',
                    ],
                    [
                        'search' => 'msf',
                        'replace' => 'application/vnd.epson.msf',
                    ],
                    [
                        'search' => 'msg',
                        'replace' => 'application/vnd.ms-outlook',
                    ],
                    [
                        'search' => 'msh',
                        'replace' => 'model/mesh',
                    ],
                    [
                        'search' => 'msi',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'msix',
                        'replace' => 'application/msix',
                    ],
                    [
                        'search' => 'msixbundle',
                        'replace' => 'application/msixbundle',
                    ],
                    [
                        'search' => 'msl',
                        'replace' => 'application/vnd.mobius.msl',
                    ],
                    [
                        'search' => 'msm',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'msp',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'msty',
                        'replace' => 'application/vnd.muvee.style',
                    ],
                    [
                        'search' => 'mtl',
                        'replace' => 'model/mtl',
                    ],
                    [
                        'search' => 'mts',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'multitrack',
                        'replace' => 'audio/vnd.presonus.multitrack',
                    ],
                    [
                        'search' => 'mus',
                        'replace' => 'application/vnd.musician',
                    ],
                    [
                        'search' => 'musd',
                        'replace' => 'application/mmt-usd+xml',
                    ],
                    [
                        'search' => 'musicxml',
                        'replace' => 'application/vnd.recordare.musicxml+xml',
                    ],
                    [
                        'search' => 'mvb',
                        'replace' => 'application/x-msmediaview',
                    ],
                    [
                        'search' => 'mvt',
                        'replace' => 'application/vnd.mapbox-vector-tile',
                    ],
                    [
                        'search' => 'mwf',
                        'replace' => 'application/vnd.mfer',
                    ],
                    [
                        'search' => 'mxf',
                        'replace' => 'application/mxf',
                    ],
                    [
                        'search' => 'mxi',
                        'replace' => 'application/vnd.vd-study',
                    ],
                    [
                        'search' => 'mxl',
                        'replace' => 'application/vnd.recordare.musicxml',
                    ],
                    [
                        'search' => 'mxmf',
                        'replace' => 'audio/mobile-xmf',
                    ],
                    [
                        'search' => 'mxml',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'mxs',
                        'replace' => 'application/vnd.triscape.mxs',
                    ],
                    [
                        'search' => 'mxu',
                        'replace' => 'video/vnd.mpegurl',
                    ],
                    [
                        'search' => 'n-gage',
                        'replace' => 'application/vnd.nokia.n-gage.symbian.install',
                    ],
                    [
                        'search' => 'n3',
                        'replace' => 'text/n3',
                    ],
                    [
                        'search' => 'na',
                        'replace' => 'application/vnd.wfa.dpp',
                    ],
                    [
                        'search' => 'nb',
                        'replace' => 'application/mathematica',
                    ],
                    [
                        'search' => 'nbp',
                        'replace' => 'application/vnd.wolfram.player',
                    ],
                    [
                        'search' => 'nc',
                        'replace' => 'application/x-netcdf',
                    ],
                    [
                        'search' => 'ncx',
                        'replace' => 'application/x-dtbncx+xml',
                    ],
                    [
                        'search' => 'ndc',
                        'replace' => 'application/vnd.osa.netdeploy',
                    ],
                    [
                        'search' => 'nds',
                        'replace' => 'application/vnd.nintendo.nitro.rom',
                    ],
                    [
                        'search' => 'nebul',
                        'replace' => 'application/vnd.nebumind.line',
                    ],
                    [
                        'search' => 'nfo',
                        'replace' => 'text/x-nfo',
                    ],
                    [
                        'search' => 'ngdat',
                        'replace' => 'application/vnd.nokia.n-gage.data',
                    ],
                    [
                        'search' => 'nim',
                        'replace' => 'video/vnd.nokia.interleaved-multimedia',
                    ],
                    [
                        'search' => 'nimn',
                        'replace' => 'application/vnd.nimn',
                    ],
                    [
                        'search' => 'nitf',
                        'replace' => 'application/vnd.nitf',
                    ],
                    [
                        'search' => 'nlu',
                        'replace' => 'application/vnd.neurolanguage.nlu',
                    ],
                    [
                        'search' => 'nml',
                        'replace' => 'application/vnd.enliven',
                    ],
                    [
                        'search' => 'nnd',
                        'replace' => 'application/vnd.noblenet-directory',
                    ],
                    [
                        'search' => 'nns',
                        'replace' => 'application/vnd.noblenet-sealer',
                    ],
                    [
                        'search' => 'nnw',
                        'replace' => 'application/vnd.noblenet-web',
                    ],
                    [
                        'search' => 'not',
                        'replace' => 'application/vnd.sss-dtf',
                    ],
                    [
                        'search' => 'notebook',
                        'replace' => 'application/vnd.smart.notebook',
                    ],
                    [
                        'search' => 'npx',
                        'replace' => 'image/vnd.net-fpx',
                    ],
                    [
                        'search' => 'nq',
                        'replace' => 'application/n-quads',
                    ],
                    [
                        'search' => 'nsc',
                        'replace' => 'application/x-conference',
                    ],
                    [
                        'search' => 'nsf',
                        'replace' => 'application/vnd.lotus-notes',
                    ],
                    [
                        'search' => 'nt',
                        'replace' => 'application/n-triples',
                    ],
                    [
                        'search' => 'ntf',
                        'replace' => 'application/vnd.nitf',
                    ],
                    [
                        'search' => 'numbers',
                        'replace' => 'application/vnd.apple.numbers',
                    ],
                    [
                        'search' => 'nzb',
                        'replace' => 'application/x-nzb',
                    ],
                    [
                        'search' => 'oa2',
                        'replace' => 'application/vnd.fujitsu.oasys2',
                    ],
                    [
                        'search' => 'oa3',
                        'replace' => 'application/vnd.fujitsu.oasys3',
                    ],
                    [
                        'search' => 'oas',
                        'replace' => 'application/vnd.fujitsu.oasys',
                    ],
                    [
                        'search' => 'ob',
                        'replace' => 'application/vnd.1ob',
                    ],
                    [
                        'search' => 'obd',
                        'replace' => 'application/x-msbinder',
                    ],
                    [
                        'search' => 'obg',
                        'replace' => 'application/vnd.openblox.game-binary',
                    ],
                    [
                        'search' => 'obgx',
                        'replace' => 'application/vnd.openblox.game+xml',
                    ],
                    [
                        'search' => 'obj',
                        'replace' => 'model/obj',
                    ],
                    [
                        'search' => 'ocf',
                        'replace' => 'application/epub+zip',
                    ],
                    [
                        'search' => 'oda',
                        'replace' => 'application/oda',
                    ],
                    [
                        'search' => 'odb',
                        'replace' => 'application/vnd.oasis.opendocument.database',
                    ],
                    [
                        'search' => 'odc',
                        'replace' => 'application/vnd.oasis.opendocument.chart',
                    ],
                    [
                        'search' => 'odf',
                        'replace' => 'application/vnd.oasis.opendocument.formula',
                    ],
                    [
                        'search' => 'odft',
                        'replace' => 'application/vnd.oasis.opendocument.formula-template',
                    ],
                    [
                        'search' => 'odg',
                        'replace' => 'application/vnd.oasis.opendocument.graphics',
                    ],
                    [
                        'search' => 'odi',
                        'replace' => 'application/vnd.oasis.opendocument.image',
                    ],
                    [
                        'search' => 'odm',
                        'replace' => 'application/vnd.oasis.opendocument.text-master',
                    ],
                    [
                        'search' => 'odp',
                        'replace' => 'application/vnd.oasis.opendocument.presentation',
                    ],
                    [
                        'search' => 'ods',
                        'replace' => 'application/vnd.oasis.opendocument.spreadsheet',
                    ],
                    [
                        'search' => 'odt',
                        'replace' => 'application/vnd.oasis.opendocument.text',
                    ],
                    [
                        'search' => 'odx',
                        'replace' => 'application/odx',
                    ],
                    [
                        'search' => 'oeb',
                        'replace' => 'application/vnd.openeye.oeb',
                    ],
                    [
                        'search' => 'often',
                        'replace' => 'application/vnd.netfpx',
                    ],
                    [
                        'search' => 'oga',
                        'replace' => 'audio/ogg',
                    ],
                    [
                        'search' => 'ogex',
                        'replace' => 'model/vnd.opengex',
                    ],
                    [
                        'search' => 'ogg',
                        'replace' => 'audio/ogg',
                    ],
                    [
                        'search' => 'ogv',
                        'replace' => 'video/ogg',
                    ],
                    [
                        'search' => 'ogx',
                        'replace' => 'application/ogg',
                    ],
                    [
                        'search' => 'omdoc',
                        'replace' => 'application/omdoc+xml',
                    ],
                    [
                        'search' => 'omg',
                        'replace' => 'audio/atrac3',
                    ],
                    [
                        'search' => 'one',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onea',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onepkg',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onetmp',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onetoc',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onetoc2',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'opf',
                        'replace' => 'application/oebps-package+xml',
                    ],
                    [
                        'search' => 'opml',
                        'replace' => 'text/x-opml',
                    ],
                    [
                        'search' => 'oprc',
                        'replace' => 'application/vnd.palm',
                    ],
                    [
                        'search' => 'opus',
                        'replace' => 'audio/opus',
                    ],
                    [
                        'search' => 'or3',
                        'replace' => 'application/vnd.lotus-organizer',
                    ],
                    [
                        'search' => 'org',
                        'replace' => 'application/vnd.lotus-organizer',
                    ],
                    [
                        'search' => 'orq',
                        'replace' => 'application/ocsp-request',
                    ],
                    [
                        'search' => 'ors',
                        'replace' => 'application/ocsp-response',
                    ],
                    [
                        'search' => 'osf',
                        'replace' => 'application/vnd.yamaha.openscoreformat',
                    ],
                    [
                        'search' => 'osfpvg',
                        'replace' => 'application/vnd.yamaha.openscoreformat.osfpvg+xml',
                    ],
                    [
                        'search' => 'osm',
                        'replace' => 'application/vnd.openstreetmap.data+xml',
                    ],
                    [
                        'search' => 'ota',
                        'replace' => 'application/vnd.android.ota',
                    ],
                    [
                        'search' => 'otc',
                        'replace' => 'application/vnd.oasis.opendocument.chart-template',
                    ],
                    [
                        'search' => 'otf',
                        'replace' => 'font/otf',
                    ],
                    [
                        'search' => 'otg',
                        'replace' => 'application/vnd.oasis.opendocument.graphics-template',
                    ],
                    [
                        'search' => 'oth',
                        'replace' => 'application/vnd.oasis.opendocument.text-web',
                    ],
                    [
                        'search' => 'oti',
                        'replace' => 'application/vnd.oasis.opendocument.image-template',
                    ],
                    [
                        'search' => 'otm',
                        'replace' => 'application/vnd.oasis.opendocument.text-master-template',
                    ],
                    [
                        'search' => 'otp',
                        'replace' => 'application/vnd.oasis.opendocument.presentation-template',
                    ],
                    [
                        'search' => 'ots',
                        'replace' => 'application/vnd.oasis.opendocument.spreadsheet-template',
                    ],
                    [
                        'search' => 'ott',
                        'replace' => 'application/vnd.oasis.opendocument.text-template',
                    ],
                    [
                        'search' => 'ova',
                        'replace' => 'application/x-virtualbox-ova',
                    ],
                    [
                        'search' => 'ovf',
                        'replace' => 'application/x-virtualbox-ovf',
                    ],
                    [
                        'search' => 'ovl',
                        'replace' => 'application/vnd.afpc.modca-overlay',
                    ],
                    [
                        'search' => 'owl',
                        'replace' => 'application/rdf+xml',
                    ],
                    [
                        'search' => 'oxlicg',
                        'replace' => 'application/vnd.oxli.countgraph',
                    ],
                    [
                        'search' => 'oxps',
                        'replace' => 'application/oxps',
                    ],
                    [
                        'search' => 'oxt',
                        'replace' => 'application/vnd.openofficeorg.extension',
                    ],
                    [
                        'search' => 'p',
                        'replace' => 'text/x-pascal',
                    ],
                    [
                        'search' => 'p10',
                        'replace' => 'application/pkcs10',
                    ],
                    [
                        'search' => 'p12',
                        'replace' => 'application/x-pkcs12',
                    ],
                    [
                        'search' => 'p21',
                        'replace' => 'application/p21',
                    ],
                    [
                        'search' => 'p2p',
                        'replace' => 'application/vnd.wfa.p2p',
                    ],
                    [
                        'search' => 'p7b',
                        'replace' => 'application/x-pkcs7-certificates',
                    ],
                    [
                        'search' => 'p7c',
                        'replace' => 'application/pkcs7-mime',
                    ],
                    [
                        'search' => 'p7m',
                        'replace' => 'application/pkcs7-mime',
                    ],
                    [
                        'search' => 'p7r',
                        'replace' => 'application/x-pkcs7-certreqresp',
                    ],
                    [
                        'search' => 'p7s',
                        'replace' => 'application/pkcs7-signature',
                    ],
                    [
                        'search' => 'p8',
                        'replace' => 'application/pkcs8',
                    ],
                    [
                        'search' => 'p8e',
                        'replace' => 'application/pkcs8-encrypted',
                    ],
                    [
                        'search' => 'pac',
                        'replace' => 'application/x-ns-proxy-autoconfig',
                    ],
                    [
                        'search' => 'package',
                        'replace' => 'application/vnd.autopackage',
                    ],
                    [
                        'search' => 'pages',
                        'replace' => 'application/vnd.apple.pages',
                    ],
                    [
                        'search' => 'parquet',
                        'replace' => 'application/vnd.apache.parquet',
                    ],
                    [
                        'search' => 'pas',
                        'replace' => 'text/x-pascal',
                    ],
                    [
                        'search' => 'paw',
                        'replace' => 'application/vnd.pawaafile',
                    ],
                    [
                        'search' => 'pbd',
                        'replace' => 'application/vnd.powerbuilder6',
                    ],
                    [
                        'search' => 'pbm',
                        'replace' => 'image/x-portable-bitmap',
                    ],
                    [
                        'search' => 'pcap',
                        'replace' => 'application/vnd.tcpdump.pcap',
                    ],
                    [
                        'search' => 'pcf',
                        'replace' => 'application/x-font-pcf',
                    ],
                    [
                        'search' => 'pcl',
                        'replace' => 'application/vnd.hp-pcl',
                    ],
                    [
                        'search' => 'pclxl',
                        'replace' => 'application/vnd.hp-pclxl',
                    ],
                    [
                        'search' => 'pct',
                        'replace' => 'image/x-pict',
                    ],
                    [
                        'search' => 'pcurl',
                        'replace' => 'application/vnd.curl.pcurl',
                    ],
                    [
                        'search' => 'pcx',
                        'replace' => 'image/x-pcx',
                    ],
                    [
                        'search' => 'pdb',
                        'replace' => 'application/vnd.palm',
                    ],
                    [
                        'search' => 'pde',
                        'replace' => 'text/x-processing',
                    ],
                    [
                        'search' => 'pdf',
                        'replace' => 'application/pdf',
                    ],
                    [
                        'search' => 'pdx',
                        'replace' => 'application/pdx',
                    ],
                    [
                        'search' => 'pem',
                        'replace' => 'application/x-x509-ca-cert',
                    ],
                    [
                        'search' => 'pfa',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'pfb',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'pfm',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'pfr',
                        'replace' => 'application/font-tdpfr',
                    ],
                    [
                        'search' => 'pfx',
                        'replace' => 'application/x-pkcs12',
                    ],
                    [
                        'search' => 'pgb',
                        'replace' => 'image/vnd.globalgraphics.pgb',
                    ],
                    [
                        'search' => 'pgm',
                        'replace' => 'image/x-portable-graymap',
                    ],
                    [
                        'search' => 'pgn',
                        'replace' => 'application/x-chess-pgn',
                    ],
                    [
                        'search' => 'pgp',
                        'replace' => 'application/pgp-encrypted',
                    ],
                    [
                        'search' => 'php',
                        'replace' => 'application/x-httpd-php',
                    ],
                    [
                        'search' => 'pic',
                        'replace' => 'image/x-pict',
                    ],
                    [
                        'search' => 'pil',
                        'replace' => 'application/vnd.piaccess.application-licence',
                    ],
                    [
                        'search' => 'pkd',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'pkg',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'pki',
                        'replace' => 'application/pkixcmp',
                    ],
                    [
                        'search' => 'pkipath',
                        'replace' => 'application/pkix-pkipath',
                    ],
                    [
                        'search' => 'pkpass',
                        'replace' => 'application/vnd.apple.pkpass',
                    ],
                    [
                        'search' => 'pl',
                        'replace' => 'application/x-perl',
                    ],
                    [
                        'search' => 'plb',
                        'replace' => 'application/vnd.3gpp.pic-bw-large',
                    ],
                    [
                        'search' => 'plc',
                        'replace' => 'application/vnd.mobius.plc',
                    ],
                    [
                        'search' => 'plf',
                        'replace' => 'application/vnd.pocketlearn',
                    ],
                    [
                        'search' => 'plj',
                        'replace' => 'audio/vnd.everad.plj',
                    ],
                    [
                        'search' => 'plp',
                        'replace' => 'application/vnd.panoply',
                    ],
                    [
                        'search' => 'pls',
                        'replace' => 'application/pls+xml',
                    ],
                    [
                        'search' => 'pm',
                        'replace' => 'application/x-perl',
                    ],
                    [
                        'search' => 'pml',
                        'replace' => 'application/vnd.ctc-posml',
                    ],
                    [
                        'search' => 'pmtiles',
                        'replace' => 'application/vnd.pmtiles',
                    ],
                    [
                        'search' => 'png',
                        'replace' => 'image/png',
                    ],
                    [
                        'search' => 'pnm',
                        'replace' => 'image/x-portable-anymap',
                    ],
                    [
                        'search' => 'portpkg',
                        'replace' => 'application/vnd.macports.portpkg',
                    ],
                    [
                        'search' => 'pot',
                        'replace' => 'application/vnd.ms-powerpoint',
                    ],
                    [
                        'search' => 'potm',
                        'replace' => 'application/vnd.ms-powerpoint.template.macroenabled.12',
                    ],
                    [
                        'search' => 'potx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
                    ],
                    [
                        'search' => 'ppam',
                        'replace' => 'application/vnd.ms-powerpoint.addin.macroenabled.12',
                    ],
                    [
                        'search' => 'ppd',
                        'replace' => 'application/vnd.cups-ppd',
                    ],
                    [
                        'search' => 'ppd.gz',
                        'replace' => 'application/vnd.cups-ppd',
                    ],
                    [
                        'search' => 'ppkg',
                        'replace' => 'application/vnd.xmpie.ppkg',
                    ],
                    [
                        'search' => 'ppm',
                        'replace' => 'image/x-portable-pixmap',
                    ],
                    [
                        'search' => 'pps',
                        'replace' => 'application/vnd.ms-powerpoint',
                    ],
                    [
                        'search' => 'ppsm',
                        'replace' => 'application/vnd.ms-powerpoint.slideshow.macroenabled.12',
                    ],
                    [
                        'search' => 'ppsx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
                    ],
                    [
                        'search' => 'ppt',
                        'replace' => 'application/vnd.ms-powerpoint',
                    ],
                    [
                        'search' => 'pptm',
                        'replace' => 'application/vnd.ms-powerpoint.presentation.macroenabled.12',
                    ],
                    [
                        'search' => 'ppttc',
                        'replace' => 'application/vnd.think-cell.ppttc+json',
                    ],
                    [
                        'search' => 'pptx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    ],
                    [
                        'search' => 'pqa',
                        'replace' => 'application/vnd.palm',
                    ],
                    [
                        'search' => 'prc',
                        'replace' => 'application/x-mobipocket-ebook',
                    ],
                    [
                        'search' => 'pre',
                        'replace' => 'application/vnd.lotus-freelance',
                    ],
                    [
                        'search' => 'preminet',
                        'replace' => 'application/vnd.preminet',
                    ],
                    [
                        'search' => 'prf',
                        'replace' => 'application/pics-rules',
                    ],
                    [
                        'search' => 'provn',
                        'replace' => 'text/provenance-notation',
                    ],
                    [
                        'search' => 'provx',
                        'replace' => 'application/provenance+xml',
                    ],
                    [
                        'search' => 'prz',
                        'replace' => 'application/vnd.lotus-freelance',
                    ],
                    [
                        'search' => 'ps',
                        'replace' => 'application/postscript',
                    ],
                    [
                        'search' => 'psb',
                        'replace' => 'application/vnd.3gpp.pic-bw-small',
                    ],
                    [
                        'search' => 'psd',
                        'replace' => 'image/vnd.adobe.photoshop',
                    ],
                    [
                        'search' => 'pseg3820',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'psf',
                        'replace' => 'application/x-font-linux-psf',
                    ],
                    [
                        'search' => 'psfs',
                        'replace' => 'application/vnd.psfs',
                    ],
                    [
                        'search' => 'psg',
                        'replace' => 'application/vnd.afpc.modca-pagesegment',
                    ],
                    [
                        'search' => 'psid',
                        'replace' => 'audio/prs.sid',
                    ],
                    [
                        'search' => 'pskcxml',
                        'replace' => 'application/pskc+xml',
                    ],
                    [
                        'search' => 'pt',
                        'replace' => 'application/vnd.snesdev-page-table',
                    ],
                    [
                        'search' => 'pti',
                        'replace' => 'application/vnd.pvi.ptid1',
                    ],
                    [
                        'search' => 'ptid',
                        'replace' => 'application/vnd.pvi.ptid1',
                    ],
                    [
                        'search' => 'ptrom',
                        'replace' => 'application/vnd.snesdev-page-table',
                    ],
                    [
                        'search' => 'pub',
                        'replace' => 'application/x-mspublisher',
                    ],
                    [
                        'search' => 'pvb',
                        'replace' => 'application/vnd.3gpp.pic-bw-var',
                    ],
                    [
                        'search' => 'pwn',
                        'replace' => 'application/vnd.3m.post-it-notes',
                    ],
                    [
                        'search' => 'pya',
                        'replace' => 'audio/vnd.ms-playready.media.pya',
                    ],
                    [
                        'search' => 'pyo',
                        'replace' => 'model/vnd.pytha.pyox',
                    ],
                    [
                        'search' => 'pyon',
                        'replace' => 'application/vnd.pyon+json',
                    ],
                    [
                        'search' => 'pyox',
                        'replace' => 'model/vnd.pytha.pyox',
                    ],
                    [
                        'search' => 'pyv',
                        'replace' => 'video/vnd.ms-playready.media.pyv',
                    ],
                    [
                        'search' => 'qam',
                        'replace' => 'application/vnd.epson.quickanime',
                    ],
                    [
                        'search' => 'qbo',
                        'replace' => 'application/vnd.intu.qbo',
                    ],
                    [
                        'search' => 'qca',
                        'replace' => 'application/vnd.ericsson.quickcall',
                    ],
                    [
                        'search' => 'qcall',
                        'replace' => 'application/vnd.ericsson.quickcall',
                    ],
                    [
                        'search' => 'qcp',
                        'replace' => 'audio/evrc-qcp',
                    ],
                    [
                        'search' => 'qfx',
                        'replace' => 'application/vnd.intu.qfx',
                    ],
                    [
                        'search' => 'qps',
                        'replace' => 'application/vnd.publishare-delta-tree',
                    ],
                    [
                        'search' => 'qt',
                        'replace' => 'video/quicktime',
                    ],
                    [
                        'search' => 'quiz',
                        'replace' => 'application/vnd.quobject-quoxdocument',
                    ],
                    [
                        'search' => 'quox',
                        'replace' => 'application/vnd.quobject-quoxdocument',
                    ],
                    [
                        'search' => 'qwd',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qwt',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxb',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxd',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxl',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxt',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'ra',
                        'replace' => 'audio/x-pn-realaudio',
                    ],
                    [
                        'search' => 'ram',
                        'replace' => 'audio/x-pn-realaudio',
                    ],
                    [
                        'search' => 'raml',
                        'replace' => 'application/raml+yaml',
                    ],
                    [
                        'search' => 'rapd',
                        'replace' => 'application/route-apd+xml',
                    ],
                    [
                        'search' => 'rar',
                        'replace' => 'application/x-rar-compressed',
                    ],
                    [
                        'search' => 'ras',
                        'replace' => 'image/x-cmu-raster',
                    ],
                    [
                        'search' => 'rcprofile',
                        'replace' => 'application/vnd.ipunplugged.rcprofile',
                    ],
                    [
                        'search' => 'rct',
                        'replace' => 'application/prs.nprend',
                    ],
                    [
                        'search' => 'rdf',
                        'replace' => 'application/rdf+xml',
                    ],
                    [
                        'search' => 'rdf-crypt',
                        'replace' => 'application/prs.rdf-xml-crypt',
                    ],
                    [
                        'search' => 'rdz',
                        'replace' => 'application/vnd.data-vision.rdz',
                    ],
                    [
                        'search' => 'relo',
                        'replace' => 'application/p2p-overlay+xml',
                    ],
                    [
                        'search' => 'reload',
                        'replace' => 'application/vnd.resilient.logic',
                    ],
                    [
                        'search' => 'rep',
                        'replace' => 'application/vnd.businessobjects',
                    ],
                    [
                        'search' => 'req',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'request',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'res',
                        'replace' => 'application/x-dtbresource+xml',
                    ],
                    [
                        'search' => 'rfc',
                        'replace' => 'application/dns+json',
                    ],
                    [
                        'search' => 'rfcxml',
                        'replace' => 'application/rfc+xml',
                    ],
                    [
                        'search' => 'rgb',
                        'replace' => 'image/x-rgb',
                    ],
                    [
                        'search' => 'rgbe',
                        'replace' => 'image/vnd.radiance',
                    ],
                    [
                        'search' => 'rif',
                        'replace' => 'application/reginfo+xml',
                    ],
                    [
                        'search' => 'rip',
                        'replace' => 'audio/vnd.rip',
                    ],
                    [
                        'search' => 'ris',
                        'replace' => 'application/x-research-info-systems',
                    ],
                    [
                        'search' => 'rl',
                        'replace' => 'application/resource-lists+xml',
                    ],
                    [
                        'search' => 'rlc',
                        'replace' => 'image/vnd.fujixerox.edmics-rlc',
                    ],
                    [
                        'search' => 'rld',
                        'replace' => 'application/resource-lists-diff+xml',
                    ],
                    [
                        'search' => 'rlm',
                        'replace' => 'application/vnd.resilient.logic',
                    ],
                    [
                        'search' => 'rm',
                        'replace' => 'application/vnd.rn-realmedia',
                    ],
                    [
                        'search' => 'rmi',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'rmp',
                        'replace' => 'audio/x-pn-realaudio-plugin',
                    ],
                    [
                        'search' => 'rms',
                        'replace' => 'application/vnd.jcp.javame.midlet-rms',
                    ],
                    [
                        'search' => 'rmvb',
                        'replace' => 'application/vnd.rn-realmedia-vbr',
                    ],
                    [
                        'search' => 'rnc',
                        'replace' => 'application/relax-ng-compact-syntax',
                    ],
                    [
                        'search' => 'rnd',
                        'replace' => 'application/prs.nprend',
                    ],
                    [
                        'search' => 'rng',
                        'replace' => 'application/xml',
                    ],
                    [
                        'search' => 'roa',
                        'replace' => 'application/rpki-roa',
                    ],
                    [
                        'search' => 'roff',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'rp',
                        'replace' => 'application/vnd.relpipe',
                    ],
                    [
                        'search' => 'rp9',
                        'replace' => 'application/vnd.cloanto.rp9',
                    ],
                    [
                        'search' => 'rpm',
                        'replace' => 'application/x-redhat-package-manager',
                    ],
                    [
                        'search' => 'rpss',
                        'replace' => 'application/vnd.nokia.radio-presets',
                    ],
                    [
                        'search' => 'rpst',
                        'replace' => 'application/vnd.nokia.radio-preset',
                    ],
                    [
                        'search' => 'rq',
                        'replace' => 'application/sparql-query',
                    ],
                    [
                        'search' => 'rs',
                        'replace' => 'application/rls-services+xml',
                    ],
                    [
                        'search' => 'rsat',
                        'replace' => 'application/atsc-rsat+xml',
                    ],
                    [
                        'search' => 'rsd',
                        'replace' => 'application/rsd+xml',
                    ],
                    [
                        'search' => 'rsheet',
                        'replace' => 'application/urc-ressheet+xml',
                    ],
                    [
                        'search' => 'rsm',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'rss',
                        'replace' => 'application/rss+xml',
                    ],
                    [
                        'search' => 'rst',
                        'replace' => 'text/prs.fallenstein.rst',
                    ],
                    [
                        'search' => 'rtf',
                        'replace' => 'application/rtf',
                    ],
                    [
                        'search' => 'rtx',
                        'replace' => 'text/richtext',
                    ],
                    [
                        'search' => 'run',
                        'replace' => 'application/x-makeself',
                    ],
                    [
                        'search' => 'rusd',
                        'replace' => 'application/route-usd+xml',
                    ],
                    [
                        'search' => 'rxr',
                        'replace' => 'application/vnd.medicalholodeck.recordxr',
                    ],
                    [
                        'search' => 's',
                        'replace' => 'text/x-asm',
                    ],
                    [
                        'search' => 's11',
                        'replace' => 'video/vnd.sealed.mpeg1',
                    ],
                    [
                        'search' => 's14',
                        'replace' => 'video/vnd.sealed.mpeg4',
                    ],
                    [
                        'search' => 's1a',
                        'replace' => 'application/vnd.sealedmedia.softseal.pdf',
                    ],
                    [
                        'search' => 's1e',
                        'replace' => 'application/vnd.sealed.xls',
                    ],
                    [
                        'search' => 's1g',
                        'replace' => 'image/vnd.sealedmedia.softseal.gif',
                    ],
                    [
                        'search' => 's1h',
                        'replace' => 'application/vnd.sealedmedia.softseal.html',
                    ],
                    [
                        'search' => 's1j',
                        'replace' => 'image/vnd.sealedmedia.softseal.jpg',
                    ],
                    [
                        'search' => 's1m',
                        'replace' => 'audio/vnd.sealedmedia.softseal.mpeg',
                    ],
                    [
                        'search' => 's1n',
                        'replace' => 'image/vnd.sealed.png',
                    ],
                    [
                        'search' => 's1p',
                        'replace' => 'application/vnd.sealed.ppt',
                    ],
                    [
                        'search' => 's1q',
                        'replace' => 'video/vnd.sealedmedia.softseal.mov',
                    ],
                    [
                        'search' => 's1w',
                        'replace' => 'application/vnd.sealed.doc',
                    ],
                    [
                        'search' => 's3df',
                        'replace' => 'application/vnd.sealed.3df',
                    ],
                    [
                        'search' => 's3m',
                        'replace' => 'audio/s3m',
                    ],
                    [
                        'search' => 'sac',
                        'replace' => 'application/tamp-sequence-adjust-confirm',
                    ],
                    [
                        'search' => 'saf',
                        'replace' => 'application/vnd.yamaha.smaf-audio',
                    ],
                    [
                        'search' => 'sar',
                        'replace' => 'application/vnd.sar',
                    ],
                    [
                        'search' => 'sarif',
                        'replace' => 'application/sarif+json',
                    ],
                    [
                        'search' => 'sarif-external-properties',
                        'replace' => 'application/sarif-external-properties+json',
                    ],
                    [
                        'search' => 'sarif-external-properties.json',
                        'replace' => 'application/sarif-external-properties+json',
                    ],
                    [
                        'search' => 'sarif.json',
                        'replace' => 'application/sarif+json',
                    ],
                    [
                        'search' => 'sass',
                        'replace' => 'text/x-sass',
                    ],
                    [
                        'search' => 'sbml',
                        'replace' => 'application/sbml+xml',
                    ],
                    [
                        'search' => 'sbxls',
                        'replace' => 'application/vnd.r74n.sandboxels+json',
                    ],
                    [
                        'search' => 'sc',
                        'replace' => 'application/vnd.ibm.secure-container',
                    ],
                    [
                        'search' => 'scd',
                        'replace' => 'application/x-msschedule',
                    ],
                    [
                        'search' => 'scd.gz',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'sce',
                        'replace' => 'application/vnd.etsi.asic-e+zip',
                    ],
                    [
                        'search' => 'scim',
                        'replace' => 'application/scim+json',
                    ],
                    [
                        'search' => 'scl',
                        'replace' => 'application/vnd.sycle+xml',
                    ],
                    [
                        'search' => 'scld',
                        'replace' => 'application/vnd.doremir.scorecloud-binary-document',
                    ],
                    [
                        'search' => 'scm',
                        'replace' => 'application/vnd.lotus-screencam',
                    ],
                    [
                        'search' => 'scq',
                        'replace' => 'application/scvp-cv-request',
                    ],
                    [
                        'search' => 'scs',
                        'replace' => 'application/scvp-cv-response',
                    ],
                    [
                        'search' => 'scsf',
                        'replace' => 'application/vnd.sealed.csf',
                    ],
                    [
                        'search' => 'scss',
                        'replace' => 'text/x-scss',
                    ],
                    [
                        'search' => 'scurl',
                        'replace' => 'text/vnd.curl.scurl',
                    ],
                    [
                        'search' => 'sda',
                        'replace' => 'application/vnd.stardivision.draw',
                    ],
                    [
                        'search' => 'sdc',
                        'replace' => 'application/vnd.stardivision.calc',
                    ],
                    [
                        'search' => 'sdd',
                        'replace' => 'application/vnd.stardivision.impress',
                    ],
                    [
                        'search' => 'sdf.json',
                        'replace' => 'application/sdf+json',
                    ],
                    [
                        'search' => 'sdkd',
                        'replace' => 'application/vnd.solent.sdkm+xml',
                    ],
                    [
                        'search' => 'sdkm',
                        'replace' => 'application/vnd.solent.sdkm+xml',
                    ],
                    [
                        'search' => 'sdo',
                        'replace' => 'application/vnd.sealed.doc',
                    ],
                    [
                        'search' => 'sdoc',
                        'replace' => 'application/vnd.sealed.doc',
                    ],
                    [
                        'search' => 'sdp',
                        'replace' => 'application/sdp',
                    ],
                    [
                        'search' => 'sdw',
                        'replace' => 'application/vnd.stardivision.writer',
                    ],
                    [
                        'search' => 'sea',
                        'replace' => 'application/x-sea',
                    ],
                    [
                        'search' => 'see',
                        'replace' => 'application/vnd.seemail',
                    ],
                    [
                        'search' => 'seed',
                        'replace' => 'application/vnd.fdsn.seed',
                    ],
                    [
                        'search' => 'sem',
                        'replace' => 'application/vnd.sealed.eml',
                    ],
                    [
                        'search' => 'sema',
                        'replace' => 'application/vnd.sema',
                    ],
                    [
                        'search' => 'semd',
                        'replace' => 'application/vnd.semd',
                    ],
                    [
                        'search' => 'semf',
                        'replace' => 'application/vnd.semf',
                    ],
                    [
                        'search' => 'seml',
                        'replace' => 'application/vnd.sealed.eml',
                    ],
                    [
                        'search' => 'senml',
                        'replace' => 'application/senml+json',
                    ],
                    [
                        'search' => 'senml-etchc',
                        'replace' => 'application/senml-etch+cbor',
                    ],
                    [
                        'search' => 'senml-etchj',
                        'replace' => 'application/senml-etch+json',
                    ],
                    [
                        'search' => 'senmlc',
                        'replace' => 'application/senml+cbor',
                    ],
                    [
                        'search' => 'senmle',
                        'replace' => 'application/senml-exi',
                    ],
                    [
                        'search' => 'senmlx',
                        'replace' => 'application/senml+xml',
                    ],
                    [
                        'search' => 'sensml',
                        'replace' => 'application/sensml+json',
                    ],
                    [
                        'search' => 'sensmlc',
                        'replace' => 'application/sensml+cbor',
                    ],
                    [
                        'search' => 'sensmle',
                        'replace' => 'application/sensml-exi',
                    ],
                    [
                        'search' => 'sensmlx',
                        'replace' => 'application/sensml+xml',
                    ],
                    [
                        'search' => 'ser',
                        'replace' => 'application/java-serialized-object',
                    ],
                    [
                        'search' => 'setpay',
                        'replace' => 'application/set-payment-initiation',
                    ],
                    [
                        'search' => 'setreg',
                        'replace' => 'application/set-registration-initiation',
                    ],
                    [
                        'search' => 'sfc',
                        'replace' => 'application/vnd.nintendo.snes.rom',
                    ],
                    [
                        'search' => 'sfd',
                        'replace' => 'application/vnd.font-fontforge-sfd',
                    ],
                    [
                        'search' => 'sfd-hdstx',
                        'replace' => 'application/vnd.hydrostatix.sof-data',
                    ],
                    [
                        'search' => 'sfs',
                        'replace' => 'application/vnd.spotfire.sfs',
                    ],
                    [
                        'search' => 'sfv',
                        'replace' => 'text/x-sfv',
                    ],
                    [
                        'search' => 'sgi',
                        'replace' => 'image/sgi',
                    ],
                    [
                        'search' => 'sgif',
                        'replace' => 'image/vnd.sealedmedia.softseal.gif',
                    ],
                    [
                        'search' => 'sgl',
                        'replace' => 'application/vnd.stardivision.writer-global',
                    ],
                    [
                        'search' => 'sgm',
                        'replace' => 'text/sgml',
                    ],
                    [
                        'search' => 'sgml',
                        'replace' => 'text/sgml',
                    ],
                    [
                        'search' => 'sh',
                        'replace' => 'application/x-sh',
                    ],
                    [
                        'search' => 'shaclc',
                        'replace' => 'text/shaclc',
                    ],
                    [
                        'search' => 'shar',
                        'replace' => 'application/x-shar',
                    ],
                    [
                        'search' => 'shc',
                        'replace' => 'text/shaclc',
                    ],
                    [
                        'search' => 'shex',
                        'replace' => 'text/shex',
                    ],
                    [
                        'search' => 'shf',
                        'replace' => 'application/shf+xml',
                    ],
                    [
                        'search' => 'shp',
                        'replace' => 'application/vnd.shp',
                    ],
                    [
                        'search' => 'shtml',
                        'replace' => 'text/html',
                    ],
                    [
                        'search' => 'shx',
                        'replace' => 'application/vnd.shx',
                    ],
                    [
                        'search' => 'si',
                        'replace' => 'text/vnd.wap.si',
                    ],
                    [
                        'search' => 'sic',
                        'replace' => 'application/vnd.wap.sic',
                    ],
                    [
                        'search' => 'sid',
                        'replace' => 'audio/prs.sid',
                    ],
                    [
                        'search' => 'sieve',
                        'replace' => 'application/sieve',
                    ],
                    [
                        'search' => 'sig',
                        'replace' => 'application/pgp-signature',
                    ],
                    [
                        'search' => 'sil',
                        'replace' => 'audio/silk',
                    ],
                    [
                        'search' => 'silo',
                        'replace' => 'model/mesh',
                    ],
                    [
                        'search' => 'sipa',
                        'replace' => 'application/vnd.smintio.portals.archive',
                    ],
                    [
                        'search' => 'sis',
                        'replace' => 'application/vnd.symbian.install',
                    ],
                    [
                        'search' => 'sisx',
                        'replace' => 'application/vnd.symbian.install',
                    ],
                    [
                        'search' => 'sit',
                        'replace' => 'application/x-stuffit',
                    ],
                    [
                        'search' => 'sitx',
                        'replace' => 'application/x-stuffitx',
                    ],
                    [
                        'search' => 'siv',
                        'replace' => 'application/sieve',
                    ],
                    [
                        'search' => 'sjp',
                        'replace' => 'image/vnd.sealedmedia.softseal.jpg',
                    ],
                    [
                        'search' => 'sjpg',
                        'replace' => 'image/vnd.sealedmedia.softseal.jpg',
                    ],
                    [
                        'search' => 'skd',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'sketch',
                        'replace' => 'application/vnd.sketchometry',
                    ],
                    [
                        'search' => 'sketcho',
                        'replace' => 'application/vnd.sketchometry',
                    ],
                    [
                        'search' => 'skm',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'skp',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'skt',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'sl',
                        'replace' => 'text/vnd.wap.sl',
                    ],
                    [
                        'search' => 'sla',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'sla.gz',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'slaz',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'slc',
                        'replace' => 'application/vnd.wap.slc',
                    ],
                    [
                        'search' => 'sldm',
                        'replace' => 'application/vnd.ms-powerpoint.slide.macroenabled.12',
                    ],
                    [
                        'search' => 'sldx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
                    ],
                    [
                        'search' => 'slim',
                        'replace' => 'text/slim',
                    ],
                    [
                        'search' => 'slm',
                        'replace' => 'text/slim',
                    ],
                    [
                        'search' => 'sls',
                        'replace' => 'application/route-s-tsid+xml',
                    ],
                    [
                        'search' => 'slt',
                        'replace' => 'application/vnd.epson.salt',
                    ],
                    [
                        'search' => 'sm',
                        'replace' => 'application/vnd.stepmania.stepchart',
                    ],
                    [
                        'search' => 'smc',
                        'replace' => 'application/vnd.nintendo.snes.rom',
                    ],
                    [
                        'search' => 'smf',
                        'replace' => 'application/vnd.stardivision.math',
                    ],
                    [
                        'search' => 'smh',
                        'replace' => 'application/vnd.sealed.mht',
                    ],
                    [
                        'search' => 'smht',
                        'replace' => 'application/vnd.sealed.mht',
                    ],
                    [
                        'search' => 'smi',
                        'replace' => 'application/smil+xml',
                    ],
                    [
                        'search' => 'smil',
                        'replace' => 'application/smil+xml',
                    ],
                    [
                        'search' => 'smk',
                        'replace' => 'video/vnd.radgamettools.smacker',
                    ],
                    [
                        'search' => 'sml',
                        'replace' => 'application/smil',
                    ],
                    [
                        'search' => 'smo',
                        'replace' => 'video/vnd.sealedmedia.softseal.mov',
                    ],
                    [
                        'search' => 'smov',
                        'replace' => 'video/vnd.sealedmedia.softseal.mov',
                    ],
                    [
                        'search' => 'smp',
                        'replace' => 'audio/vnd.sealedmedia.softseal.mpeg',
                    ],
                    [
                        'search' => 'smp3',
                        'replace' => 'audio/vnd.sealedmedia.softseal.mpeg',
                    ],
                    [
                        'search' => 'smpg',
                        'replace' => 'video/vnd.sealed.mpeg4',
                    ],
                    [
                        'search' => 'sms',
                        'replace' => 'application/vnd.3gpp.sms',
                    ],
                    [
                        'search' => 'smv',
                        'replace' => 'video/x-smv',
                    ],
                    [
                        'search' => 'smzip',
                        'replace' => 'application/vnd.stepmania.package',
                    ],
                    [
                        'search' => 'snd',
                        'replace' => 'audio/basic',
                    ],
                    [
                        'search' => 'snf',
                        'replace' => 'application/x-font-snf',
                    ],
                    [
                        'search' => 'so',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'soa',
                        'replace' => 'text/dns',
                    ],
                    [
                        'search' => 'sofa',
                        'replace' => 'audio/sofa',
                    ],
                    [
                        'search' => 'sos',
                        'replace' => 'text/vnd.sosi',
                    ],
                    [
                        'search' => 'spc',
                        'replace' => 'application/x-pkcs7-certificates',
                    ],
                    [
                        'search' => 'spd',
                        'replace' => 'application/vnd.sealedmedia.softseal.pdf',
                    ],
                    [
                        'search' => 'spdf',
                        'replace' => 'application/vnd.sealedmedia.softseal.pdf',
                    ],
                    [
                        'search' => 'spdx',
                        'replace' => 'text/spdx',
                    ],
                    [
                        'search' => 'spdx.json',
                        'replace' => 'application/spdx+json',
                    ],
                    [
                        'search' => 'spf',
                        'replace' => 'application/vnd.yamaha.smaf-phrase',
                    ],
                    [
                        'search' => 'spl',
                        'replace' => 'application/x-futuresplash',
                    ],
                    [
                        'search' => 'spn',
                        'replace' => 'image/vnd.sealed.png',
                    ],
                    [
                        'search' => 'spng',
                        'replace' => 'image/vnd.sealed.png',
                    ],
                    [
                        'search' => 'spo',
                        'replace' => 'text/vnd.in3d.spot',
                    ],
                    [
                        'search' => 'spot',
                        'replace' => 'text/vnd.in3d.spot',
                    ],
                    [
                        'search' => 'spp',
                        'replace' => 'application/scvp-vp-response',
                    ],
                    [
                        'search' => 'sppt',
                        'replace' => 'application/vnd.sealed.ppt',
                    ],
                    [
                        'search' => 'spq',
                        'replace' => 'application/scvp-vp-request',
                    ],
                    [
                        'search' => 'spx',
                        'replace' => 'audio/ogg',
                    ],
                    [
                        'search' => 'sql',
                        'replace' => 'application/sql',
                    ],
                    [
                        'search' => 'sqlite',
                        'replace' => 'application/vnd.sqlite3',
                    ],
                    [
                        'search' => 'sqlite3',
                        'replace' => 'application/vnd.sqlite3',
                    ],
                    [
                        'search' => 'sr',
                        'replace' => 'application/vnd.sigrok.session',
                    ],
                    [
                        'search' => 'src',
                        'replace' => 'application/x-wais-source',
                    ],
                    [
                        'search' => 'srt',
                        'replace' => 'application/x-subrip',
                    ],
                    [
                        'search' => 'sru',
                        'replace' => 'application/sru+xml',
                    ],
                    [
                        'search' => 'srx',
                        'replace' => 'application/sparql-results+xml',
                    ],
                    [
                        'search' => 'ssdl',
                        'replace' => 'application/ssdl+xml',
                    ],
                    [
                        'search' => 'sse',
                        'replace' => 'application/vnd.kodak-descriptor',
                    ],
                    [
                        'search' => 'ssf',
                        'replace' => 'application/vnd.epson.ssf',
                    ],
                    [
                        'search' => 'ssml',
                        'replace' => 'application/ssml+xml',
                    ],
                    [
                        'search' => 'ssv',
                        'replace' => 'application/vnd.shade-save-file',
                    ],
                    [
                        'search' => 'ssvc',
                        'replace' => 'application/vnd.crypto-shade-file',
                    ],
                    [
                        'search' => 'ssw',
                        'replace' => 'video/vnd.sealed.swf',
                    ],
                    [
                        'search' => 'sswf',
                        'replace' => 'video/vnd.sealed.swf',
                    ],
                    [
                        'search' => 'st',
                        'replace' => 'application/vnd.sailingtracker.track',
                    ],
                    [
                        'search' => 'stc',
                        'replace' => 'application/vnd.sun.xml.calc.template',
                    ],
                    [
                        'search' => 'std',
                        'replace' => 'application/vnd.sun.xml.draw.template',
                    ],
                    [
                        'search' => 'step',
                        'replace' => 'model/step',
                    ],
                    [
                        'search' => 'stf',
                        'replace' => 'application/vnd.wt.stf',
                    ],
                    [
                        'search' => 'sti',
                        'replace' => 'application/vnd.sun.xml.impress.template',
                    ],
                    [
                        'search' => 'stif',
                        'replace' => 'application/vnd.sealed.tiff',
                    ],
                    [
                        'search' => 'stix',
                        'replace' => 'application/stix+json',
                    ],
                    [
                        'search' => 'stk',
                        'replace' => 'application/hyperstudio',
                    ],
                    [
                        'search' => 'stl',
                        'replace' => 'model/stl',
                    ],
                    [
                        'search' => 'stm',
                        'replace' => 'application/vnd.sealedmedia.softseal.html',
                    ],
                    [
                        'search' => 'stml',
                        'replace' => 'application/vnd.sealedmedia.softseal.html',
                    ],
                    [
                        'search' => 'stp',
                        'replace' => 'model/step',
                    ],
                    [
                        'search' => 'stpnc',
                        'replace' => 'model/step',
                    ],
                    [
                        'search' => 'stpx',
                        'replace' => 'model/step+xml',
                    ],
                    [
                        'search' => 'stpxz',
                        'replace' => 'model/step-xml+zip',
                    ],
                    [
                        'search' => 'stpz',
                        'replace' => 'model/step+zip',
                    ],
                    [
                        'search' => 'str',
                        'replace' => 'application/vnd.pg.format',
                    ],
                    [
                        'search' => 'study-inter',
                        'replace' => 'application/vnd.vd-study',
                    ],
                    [
                        'search' => 'stw',
                        'replace' => 'application/vnd.sun.xml.writer.template',
                    ],
                    [
                        'search' => 'styl',
                        'replace' => 'text/stylus',
                    ],
                    [
                        'search' => 'stylus',
                        'replace' => 'text/stylus',
                    ],
                    [
                        'search' => 'sub',
                        'replace' => 'image/vnd.dvb.subtitle',
                    ],
                    [
                        'search' => 'such',
                        'replace' => 'audio/vnd.dts.uhd',
                    ],
                    [
                        'search' => 'suit',
                        'replace' => 'application/suit-envelope+cose',
                    ],
                    [
                        'search' => 'super',
                        'replace' => 'application/vnd.superfile.super',
                    ],
                    [
                        'search' => 'sus',
                        'replace' => 'application/vnd.sus-calendar',
                    ],
                    [
                        'search' => 'susp',
                        'replace' => 'application/vnd.sus-calendar',
                    ],
                    [
                        'search' => 'sv4cpio',
                        'replace' => 'application/x-sv4cpio',
                    ],
                    [
                        'search' => 'sv4crc',
                        'replace' => 'application/x-sv4crc',
                    ],
                    [
                        'search' => 'svc',
                        'replace' => 'application/vnd.dvb.service',
                    ],
                    [
                        'search' => 'svd',
                        'replace' => 'application/vnd.svd',
                    ],
                    [
                        'search' => 'svg',
                        'replace' => 'image/svg+xml',
                    ],
                    [
                        'search' => 'svgz',
                        'replace' => 'image/svg+xml',
                    ],
                    [
                        'search' => 'swa',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'swf',
                        'replace' => 'application/x-shockwave-flash',
                    ],
                    [
                        'search' => 'swi',
                        'replace' => 'application/vnd.aristanetworks.swi',
                    ],
                    [
                        'search' => 'swidtag',
                        'replace' => 'application/swid+xml',
                    ],
                    [
                        'search' => 'sxc',
                        'replace' => 'application/vnd.sun.xml.calc',
                    ],
                    [
                        'search' => 'sxd',
                        'replace' => 'application/vnd.sun.xml.draw',
                    ],
                    [
                        'search' => 'sxg',
                        'replace' => 'application/vnd.sun.xml.writer.global',
                    ],
                    [
                        'search' => 'sxi',
                        'replace' => 'application/vnd.sun.xml.impress',
                    ],
                    [
                        'search' => 'sxl',
                        'replace' => 'application/vnd.sealed.xls',
                    ],
                    [
                        'search' => 'sxls',
                        'replace' => 'application/vnd.sealed.xls',
                    ],
                    [
                        'search' => 'sxm',
                        'replace' => 'application/vnd.sun.xml.math',
                    ],
                    [
                        'search' => 'sxw',
                        'replace' => 'application/vnd.sun.xml.writer',
                    ],
                    [
                        'search' => 'sy2',
                        'replace' => 'application/vnd.sybyl.mol2',
                    ],
                    [
                        'search' => 'syft.json',
                        'replace' => 'application/vnd.syft+json',
                    ],
                    [
                        'search' => 'systemverify',
                        'replace' => 'application/vnd.pp.systemverify+xml',
                    ],
                    [
                        'search' => 't',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 't3',
                        'replace' => 'application/x-t3vm-image',
                    ],
                    [
                        'search' => 't38',
                        'replace' => 'image/t38',
                    ],
                    [
                        'search' => 'taglet',
                        'replace' => 'application/vnd.mynfc',
                    ],
                    [
                        'search' => 'tak',
                        'replace' => 'application/rpki-signed-tal',
                    ],
                    [
                        'search' => 'tam',
                        'replace' => 'application/vnd.onepager',
                    ],
                    [
                        'search' => 'tamp',
                        'replace' => 'application/vnd.onepagertamp',
                    ],
                    [
                        'search' => 'tamx',
                        'replace' => 'application/vnd.onepagertamx',
                    ],
                    [
                        'search' => 'tao',
                        'replace' => 'application/vnd.tao.intent-module-archive',
                    ],
                    [
                        'search' => 'tap',
                        'replace' => 'image/vnd.tencent.tap',
                    ],
                    [
                        'search' => 'tar',
                        'replace' => 'application/x-tar',
                    ],
                    [
                        'search' => 'tat',
                        'replace' => 'application/vnd.onepagertat',
                    ],
                    [
                        'search' => 'tatp',
                        'replace' => 'application/vnd.onepagertatp',
                    ],
                    [
                        'search' => 'tatx',
                        'replace' => 'application/vnd.onepagertatx',
                    ],
                    [
                        'search' => 'tau',
                        'replace' => 'application/tamp-apex-update',
                    ],
                    [
                        'search' => 'tbz2',
                        'replace' => 'application/vnd.gentoo.xpak',
                    ],
                    [
                        'search' => 'tcap',
                        'replace' => 'application/vnd.3gpp2.tcap',
                    ],
                    [
                        'search' => 'tcl',
                        'replace' => 'application/x-tcl',
                    ],
                    [
                        'search' => 'tcu',
                        'replace' => 'application/tamp-community-update',
                    ],
                    [
                        'search' => 'td',
                        'replace' => 'application/urc-targetdesc+xml',
                    ],
                    [
                        'search' => 'teacher',
                        'replace' => 'application/vnd.smart.teacher',
                    ],
                    [
                        'search' => 'tei',
                        'replace' => 'application/tei+xml',
                    ],
                    [
                        'search' => 'teicorpus',
                        'replace' => 'application/tei+xml',
                    ],
                    [
                        'search' => 'ter',
                        'replace' => 'application/tamp-error',
                    ],
                    [
                        'search' => 'tex',
                        'replace' => 'application/x-tex',
                    ],
                    [
                        'search' => 'texi',
                        'replace' => 'application/x-texinfo',
                    ],
                    [
                        'search' => 'texinfo',
                        'replace' => 'application/x-texinfo',
                    ],
                    [
                        'search' => 'text',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'tfi',
                        'replace' => 'application/thraud+xml',
                    ],
                    [
                        'search' => 'tfm',
                        'replace' => 'application/x-tex-tfm',
                    ],
                    [
                        'search' => 'tfx',
                        'replace' => 'image/tiff-fx',
                    ],
                    [
                        'search' => 'tga',
                        'replace' => 'image/x-tga',
                    ],
                    [
                        'search' => 'thmx',
                        'replace' => 'application/vnd.ms-officetheme',
                    ],
                    [
                        'search' => 'tif',
                        'replace' => 'image/tiff',
                    ],
                    [
                        'search' => 'tiff',
                        'replace' => 'image/tiff',
                    ],
                    [
                        'search' => 'tk',
                        'replace' => 'application/x-tcl',
                    ],
                    [
                        'search' => 'tlclient',
                        'replace' => 'application/vnd.cendio.thinlinc.clientconf',
                    ],
                    [
                        'search' => 'tm.json',
                        'replace' => 'application/tm+json',
                    ],
                    [
                        'search' => 'tm.jsonld',
                        'replace' => 'application/tm+json',
                    ],
                    [
                        'search' => 'tmo',
                        'replace' => 'application/vnd.tmobile-livetv',
                    ],
                    [
                        'search' => 'toml',
                        'replace' => 'application/toml',
                    ],
                    [
                        'search' => 'torrent',
                        'replace' => 'application/x-bittorrent',
                    ],
                    [
                        'search' => 'tpl',
                        'replace' => 'application/vnd.groove-tool-template',
                    ],
                    [
                        'search' => 'tpt',
                        'replace' => 'application/vnd.trid.tpt',
                    ],
                    [
                        'search' => 'tr',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'tra',
                        'replace' => 'application/vnd.trueapp',
                    ],
                    [
                        'search' => 'tree',
                        'replace' => 'application/vnd.rainstor.data',
                    ],
                    [
                        'search' => 'trig',
                        'replace' => 'application/trig',
                    ],
                    [
                        'search' => 'trm',
                        'replace' => 'application/x-msterminal',
                    ],
                    [
                        'search' => 'ts',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'tsa',
                        'replace' => 'application/tamp-sequence-adjust',
                    ],
                    [
                        'search' => 'tsd',
                        'replace' => 'application/timestamped-data',
                    ],
                    [
                        'search' => 'tsq',
                        'replace' => 'application/timestamp-query',
                    ],
                    [
                        'search' => 'tsr',
                        'replace' => 'application/timestamp-reply',
                    ],
                    [
                        'search' => 'tst',
                        'replace' => 'application/vnd.etsi.timestamp-token',
                    ],
                    [
                        'search' => 'tsv',
                        'replace' => 'text/tab-separated-values',
                    ],
                    [
                        'search' => 'ttc',
                        'replace' => 'font/collection',
                    ],
                    [
                        'search' => 'ttf',
                        'replace' => 'font/ttf',
                    ],
                    [
                        'search' => 'ttl',
                        'replace' => 'text/turtle',
                    ],
                    [
                        'search' => 'ttml',
                        'replace' => 'application/ttml+xml',
                    ],
                    [
                        'search' => 'tuc',
                        'replace' => 'application/tamp-update-confirm',
                    ],
                    [
                        'search' => 'tur',
                        'replace' => 'application/tamp-update',
                    ],
                    [
                        'search' => 'twd',
                        'replace' => 'application/vnd.simtech-mindmapper',
                    ],
                    [
                        'search' => 'twds',
                        'replace' => 'application/vnd.simtech-mindmapper',
                    ],
                    [
                        'search' => 'txd',
                        'replace' => 'application/vnd.genomatix.tuxedo',
                    ],
                    [
                        'search' => 'txf',
                        'replace' => 'application/vnd.mobius.txf',
                    ],
                    [
                        'search' => 'txt',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'typ',
                        'replace' => 'text/vnd.typst',
                    ],
                    [
                        'search' => 'u32',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'u3d',
                        'replace' => 'model/u3d',
                    ],
                    [
                        'search' => 'u8dsn',
                        'replace' => 'message/global-delivery-status',
                    ],
                    [
                        'search' => 'u8hdr',
                        'replace' => 'message/global-headers',
                    ],
                    [
                        'search' => 'u8mdn',
                        'replace' => 'message/global-disposition-notification',
                    ],
                    [
                        'search' => 'u8msg',
                        'replace' => 'message/global',
                    ],
                    [
                        'search' => 'ubj',
                        'replace' => 'application/ubjson',
                    ],
                    [
                        'search' => 'uccs',
                        'replace' => 'application/uccs+cbor',
                    ],
                    [
                        'search' => 'udeb',
                        'replace' => 'application/x-debian-package',
                    ],
                    [
                        'search' => 'ufd',
                        'replace' => 'application/vnd.ufdl',
                    ],
                    [
                        'search' => 'ufdl',
                        'replace' => 'application/vnd.ufdl',
                    ],
                    [
                        'search' => 'uis',
                        'replace' => 'application/urc-uisocketdesc+xml',
                    ],
                    [
                        'search' => 'ujcs',
                        'replace' => 'application/ujcs+json',
                    ],
                    [
                        'search' => 'ulx',
                        'replace' => 'application/x-glulx',
                    ],
                    [
                        'search' => 'umj',
                        'replace' => 'application/vnd.umajin',
                    ],
                    [
                        'search' => 'unityweb',
                        'replace' => 'application/vnd.unity',
                    ],
                    [
                        'search' => 'uo',
                        'replace' => 'application/vnd.uoml+xml',
                    ],
                    [
                        'search' => 'uoml',
                        'replace' => 'application/vnd.uoml+xml',
                    ],
                    [
                        'search' => 'upa',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'uri',
                        'replace' => 'text/uri-list',
                    ],
                    [
                        'search' => 'uric',
                        'replace' => 'text/vnd.si.uricatalogue',
                    ],
                    [
                        'search' => 'urim',
                        'replace' => 'application/vnd.uri-map',
                    ],
                    [
                        'search' => 'urimap',
                        'replace' => 'application/vnd.uri-map',
                    ],
                    [
                        'search' => 'uris',
                        'replace' => 'text/uri-list',
                    ],
                    [
                        'search' => 'urls',
                        'replace' => 'text/uri-list',
                    ],
                    [
                        'search' => 'usda',
                        'replace' => 'model/vnd.usda',
                    ],
                    [
                        'search' => 'usdz',
                        'replace' => 'model/vnd.usdz+zip',
                    ],
                    [
                        'search' => 'ustar',
                        'replace' => 'application/x-ustar',
                    ],
                    [
                        'search' => 'utz',
                        'replace' => 'application/vnd.uiq.theme',
                    ],
                    [
                        'search' => 'uu',
                        'replace' => 'text/x-uuencode',
                    ],
                    [
                        'search' => 'uva',
                        'replace' => 'audio/vnd.dece.audio',
                    ],
                    [
                        'search' => 'uvd',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvf',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvg',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvh',
                        'replace' => 'video/vnd.dece.hd',
                    ],
                    [
                        'search' => 'uvi',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvm',
                        'replace' => 'video/vnd.dece.mobile',
                    ],
                    [
                        'search' => 'uvp',
                        'replace' => 'video/vnd.dece.pd',
                    ],
                    [
                        'search' => 'uvs',
                        'replace' => 'video/vnd.dece.sd',
                    ],
                    [
                        'search' => 'uvt',
                        'replace' => 'application/vnd.dece.ttml+xml',
                    ],
                    [
                        'search' => 'uvu',
                        'replace' => 'video/vnd.uvvu.mp4',
                    ],
                    [
                        'search' => 'uvv',
                        'replace' => 'video/vnd.dece.video',
                    ],
                    [
                        'search' => 'uvva',
                        'replace' => 'audio/vnd.dece.audio',
                    ],
                    [
                        'search' => 'uvvd',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvvf',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvvg',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvvh',
                        'replace' => 'video/vnd.dece.hd',
                    ],
                    [
                        'search' => 'uvvi',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvvm',
                        'replace' => 'video/vnd.dece.mobile',
                    ],
                    [
                        'search' => 'uvvp',
                        'replace' => 'video/vnd.dece.pd',
                    ],
                    [
                        'search' => 'uvvs',
                        'replace' => 'video/vnd.dece.sd',
                    ],
                    [
                        'search' => 'uvvt',
                        'replace' => 'application/vnd.dece.ttml+xml',
                    ],
                    [
                        'search' => 'uvvu',
                        'replace' => 'video/vnd.uvvu.mp4',
                    ],
                    [
                        'search' => 'uvvv',
                        'replace' => 'video/vnd.dece.video',
                    ],
                    [
                        'search' => 'uvvx',
                        'replace' => 'application/vnd.dece.unspecified',
                    ],
                    [
                        'search' => 'uvvz',
                        'replace' => 'application/vnd.dece.zip',
                    ],
                    [
                        'search' => 'uvx',
                        'replace' => 'application/vnd.dece.unspecified',
                    ],
                    [
                        'search' => 'uvz',
                        'replace' => 'application/vnd.dece.zip',
                    ],
                    [
                        'search' => 'various',
                        'replace' => 'audio/vnd.dts.uhd',
                    ],
                    [
                        'search' => 'vbk',
                        'replace' => 'audio/vnd.nortel.vbk',
                    ],
                    [
                        'search' => 'vbox',
                        'replace' => 'application/x-virtualbox-vbox',
                    ],
                    [
                        'search' => 'vbox-extpack',
                        'replace' => 'application/x-virtualbox-vbox-extpack',
                    ],
                    [
                        'search' => 'vc',
                        'replace' => 'application/vc',
                    ],
                    [
                        'search' => 'vcard',
                        'replace' => 'text/vcard',
                    ],
                    [
                        'search' => 'vcd',
                        'replace' => 'application/x-cdlink',
                    ],
                    [
                        'search' => 'vcf',
                        'replace' => 'text/vcard',
                    ],
                    [
                        'search' => 'vcf.bz2',
                        'replace' => 'application/prs.vcfbzip2',
                    ],
                    [
                        'search' => 'vcfbz2',
                        'replace' => 'application/prs.vcfbzip2',
                    ],
                    [
                        'search' => 'vcfbzip2',
                        'replace' => 'application/prs.vcfbzip2',
                    ],
                    [
                        'search' => 'vcg',
                        'replace' => 'application/vnd.groove-vcard',
                    ],
                    [
                        'search' => 'vcj',
                        'replace' => 'application/voucher-cms+json',
                    ],
                    [
                        'search' => 'vcs',
                        'replace' => 'text/x-vcalendar',
                    ],
                    [
                        'search' => 'vcx',
                        'replace' => 'application/vnd.vcx',
                    ],
                    [
                        'search' => 'vd',
                        'replace' => 'application/vividence.scriptfile',
                    ],
                    [
                        'search' => 'vdi',
                        'replace' => 'application/x-virtualbox-vdi',
                    ],
                    [
                        'search' => 'vds',
                        'replace' => 'model/vnd.sap.vds',
                    ],
                    [
                        'search' => 'vdx',
                        'replace' => 'application/vnd.ms-visio.viewer',
                    ],
                    [
                        'search' => 'ves',
                        'replace' => 'application/vnd.ves.encrypted',
                    ],
                    [
                        'search' => 'vfk',
                        'replace' => 'text/vnd.exchangeable',
                    ],
                    [
                        'search' => 'vfr',
                        'replace' => 'application/vnd.tml',
                    ],
                    [
                        'search' => 'vhd',
                        'replace' => 'application/x-virtualbox-vhd',
                    ],
                    [
                        'search' => 'viaframe',
                        'replace' => 'application/vnd.tml',
                    ],
                    [
                        'search' => 'vis',
                        'replace' => 'application/vnd.visionary',
                    ],
                    [
                        'search' => 'viv',
                        'replace' => 'video/vnd.vivo',
                    ],
                    [
                        'search' => 'vjj',
                        'replace' => 'application/voucher-jws+json',
                    ],
                    [
                        'search' => 'vmdk',
                        'replace' => 'application/x-virtualbox-vmdk',
                    ],
                    [
                        'search' => 'vmt',
                        'replace' => 'application/vnd.valve.source.material',
                    ],
                    [
                        'search' => 'vmv0',
                        'replace' => 'application/vnd.sirtx.vmv0',
                    ],
                    [
                        'search' => 'vob',
                        'replace' => 'video/x-ms-vob',
                    ],
                    [
                        'search' => 'vor',
                        'replace' => 'application/vnd.stardivision.writer',
                    ],
                    [
                        'search' => 'vox',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'vp',
                        'replace' => 'application/vp',
                    ],
                    [
                        'search' => 'vpm',
                        'replace' => 'multipart/voice-message',
                    ],
                    [
                        'search' => 'vrml',
                        'replace' => 'model/vrml',
                    ],
                    [
                        'search' => 'vsc',
                        'replace' => 'application/vnd.vidsoft.vidconference',
                    ],
                    [
                        'search' => 'vsd',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vsdx',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vsf',
                        'replace' => 'application/vnd.vsf',
                    ],
                    [
                        'search' => 'vsp4',
                        'replace' => 'application/vnd.vocalshaper.vsp4',
                    ],
                    [
                        'search' => 'vss',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vst',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vsw',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vtd',
                        'replace' => 'application/vividence.scriptfile',
                    ],
                    [
                        'search' => 'vtf',
                        'replace' => 'image/vnd.valve.source.texture',
                    ],
                    [
                        'search' => 'vtnstd',
                        'replace' => 'application/vnd.veritone.aion+json',
                    ],
                    [
                        'search' => 'vtt',
                        'replace' => 'text/vtt',
                    ],
                    [
                        'search' => 'vtu',
                        'replace' => 'model/vnd.vtu',
                    ],
                    [
                        'search' => 'vtx',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vwx',
                        'replace' => 'application/vnd.vectorworks',
                    ],
                    [
                        'search' => 'vxml',
                        'replace' => 'application/voicexml+xml',
                    ],
                    [
                        'search' => 'w3d',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'wad',
                        'replace' => 'application/x-doom',
                    ],
                    [
                        'search' => 'wadl',
                        'replace' => 'application/vnd.sun.wadl+xml',
                    ],
                    [
                        'search' => 'wafl',
                        'replace' => 'application/vnd.wasmflow.wafl',
                    ],
                    [
                        'search' => 'war',
                        'replace' => 'application/java-archive',
                    ],
                    [
                        'search' => 'wasm',
                        'replace' => 'application/wasm',
                    ],
                    [
                        'search' => 'wav',
                        'replace' => 'audio/wav',
                    ],
                    [
                        'search' => 'wax',
                        'replace' => 'audio/x-ms-wax',
                    ],
                    [
                        'search' => 'wbmp',
                        'replace' => 'image/vnd.wap.wbmp',
                    ],
                    [
                        'search' => 'wbs',
                        'replace' => 'application/vnd.criticaltools.wbs+xml',
                    ],
                    [
                        'search' => 'wbxml',
                        'replace' => 'application/vnd.wap.wbxml',
                    ],
                    [
                        'search' => 'wcm',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wdb',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wdp',
                        'replace' => 'image/vnd.ms-photo',
                    ],
                    [
                        'search' => 'weba',
                        'replace' => 'audio/webm',
                    ],
                    [
                        'search' => 'webapp',
                        'replace' => 'application/x-web-app-manifest+json',
                    ],
                    [
                        'search' => 'webm',
                        'replace' => 'video/webm',
                    ],
                    [
                        'search' => 'webmanifest',
                        'replace' => 'application/manifest+json',
                    ],
                    [
                        'search' => 'webp',
                        'replace' => 'image/webp',
                    ],
                    [
                        'search' => 'wg',
                        'replace' => 'application/vnd.pmi.widget',
                    ],
                    [
                        'search' => 'wgsl',
                        'replace' => 'text/wgsl',
                    ],
                    [
                        'search' => 'wgt',
                        'replace' => 'application/widget',
                    ],
                    [
                        'search' => 'wif',
                        'replace' => 'application/watcherinfo+xml',
                    ],
                    [
                        'search' => 'win',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'wks',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wlnk',
                        'replace' => 'application/link-format',
                    ],
                    [
                        'search' => 'wm',
                        'replace' => 'video/x-ms-wm',
                    ],
                    [
                        'search' => 'wma',
                        'replace' => 'audio/x-ms-wma',
                    ],
                    [
                        'search' => 'wmc',
                        'replace' => 'application/vnd.wmc',
                    ],
                    [
                        'search' => 'wmd',
                        'replace' => 'application/x-ms-wmd',
                    ],
                    [
                        'search' => 'wmf',
                        'replace' => 'image/wmf',
                    ],
                    [
                        'search' => 'wml',
                        'replace' => 'text/vnd.wap.wml',
                    ],
                    [
                        'search' => 'wmlc',
                        'replace' => 'application/vnd.wap.wmlc',
                    ],
                    [
                        'search' => 'wmls',
                        'replace' => 'text/vnd.wap.wmlscript',
                    ],
                    [
                        'search' => 'wmlsc',
                        'replace' => 'application/vnd.wap.wmlscriptc',
                    ],
                    [
                        'search' => 'wmv',
                        'replace' => 'video/x-ms-wmv',
                    ],
                    [
                        'search' => 'wmx',
                        'replace' => 'video/x-ms-wmx',
                    ],
                    [
                        'search' => 'wmz',
                        'replace' => 'application/x-ms-wmz',
                    ],
                    [
                        'search' => 'woff',
                        'replace' => 'font/woff',
                    ],
                    [
                        'search' => 'woff2',
                        'replace' => 'font/woff2',
                    ],
                    [
                        'search' => 'wpd',
                        'replace' => 'application/vnd.wordperfect',
                    ],
                    [
                        'search' => 'wpl',
                        'replace' => 'application/vnd.ms-wpl',
                    ],
                    [
                        'search' => 'wps',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wqd',
                        'replace' => 'application/vnd.wqd',
                    ],
                    [
                        'search' => 'wri',
                        'replace' => 'application/x-mswrite',
                    ],
                    [
                        'search' => 'wrl',
                        'replace' => 'model/vrml',
                    ],
                    [
                        'search' => 'wsc',
                        'replace' => 'application/vnd.wfa.wsc',
                    ],
                    [
                        'search' => 'wsdl',
                        'replace' => 'application/wsdl+xml',
                    ],
                    [
                        'search' => 'wspolicy',
                        'replace' => 'application/wspolicy+xml',
                    ],
                    [
                        'search' => 'wtb',
                        'replace' => 'application/vnd.webturbo',
                    ],
                    [
                        'search' => 'wv',
                        'replace' => 'application/vnd.wv.csp+wbxml',
                    ],
                    [
                        'search' => 'wvx',
                        'replace' => 'video/x-ms-wvx',
                    ],
                    [
                        'search' => 'x32',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'x3d',
                        'replace' => 'model/x3d+xml',
                    ],
                    [
                        'search' => 'x3db',
                        'replace' => 'model/x3d+binary',
                    ],
                    [
                        'search' => 'x3dbz',
                        'replace' => 'model/x3d+binary',
                    ],
                    [
                        'search' => 'x3dv',
                        'replace' => 'model/x3d+vrml',
                    ],
                    [
                        'search' => 'x3dvz',
                        'replace' => 'model/x3d+vrml',
                    ],
                    [
                        'search' => 'x3dz',
                        'replace' => 'model/x3d+xml',
                    ],
                    [
                        'search' => 'x_b',
                        'replace' => 'model/vnd.parasolid.transmit.binary',
                    ],
                    [
                        'search' => 'x_t',
                        'replace' => 'model/vnd.parasolid.transmit.text',
                    ],
                    [
                        'search' => 'xaml',
                        'replace' => 'application/xaml+xml',
                    ],
                    [
                        'search' => 'xap',
                        'replace' => 'application/x-silverlight-app',
                    ],
                    [
                        'search' => 'xar',
                        'replace' => 'application/vnd.xara',
                    ],
                    [
                        'search' => 'xav',
                        'replace' => 'application/xcap-att+xml',
                    ],
                    [
                        'search' => 'xbap',
                        'replace' => 'application/x-ms-xbap',
                    ],
                    [
                        'search' => 'xbd',
                        'replace' => 'application/vnd.fujixerox.docuworks.binder',
                    ],
                    [
                        'search' => 'xbm',
                        'replace' => 'image/x-xbitmap',
                    ],
                    [
                        'search' => 'xca',
                        'replace' => 'application/xcap-caps+xml',
                    ],
                    [
                        'search' => 'xcs',
                        'replace' => 'application/calendar+xml',
                    ],
                    [
                        'search' => 'xct',
                        'replace' => 'application/vnd.fujixerox.docuworks.container',
                    ],
                    [
                        'search' => 'xdcf',
                        'replace' => 'application/vnd.gov.sk.xmldatacontainer+xml',
                    ],
                    [
                        'search' => 'xdd',
                        'replace' => 'application/bacnet-xdd+zip',
                    ],
                    [
                        'search' => 'xdf',
                        'replace' => 'application/xcap-diff+xml',
                    ],
                    [
                        'search' => 'xdm',
                        'replace' => 'application/vnd.syncml.dm+xml',
                    ],
                    [
                        'search' => 'xdp',
                        'replace' => 'application/vnd.adobe.xdp+xml',
                    ],
                    [
                        'search' => 'xdssc',
                        'replace' => 'application/dssc+xml',
                    ],
                    [
                        'search' => 'xdw',
                        'replace' => 'application/vnd.fujixerox.docuworks',
                    ],
                    [
                        'search' => 'xel',
                        'replace' => 'application/xcap-el+xml',
                    ],
                    [
                        'search' => 'xenc',
                        'replace' => 'application/xenc+xml',
                    ],
                    [
                        'search' => 'xer',
                        'replace' => 'application/patch-ops-error+xml',
                    ],
                    [
                        'search' => 'xfd',
                        'replace' => 'application/vnd.xfdl',
                    ],
                    [
                        'search' => 'xfdf',
                        'replace' => 'application/vnd.adobe.xfdf',
                    ],
                    [
                        'search' => 'xfdl',
                        'replace' => 'application/vnd.xfdl',
                    ],
                    [
                        'search' => 'xhe',
                        'replace' => 'audio/usac',
                    ],
                    [
                        'search' => 'xht',
                        'replace' => 'application/xhtml+xml',
                    ],
                    [
                        'search' => 'xhtm',
                        'replace' => 'application/vnd.pwg-xhtml-print+xml',
                    ],
                    [
                        'search' => 'xhtml',
                        'replace' => 'application/xhtml+xml',
                    ],
                    [
                        'search' => 'xhvml',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'xif',
                        'replace' => 'image/vnd.xiff',
                    ],
                    [
                        'search' => 'xla',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xlam',
                        'replace' => 'application/vnd.ms-excel.addin.macroenabled.12',
                    ],
                    [
                        'search' => 'xlc',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xlf',
                        'replace' => 'application/xliff+xml',
                    ],
                    [
                        'search' => 'xlim',
                        'replace' => 'application/vnd.xmpie.xlim',
                    ],
                    [
                        'search' => 'xlm',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xls',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xlsb',
                        'replace' => 'application/vnd.ms-excel.sheet.binary.macroenabled.12',
                    ],
                    [
                        'search' => 'xlsm',
                        'replace' => 'application/vnd.ms-excel.sheet.macroenabled.12',
                    ],
                    [
                        'search' => 'xlsx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ],
                    [
                        'search' => 'xlt',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xltm',
                        'replace' => 'application/vnd.ms-excel.template.macroenabled.12',
                    ],
                    [
                        'search' => 'xltx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
                    ],
                    [
                        'search' => 'xlw',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xm',
                        'replace' => 'audio/xm',
                    ],
                    [
                        'search' => 'xmi',
                        'replace' => 'application/vnd.xmi+xml',
                    ],
                    [
                        'search' => 'xml',
                        'replace' => 'application/xml',
                    ],
                    [
                        'search' => 'xmls',
                        'replace' => 'application/dskpp+xml',
                    ],
                    [
                        'search' => 'xmt_bin',
                        'replace' => 'model/vnd.parasolid.transmit.binary',
                    ],
                    [
                        'search' => 'xmt_txt',
                        'replace' => 'model/vnd.parasolid.transmit.text',
                    ],
                    [
                        'search' => 'xns',
                        'replace' => 'application/xcap-ns+xml',
                    ],
                    [
                        'search' => 'xo',
                        'replace' => 'application/vnd.olpc-sugar',
                    ],
                    [
                        'search' => 'xodp',
                        'replace' => 'application/vnd.collabio.xodocuments.presentation',
                    ],
                    [
                        'search' => 'xods',
                        'replace' => 'application/vnd.collabio.xodocuments.spreadsheet',
                    ],
                    [
                        'search' => 'xodt',
                        'replace' => 'application/vnd.collabio.xodocuments.document',
                    ],
                    [
                        'search' => 'xop',
                        'replace' => 'application/xop+xml',
                    ],
                    [
                        'search' => 'xotp',
                        'replace' => 'application/vnd.collabio.xodocuments.presentation-template',
                    ],
                    [
                        'search' => 'xots',
                        'replace' => 'application/vnd.collabio.xodocuments.spreadsheet-template',
                    ],
                    [
                        'search' => 'xott',
                        'replace' => 'application/vnd.collabio.xodocuments.document-template',
                    ],
                    [
                        'search' => 'xpak',
                        'replace' => 'application/vnd.gentoo.xpak',
                    ],
                    [
                        'search' => 'xpi',
                        'replace' => 'application/x-xpinstall',
                    ],
                    [
                        'search' => 'xpl',
                        'replace' => 'application/xproc+xml',
                    ],
                    [
                        'search' => 'xpm',
                        'replace' => 'image/x-xpixmap',
                    ],
                    [
                        'search' => 'xpr',
                        'replace' => 'application/vnd.is-xpr',
                    ],
                    [
                        'search' => 'xps',
                        'replace' => 'application/vnd.ms-xpsdocument',
                    ],
                    [
                        'search' => 'xpw',
                        'replace' => 'application/vnd.intercon.formnet',
                    ],
                    [
                        'search' => 'xpx',
                        'replace' => 'application/vnd.intercon.formnet',
                    ],
                    [
                        'search' => 'xsd',
                        'replace' => 'application/xml',
                    ],
                    [
                        'search' => 'xsf',
                        'replace' => 'application/prs.xsf+xml',
                    ],
                    [
                        'search' => 'xsl',
                        'replace' => 'application/xslt+xml',
                    ],
                    [
                        'search' => 'xslt',
                        'replace' => 'application/xslt+xml',
                    ],
                    [
                        'search' => 'xsm',
                        'replace' => 'application/vnd.syncml+xml',
                    ],
                    [
                        'search' => 'xspf',
                        'replace' => 'application/xspf+xml',
                    ],
                    [
                        'search' => 'xul',
                        'replace' => 'application/vnd.mozilla.xul+xml',
                    ],
                    [
                        'search' => 'xvm',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'xvml',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'xwd',
                        'replace' => 'image/x-xwindowdump',
                    ],
                    [
                        'search' => 'xyz',
                        'replace' => 'chemical/x-xyz',
                    ],
                    [
                        'search' => 'xyze',
                        'replace' => 'image/vnd.radiance',
                    ],
                    [
                        'search' => 'xz',
                        'replace' => 'application/x-xz',
                    ],
                    [
                        'search' => 'yaml',
                        'replace' => 'text/yaml',
                    ],
                    [
                        'search' => 'yang',
                        'replace' => 'application/yang',
                    ],
                    [
                        'search' => 'yin',
                        'replace' => 'application/yin+xml',
                    ],
                    [
                        'search' => 'yme',
                        'replace' => 'application/vnd.yaoweme',
                    ],
                    [
                        'search' => 'yml',
                        'replace' => 'text/yaml',
                    ],
                    [
                        'search' => 'ymp',
                        'replace' => 'text/x-suse-ymp',
                    ],
                    [
                        'search' => 'yt',
                        'replace' => 'video/vnd.youtube.yt',
                    ],
                    [
                        'search' => 'yuv',
                        'replace' => 'video/vnd.planar',
                    ],
                    [
                        'search' => 'z1',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z2',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z3',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z4',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z5',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z6',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z7',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z8',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'zaz',
                        'replace' => 'application/vnd.zzazz.deck+xml',
                    ],
                    [
                        'search' => 'zfc',
                        'replace' => 'application/vnd.filmit.zfc',
                    ],
                    [
                        'search' => 'zfo',
                        'replace' => 'application/vnd.software602.filler.form-xml-zip',
                    ],
                    [
                        'search' => 'zip',
                        'replace' => 'application/zip',
                    ],
                    [
                        'search' => 'zir',
                        'replace' => 'application/vnd.zul',
                    ],
                    [
                        'search' => 'zirz',
                        'replace' => 'application/vnd.zul',
                    ],
                    [
                        'search' => 'zmm',
                        'replace' => 'application/vnd.handheld-entertainment+xml',
                    ],
                    [
                        'search' => 'zone',
                        'replace' => 'text/dns',
                    ],
                    [
                        'search' => 'zst',
                        'replace' => 'application/zstd',
                    ],
                ],
            ],
            'deduplicate_values' => true,
        ],

        // Enable Autocomplete: Autocomplete Attribute
        // --------------------------------------------------------------------------------
        // The Gravity Forms `autocompleteAttribute` attribute is a string value. It sets
        // the autocomplete attribute for the field.
        // --------------------------------------------------------------------------------
        'autocompleteAttribute' => [
            'statamic_attribute' => [
                'default' => 'autocomplete',
                'specific' => [
                    'email' => null, // email's autocompleteAttribute value matches the default value in Statamic
                    'phone' => null, // phone's autocompleteAttribute value matches the default value in Statamic
                    'website' => null, // phone's autocompleteAttribute value matches the default value in Statamic
                ],
                'based_on_other_attribute_value' => [
                    'default' => [
                        'inputType' => [
                            'email' => null,
                            'phone' => null,
                            'website' => null,
                        ],
                    ],
                ],
            ],
        ],

        // basePrice
        // --------------------------------------------------------------------------------
        // The Gravity Forms `basePrice` attribute is a string value. It sets
        // the base price for the product field when "Single Product" is selected.
        // --------------------------------------------------------------------------------
        'basePrice' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'default' => [
                        'inputType' => [
                            'singleproduct' => 'price', // Single Product
                            'hiddenproduct' => 'price', // Hidden
                            'price' => 'price', // User Defined Price
                            'calculation' => null, // Calculation
                            'radio' => null, // Radio Buttons
                            'select' => null, // Drop Down
                        ],
                    ],
                ],
            ],
            'convert_dollar_amount' => true,
        ],

        // Enable Calculation: Formula
        'calculationFormula' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'checkbox' => 'calculation_formula_text',
                    'date' => 'calculation_formula_text',
                    'email' => 'calculation_formula_text',
                    'hidden' => 'calculation_formula_text',
                    'multi_choice' => 'calculation_formula_text',
                    'number' => 'calculation_formula_math',
                    'password' => 'calculation_formula_text',
                    'phone' => 'calculation_formula_text',
                    'poll' => 'calculation_formula_text',
                    'radio' => 'calculation_formula_text',
                    'select' => 'calculation_formula_text',
                    'survey' => 'calculation_formula_text',
                    'text' => 'calculation_formula_text',
                    'textarea' => 'calculation_formula_text',
                    'time' => 'calculation_formula_text',
                    'website' => 'calculation_formula_text',
                ],
            ],
            'translate_field_references_calculation' => true,
            'unescape_slashes' => true,
        ],

        // Enable Calculation: Rounding
        'calculationRounding' => [
            'statamic_attribute' => [
                'default' => 'calculation_decimals',
            ],
            'as_integer' => true,
        ],

        // Date Picker Icon
        // --------------------------------------------------------------------------------
        // The Gravity Forms `calendarIconType` attribute is an enumerated string with the
        // following options:
        // - 'none' (No Icon)
        // - 'calendar' (Calendar Icon)
        // - 'custom' (Custom Icon)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'calendarIconType' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Date Picker Icon: Custom Icon: Image Path
        // --------------------------------------------------------------------------------
        // The Gravity Forms `calendarIconUrl` attribute is a string value. It sets the
        // URL of the custom icon to use for the date picker.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'calendarIconUrl' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Theme (Captcha)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `captchaTheme` attribute is an enumerated string with the
        // following options:
        // - 'light' (Light)
        // - 'dark' (Dark)
        // --------------------------------------------------------------------------------
        'captchaTheme' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // captchaType
        'captchaType' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Post category placeholder text
        // --------------------------------------------------------------------------------
        // Reported at any non-empty value (wordpress-importer-archive#1163). Gravity Forms core (`common.php`) renders
        // it as the first, empty-valued choice of a `post_category` select when
        // `categoryInitialItemEnabled` is set: placeholder text with no import target.
        // --------------------------------------------------------------------------------
        'categoryInitialItem' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
        ],

        // categoryInitialItemEnabled
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'categoryInitialItemEnabled' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Checkbox Label (`consent` fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `checkboxLabel` attribute is a string value. It sets the text
        // of the consent checkbox.
        // --------------------------------------------------------------------------------
        'checkboxLabel' => [
            'statamic_attribute' => [
                'default' => 'checkbox_label',
            ],
            'unescape_slashes' => true,
        ],

        // checked_indicator_markup
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'checked_indicator_markup' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // checked_indicator_url
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'checked_indicator_url' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Selections (multi_choice fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `choiceLimit` attribute is an enumerated string with the
        // following options:
        // - 'single' (Select One) — sets 'inputType` to `radio`
        // - 'unlimited' (Select Multiple) — sets 'inputType` to `checkboxes`
        // - 'exactly' (Select Exact Number) — sets 'inputType` to `checkboxes`
        // - 'range' (Select a Range) — sets 'inputType` to `checkboxes`
        //
        // If `choiceLimit` is set to either `exactly` or `range`, will will need to
        // either extend the `checkboxes` fieldtype or create a custom fieldtype, as the
        // base `checkboxes` fieldtype does not support these options.
        // --------------------------------------------------------------------------------
        'choiceLimit' => [
            'statamic_attribute' => [
                'default' => 'selection_limit',
                'based_on_attribute_value' => [
                    'default' => [
                        'single' => null, // default behavior for Statamic `radio` fieldtype
                        'unlimited' => null, // default behavior for Statamic `checkboxes` fieldtype
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'exactly',
                        'replace' => 'exact',
                    ],
                ],
            ],
        ],

        // Selections: Select a Range: Maximum
        'choiceLimitMax' => [
            'statamic_attribute' => [
                'default' => 'max_selections',
            ],
        ],

        // Selections: Select a Range: Minimum
        'choiceLimitMin' => [
            'statamic_attribute' => [
                'default' => 'min_selections',
            ],
        ],

        // Selections: Select Exact Number: Number
        'choiceLimitNumber' => [
            'statamic_attribute' => [
                'default' => 'exact_selections',
            ],
        ],

        // Choices
        'choices' => [
            'statamic_attribute' => [
                'default' => 'options',
                'based_on_other_attribute_value' => [
                    'specific' => [
                        'list' => [
                            'enableColumns' => [
                                'true' => 'fields',
                                'false' => null,
                                1 => 'fields',
                                0 => null,
                            ],
                        ],
                        'survey' => [
                            'inputType' => [
                                'likert' => null, // Don't set options for Likert scale fields
                            ],
                        ],
                    ],
                ],
            ],
            'separate_value_field_option' => [
                'default' => 'enableChoiceValue',
                'specific' => [
                    'survey' => null,
                ],
            ],
            'key_translation' => [
                'default' => [
                    'text' => 'value',
                    'value' => 'key',
                    'isSelected' => null,
                    'price' => null,
                    'imageChoices_image' => null,
                    'imageChoices_imageID' => null,
                    'key' => null,
                ],
                'specific' => [
                    // The Statamic `product` fieldtype's `options` grid stores a stable
                    // key, an optional display label, and the price. The Gravity Forms
                    // choice `text` is the human string an editor typed, so it becomes
                    // the `label`; the `key` — which is what `App\Fieldtypes\Product`
                    // matches a submitted value against — is generated as a slug by
                    // `value_transformations` below rather than reusing that raw string
                    // verbatim (issue wordpress-importer-archive#299).
                    'product' => [
                        'text' => 'label',
                        'value' => null,
                        'isSelected' => null,
                        'price' => 'price',
                        'imageChoices_image' => null,
                        'imageChoices_imageID' => null,
                        'key' => null,
                    ],
                    // The Statamic `option` fieldtype's `options` grid stores a stable
                    // key, a display label, and the price delta. Every audited form has
                    // `enableChoiceValue` off, so Gravity Forms carries `value` equal to
                    // `text` and the resulting key matches the label.
                    'option' => [
                        'text' => 'label',
                        'value' => 'key',
                        'isSelected' => null,
                        'price' => 'price',
                        'imageChoices_image' => null,
                        'imageChoices_imageID' => null,
                        'key' => null,
                    ],
                    'quiz' => [
                        'text' => 'label',
                        'gquizIsCorrect' => 'is_correct',
                        'gquizWeight' => 'weighted_score',
                        'value' => 'id',
                        'isSelected' => null,
                        'price' => null,
                        'key' => null,
                    ],
                    'poll' => [
                        'text' => 'key',
                        'value' => null,
                        'isSelected' => null,
                        'price' => null,
                        'imageChoices_image' => null,
                        'imageChoices_imageID' => null,
                    ],
                ],
                'specific_based_on_attribute_value' => [
                    'survey' => [ // fieldtype
                        'inputType' => [
                            'likert' => [
                                'imageChoices_image' => null,
                                'imageChoices_imageID' => null,
                                'isSelected' => null,
                                'key' => null,
                                'price' => null,
                                'score' => 'key',
                                'text' => 'value',
                                'value' => null,
                            ],
                            'rating' => [
                                'imageChoices_image' => null,
                                'imageChoices_imageID' => null,
                                'isSelected' => null,
                                'key' => null,
                                'price' => null,
                                'score' => null,
                                'text' => 'key',
                                'value' => 'value',
                            ],
                            'select' => [
                                'text' => 'key',
                                'value' => null,
                                'isSelected' => null,
                                'price' => null,
                            ],
                            'radio' => [
                                'text' => 'key',
                                'value' => null,
                                'isSelected' => null,
                                'price' => null,
                            ],
                            'checkbox' => [
                                'text' => 'key',
                                'value' => null,
                                'isSelected' => null,
                                'price' => null,
                            ],
                        ],
                    ],
                ],
            ],
            'value_transformations' => [
                'default' => [
                    'price' => [
                        'convert_dollar_amount' => true,
                    ],
                    'id' => [
                        'generate_value' => [
                            ['value' => '#field_handle'], // Get the handle of the top-level field
                            ['attribute_value_snake_case' => 'text'], // 'foo_bar' stays as 'foo_bar'; 'foo-bar' becomes 'foo_bar'; 'Foo Bar' becomes 'foo_bar'
                            ['value' => '#count'], // Get the count of the current item within the 'choices' array
                        ], // e.g., <field_handle>_<choices.text>_<count>
                    ],
                ],
                'specific' => [
                    // A `product` option's `key` is what a submission carries and what
                    // `App\Fieldtypes\Product::findOption()` matches against, so it has
                    // to be a stable slug rather than the raw human choice text —
                    // `Arkansas TBI Resource Guide (English)` is a label, not a key. The
                    // human string is preserved as the option's `label` by the
                    // `key_translation` above. Mirrors the `id` pattern (issue wordpress-importer-archive#299).
                    'product' => [
                        'key' => [
                            'generate_value' => [
                                ['value' => '#field_handle'], // Get the handle of the top-level field
                                ['attribute_value_snake_case' => 'text'], // 'foo_bar' stays as 'foo_bar'; 'foo-bar' becomes 'foo_bar'; 'Foo Bar' becomes 'foo_bar'
                                ['value' => '#count'], // Get the count of the current item within the 'choices' array
                            ], // e.g., <field_handle>_<choices.text>_<count>
                        ],
                    ],
                ],
                'specific_based_on_attribute_value' => [
                    'survey' => [ // fieldtype
                        'inputType' => [
                            'rating' => [
                                'value' => [
                                    'set_to_null' => true,
                                ],
                            ],
                            'radio' => [
                                'value' => [
                                    'set_to_null' => true,
                                ],
                            ],
                            'checkbox' => [
                                'value' => [
                                    'set_to_null' => true,
                                ],
                            ],
                            'select' => [
                                'value' => [
                                    'set_to_null' => true,
                                ],
                            ],
                        ],
                    ],
                    'product' => [ // fieldtype
                        'inputType' => [
                            'radio' => [
                                'id' => [
                                    'generate_value' => [
                                        ['value' => '#field_handle'], // Get the handle of the top-level field
                                        ['attribute_value_snake_case' => 'value'], // 'foo_bar' stays as 'foo_bar'; 'foo-bar' becomes 'foo_bar'; 'Foo Bar' becomes 'foo_bar'
                                        ['value' => '#count'], // Get the count of the current item within the 'choices' array
                                    ], // e.g., <field_handle>_<value>_<count>
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'exclude_fieldtypes' => [
                'consent',
            ],
            'choices_as_fields' => [ // Map 'choices' array as basic `fields` array items (e.g., ['handle' => '<parent_handle>_<snake_case_choice_value>_<choice_count>', 'field' => ['type' => 'text', 'display' => '<choice_value>']])
                'default' => false,
                'based_on_other_attribute_value' => [
                    'default' => [
                        'enableColumns' => [
                            'true' => true,
                            'false' => false,
                            1 => true,
                            0 => null,
                        ],
                    ],
                ],
            ],
            'expected_keys' => [ // Defines which keys should be present in the options array for each fieldtype
                'default' => [
                    'key',
                    'value',
                ],
                'specific' => [
                    'product' => [
                        'id',
                        'key',
                        'label',
                        'price',
                    ],
                    'option' => [
                        'id',
                        'key',
                        'label',
                        'price',
                    ],
                    'quiz' => [
                        'id',
                        'is_correct',
                        'label',
                        'weighted_score',
                    ],
                ],
                'based_on_other_attribute_value' => [
                    'default' => [
                        'enableColumns' => [
                            'true' => [
                                'handle',
                                'field',
                            ],
                            1 => [
                                'handle',
                                'field',
                            ],
                        ],
                    ],
                ],
            ],
            'statamic_other_field_value_based_on_nested_attribute_value' => [
                'default' => [],
                'specific' => [
                    'survey' => [ // gravity_forms_fieldtype
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Strongly disagree',
                                    'score' => 1,
                                ],
                                [
                                    'text' => 'Disagree',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Neutral',
                                    'score' => 3,
                                ],
                                [
                                    'text' => 'Agree',
                                    'score' => 4,
                                ],
                                [
                                    'text' => 'Strongly agree',
                                    'score' => 5,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [ // when `nested_attribute` matches, then set all attribute values in this array; the key is the attribute, the value is the value of that attribute
                                'scale_type' => 'agreement',
                                'number_of_points' => 5,
                                'enable_not_applicable' => false,
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Absent-0',
                                    'score' => 0, // numeric strings should be considered matches of numeric values (e.g., `'0'` should be considered a match for `0`)
                                ],
                                [
                                    'text' => 'Fair-1',
                                    'score' => 1,
                                ],
                                [
                                    'text' => 'Good-2',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Excellent-3',
                                    'score' => 3,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'quality',
                                'number_of_points' => 3,
                                'enable_not_applicable' => true,
                                'not_applicable_label' => 'Absent',
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Absent-0',
                                    'score' => 1, // numeric strings should be considered matches of numeric values (e.g., `'1'` should be considered a match for `1`)
                                ],
                                [
                                    'text' => 'Fair-1',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Good-2',
                                    'score' => 3,
                                ],
                                [
                                    'text' => 'Excellent-3',
                                    'score' => 4,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'quality',
                                'number_of_points' => 3,
                                'enable_not_applicable' => true,
                                'not_applicable_label' => 'Absent',
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Absent- 0',
                                    'score' => 1, // numeric strings should be considered matches of numeric values (e.g., `'1'` should be considered a match for `1`)
                                ],
                                [
                                    'text' => 'Fair- 1',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Good- 2',
                                    'score' => 3,
                                ],
                                [
                                    'text' => 'Excellent- 3',
                                    'score' => 4,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'quality',
                                'number_of_points' => 3,
                                'enable_not_applicable' => true,
                                'not_applicable_label' => 'Absent',
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Never',
                                    'score' => 1, // numeric strings should be considered matches of numeric values (e.g., `'1'` should be considered a match for `1`)
                                ],
                                [
                                    'text' => 'Rarely',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Sometimes',
                                    'score' => 3,
                                ],
                                [
                                    'text' => 'Often',
                                    'score' => 4,
                                ],
                                [
                                    'text' => 'Very Often',
                                    'score' => 5,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'frequency_no_always',
                                'number_of_points' => 5,
                                'enable_not_applicable' => false,
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Never',
                                    'score' => 5, // numeric strings should be considered matches of numeric values (e.g., `'5'` should be considered a match for `5`)
                                ],
                                [
                                    'text' => 'Rarely',
                                    'score' => 4,
                                ],
                                [
                                    'text' => 'Sometimes',
                                    'score' => 3,
                                ],
                                [
                                    'text' => 'Often',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Very Often',
                                    'score' => 1,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'frequency_no_always',
                                'number_of_points' => 5,
                                'enable_not_applicable' => false,
                                'display_order' => 'asc',
                                'reverse_scale' => true,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Strongly Disagree',
                                    'score' => 1, // numeric strings should be considered matches of numeric values (e.g., `'1'` should be considered a match for `1`)
                                ],
                                [
                                    'text' => 'Disagree',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Agree',
                                    'score' => 3,
                                ],
                                [
                                    'text' => 'Strongly Agree',
                                    'score' => 4,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'agreement',
                                'number_of_points' => 4,
                                'enable_not_applicable' => false,
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Very Dissatisfied',
                                    'score' => 1, // numeric strings should be considered matches of numeric values (e.g., `'1'` should be considered a match for `1`)
                                ],
                                [
                                    'text' => 'Moderately Dissatisfied',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Neither Satisfied Nor Dissatisfied',
                                    'score' => 3,
                                ],
                                [
                                    'text' => 'Moderately Satisfied',
                                    'score' => 4,
                                ],
                                [
                                    'text' => 'Very Satisfied',
                                    'score' => 5,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'satisfaction',
                                'number_of_points' => 5,
                                'enable_not_applicable' => false,
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'Strongly Disagree',
                                    'score' => 1, // numeric strings should be considered matches of numeric values (e.g., `'1'` should be considered a match for `1`)
                                ],
                                [
                                    'text' => 'Disagree',
                                    'score' => 2,
                                ],
                                [
                                    'text' => 'Agree',
                                    'score' => 4,
                                ],
                                [
                                    'text' => 'Strongly Agree',
                                    'score' => 5,
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'scale_type' => 'agreement',
                                'number_of_points' => 5,
                                'enable_not_applicable' => false,
                                'display_order' => 'asc',
                                'reverse_scale' => false,
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => 'I like the Seeds of Science Program as is',
                                    'score' => '1',
                                ],
                                [
                                    'text' => 'I like the Arkansas Breast Cancer Research Program (ABCRP) pilot funding program as is',
                                    'score' => '2',
                                ],
                                [
                                    'text' => 'I would like all available funds to support cancer-related K awards similar to what is made available through the TRI.',
                                    'score' => '3',
                                ],
                                [
                                    'text' => 'I would like some of the available funds to support cancer-related K awards similar to what is made available through the TRI.',
                                    'score' => '4',
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'type' => 'radio',
                                'options' => [
                                    [
                                        'key' => '1',
                                        'value' => 'I like the Seeds of Science Program as is',
                                    ],
                                    [
                                        'key' => '2',
                                        'value' => 'I like the Arkansas Breast Cancer Research Program (ABCRP) pilot funding program as is',
                                    ],
                                    [
                                        'key' => '3',
                                        'value' => 'I would like all available funds to support cancer-related K awards similar to what is made available through the TRI.',
                                    ],
                                    [
                                        'key' => '4',
                                        'value' => 'I would like some of the available funds to support cancer-related K awards similar to what is made available through the TRI.',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'nested_attribute' => [ // if associative array, then all nested items must have the matching key/value pairs; if indexed array of arrays, then each nested item's attribute values must be in the array in the matching order
                                [
                                    'text' => '$10,000',
                                    'score' => '1',
                                ],
                                [
                                    'text' => '$25,000',
                                    'score' => '2',
                                ],
                                [
                                    'text' => '$50,000',
                                    'score' => '3',
                                ],
                                [
                                    'text' => '$75,000',
                                    'score' => '4',
                                ],
                                [
                                    'text' => '$100,000',
                                    'score' => '5',
                                ],
                                [
                                    'text' => 'Other',
                                    'score' => '6',
                                ],
                            ],
                            'numeric_value_equivalent' => true, // default is true
                            'case_sensitive_key' => false, // default is false
                            'case_sensitive_value' => false, // default is false
                            'other_attribute' => [
                                'type' => 'radio',
                                'options' => [
                                    [
                                        'key' => '1',
                                        'value' => '$10,000',
                                    ],
                                    [
                                        'key' => '2',
                                        'value' => '$25,000',
                                    ],
                                    [
                                        'key' => '3',
                                        'value' => '$50,000',
                                    ],
                                    [
                                        'key' => '4',
                                        'value' => '$75,000',
                                    ],
                                    [
                                        'key' => '5',
                                        'value' => '$100,000',
                                    ],
                                    [
                                        'key' => '6',
                                        'value' => 'Other',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // Conditional Logic
        // --------------------------------------------------------------------------------
        // The Gravity Forms `conditionalLogic` attribute is an object that defines
        // conditional display logic for fields. It contains:
        // - enabled: boolean - whether conditional logic is active
        // - actionType: "show" | "hide" - whether to show or hide the field
        // - logicType: "all" | "any" - whether all or any conditions must be met
        // - rules: array of condition objects with fieldId, operator, and value
        //
        // Maps to Statamic conditional logic attributes:
        // - if: Show when all conditions are met
        // - if_any: Show when any condition is met
        // - unless: Hide when all conditions are met
        // - unless_any: Hide when any condition is met
        // --------------------------------------------------------------------------------
        'conditionalLogic' => [
            'statamic_attribute' => [
                'default' => null, // Handled by custom mapping logic
            ],
        ],

        // Content (HTML)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `content` attribute is a string value. It sets the HTML
        // content of the field.
        // --------------------------------------------------------------------------------
        'content' => [
            'statamic_attribute' => [
                'default' => 'html',
            ],
            'preserve_newlines' => [
                'default' => true,
            ],
            'unescape_slashes' => true,
        ],

        // copyValuesOptionDefault
        'copyValuesOptionDefault' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // copyValuesOptionField
        'copyValuesOptionField' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // copyValuesOptionLabel
        'copyValuesOptionLabel' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // customFieldTemplate
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'customFieldTemplate' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // customFieldTemplateEnabled
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'customFieldTemplateEnabled' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Date Format
        // --------------------------------------------------------------------------------
        // The Gravity Forms `dateFormat` attribute is an enumerated string with the
        // following options:
        // - 'mdy' (mm/dd/yyyy)
        // - 'dmy' (dd/mm/yyyy)
        // - 'dmy_dash' (dd-mm-yyyy)
        // - 'dmy_dot' (dd.mm.yyyy)
        // - 'ymd_slash' (yyyy/mm/dd)
        // - 'ymd_dash' (yyyy-mm-dd)
        // - 'ymd_dot' (yyyy.mm.dd)
        // --------------------------------------------------------------------------------
        'dateFormat' => [
            'statamic_attribute' => [
                'default' => 'format',
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'mdy',
                        'replace' => 'm/d/Y',
                    ],
                    [
                        'search' => 'dmy',
                        'replace' => 'd/m/Y',
                    ],
                    [
                        'search' => 'dmy_dash',
                        'replace' => 'd-m-Y',
                    ],
                    [
                        'search' => 'dmy_dot',
                        'replace' => 'd.m.Y',
                    ],
                    [
                        'search' => 'ymd_slash',
                        'replace' => 'Y/m/d',
                    ],
                    [
                        'search' => 'ymd_dash',
                        'replace' => 'Y-m-d',
                    ],
                    [
                        'search' => 'ymd_dot',
                        'replace' => 'Y.m.d',
                    ],
                ],
            ],
        ],

        // Date Format Placement
        // --------------------------------------------------------------------------------
        // The Gravity Forms `dateFormatPlacement` attribute is an enumerated string with
        // the following options:
        // - 'below' (Below inputs)
        // - 'above' (Above inputs)
        // - 'hidden_label' (Hidden)
        // - 'placeholder' (Placeholder)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'dateFormatPlacement' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Date Input Type
        // --------------------------------------------------------------------------------
        // The Gravity Forms `dateType` attribute is an enumerated string with the
        // following options:
        // - 'datefield' (Date Field)
        // - 'datepicker' (Date Picker)
        // - 'datedropdown' (Date Drop Down)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'dateType' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Default Country
        // --------------------------------------------------------------------------------
        // The Gravity Forms `defaultCountry` attribute is an enumerated string with
        // options like the following:
        // - 'Afghanistan' (Afghanistan)
        // - 'Albania' (Albania)
        // - 'Algeria' (Algeria)
        // - 'American Samoa' (American Samoa)
        //
        // The `defaultCountry` attribute is used to set the default country for the
        // `address` fieldtype. However, the `defaultValue` within the `items` array
        // is also used to set the default country for the `address` fieldtype. If the
        // latter is present, it will take precedence over the `defaultCountry` attribute.
        // --------------------------------------------------------------------------------
        'defaultCountry' => [
            'statamic_attribute' => [
                'default' => 'country_default',
                'based_on_other_attribute_value' => [
                    'default' => [
                        'addressType' => [
                            'us' => null,
                            'canadian' => null,
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'Afghanistan',
                        'replace' => 'AF',
                    ],
                    [
                        'search' => 'Albania',
                        'replace' => 'AL',
                    ],
                    [
                        'search' => 'Algeria',
                        'replace' => 'DZ',
                    ],
                    [
                        'search' => 'American Samoa',
                        'replace' => 'AS',
                    ],
                    [
                        'search' => 'Andorra',
                        'replace' => 'AD',
                    ],
                    [
                        'search' => 'Angola',
                        'replace' => 'AO',
                    ],
                    [
                        'search' => 'Anguilla',
                        'replace' => 'AI',
                    ],
                    [
                        'search' => 'Antarctica',
                        'replace' => 'AQ',
                    ],
                    [
                        'search' => 'Antigua and Barbuda',
                        'replace' => 'AG',
                    ],
                    [
                        'search' => 'Argentina',
                        'replace' => 'AR',
                    ],
                    [
                        'search' => 'Armenia',
                        'replace' => 'AM',
                    ],
                    [
                        'search' => 'Aruba',
                        'replace' => 'AW',
                    ],
                    [
                        'search' => 'Australia',
                        'replace' => 'AU',
                    ],
                    [
                        'search' => 'Austria',
                        'replace' => 'AT',
                    ],
                    [
                        'search' => 'Azerbaijan',
                        'replace' => 'AZ',
                    ],
                    [
                        'search' => 'Bahamas',
                        'replace' => 'BS',
                    ],
                    [
                        'search' => 'Bahrain',
                        'replace' => 'BH',
                    ],
                    [
                        'search' => 'Bangladesh',
                        'replace' => 'BD',
                    ],
                    [
                        'search' => 'Barbados',
                        'replace' => 'BB',
                    ],
                    [
                        'search' => 'Belarus',
                        'replace' => 'BY',
                    ],
                    [
                        'search' => 'Belgium',
                        'replace' => 'BE',
                    ],
                    [
                        'search' => 'Belize',
                        'replace' => 'BZ',
                    ],
                    [
                        'search' => 'Benin',
                        'replace' => 'BJ',
                    ],
                    [
                        'search' => 'Bermuda',
                        'replace' => 'BM',
                    ],
                    [
                        'search' => 'Bhutan',
                        'replace' => 'BT',
                    ],
                    [
                        'search' => 'Bolivia',
                        'replace' => 'BO',
                    ],
                    [
                        'search' => 'Bonaire, Sint Eustatius and Saba',
                        'replace' => 'BQ',
                    ],
                    [
                        'search' => 'Bosnia and Herzegovina',
                        'replace' => 'BA',
                    ],
                    [
                        'search' => 'Botswana',
                        'replace' => 'BW',
                    ],
                    [
                        'search' => 'Bouvet Island',
                        'replace' => 'BV',
                    ],
                    [
                        'search' => 'Brazil',
                        'replace' => 'BR',
                    ],
                    [
                        'search' => 'British Indian Ocean Territory',
                        'replace' => 'IO',
                    ],
                    [
                        'search' => 'Brunei Darussalam',
                        'replace' => 'BN',
                    ],
                    [
                        'search' => 'Bulgaria',
                        'replace' => 'BG',
                    ],
                    [
                        'search' => 'Burkina Faso',
                        'replace' => 'BF',
                    ],
                    [
                        'search' => 'Burundi',
                        'replace' => 'BI',
                    ],
                    [
                        'search' => 'Cabo Verde',
                        'replace' => 'CV',
                    ],
                    [
                        'search' => 'Cambodia',
                        'replace' => 'KH',
                    ],
                    [
                        'search' => 'Cameroon',
                        'replace' => 'CM',
                    ],
                    [
                        'search' => 'Canada',
                        'replace' => 'CA',
                    ],
                    [
                        'search' => 'Cayman Islands',
                        'replace' => 'KY',
                    ],
                    [
                        'search' => 'Central African Republic',
                        'replace' => 'CF',
                    ],
                    [
                        'search' => 'Chad',
                        'replace' => 'TD',
                    ],
                    [
                        'search' => 'Chile',
                        'replace' => 'CL',
                    ],
                    [
                        'search' => 'China',
                        'replace' => 'CN',
                    ],
                    [
                        'search' => 'Christmas Island',
                        'replace' => 'CX',
                    ],
                    [
                        'search' => 'Cocos Islands',
                        'replace' => 'CC',
                    ],
                    [
                        'search' => 'Colombia',
                        'replace' => 'CO',
                    ],
                    [
                        'search' => 'Comoros',
                        'replace' => 'KM',
                    ],
                    [
                        'search' => 'Congo',
                        'replace' => 'CG',
                    ],
                    [
                        'search' => 'Congo, Democratic Republic of the',
                        'replace' => 'CD',
                    ],
                    [
                        'search' => 'Cook Islands',
                        'replace' => 'CK',
                    ],
                    [
                        'search' => 'Costa Rica',
                        'replace' => 'CR',
                    ],
                    [
                        'search' => 'Croatia',
                        'replace' => 'HR',
                    ],
                    [
                        'search' => 'Cuba',
                        'replace' => 'CU',
                    ],
                    [
                        'search' => 'Curaçao',
                        'replace' => 'CW',
                    ],
                    [
                        'search' => 'Cyprus',
                        'replace' => 'CY',
                    ],
                    [
                        'search' => 'Czechia',
                        'replace' => 'CZ',
                    ],
                    [
                        'search' => 'Côte dIvoire',
                        'replace' => 'CI',
                    ],
                    [
                        'search' => 'Denmark',
                        'replace' => 'DK',
                    ],
                    [
                        'search' => 'Djibouti',
                        'replace' => 'DJ',
                    ],
                    [
                        'search' => 'Dominica',
                        'replace' => 'DM',
                    ],
                    [
                        'search' => 'Dominican Republic',
                        'replace' => 'DO',
                    ],
                    [
                        'search' => 'Ecuador',
                        'replace' => 'EC',
                    ],
                    [
                        'search' => 'Egypt',
                        'replace' => 'EG',
                    ],
                    [
                        'search' => 'El Salvador',
                        'replace' => 'SV',
                    ],
                    [
                        'search' => 'Equatorial Guinea',
                        'replace' => 'GQ',
                    ],
                    [
                        'search' => 'Eritrea',
                        'replace' => 'ER',
                    ],
                    [
                        'search' => 'Estonia',
                        'replace' => 'EE',
                    ],
                    [
                        'search' => 'Eswatini',
                        'replace' => 'SZ',
                    ],
                    [
                        'search' => 'Ethiopia',
                        'replace' => 'ET',
                    ],
                    [
                        'search' => 'Falkland Islands',
                        'replace' => 'FK',
                    ],
                    [
                        'search' => 'Faroe Islands',
                        'replace' => 'FO',
                    ],
                    [
                        'search' => 'Fiji',
                        'replace' => 'FJ',
                    ],
                    [
                        'search' => 'Finland',
                        'replace' => 'FI',
                    ],
                    [
                        'search' => 'France',
                        'replace' => 'FR',
                    ],
                    [
                        'search' => 'French Guiana',
                        'replace' => 'GF',
                    ],
                    [
                        'search' => 'French Polynesia',
                        'replace' => 'PF',
                    ],
                    [
                        'search' => 'French Southern Territories',
                        'replace' => 'TF',
                    ],
                    [
                        'search' => 'Gabon',
                        'replace' => 'GA',
                    ],
                    [
                        'search' => 'Gambia',
                        'replace' => 'GM',
                    ],
                    [
                        'search' => 'Georgia',
                        'replace' => 'GE',
                    ],
                    [
                        'search' => 'Germany',
                        'replace' => 'DE',
                    ],
                    [
                        'search' => 'Ghana',
                        'replace' => 'GH',
                    ],
                    [
                        'search' => 'Gibraltar',
                        'replace' => 'GI',
                    ],
                    [
                        'search' => 'Greece',
                        'replace' => 'GR',
                    ],
                    [
                        'search' => 'Greenland',
                        'replace' => 'GL',
                    ],
                    [
                        'search' => 'Grenada',
                        'replace' => 'GD',
                    ],
                    [
                        'search' => 'Guadeloupe',
                        'replace' => 'GP',
                    ],
                    [
                        'search' => 'Guam',
                        'replace' => 'GU',
                    ],
                    [
                        'search' => 'Guatemala',
                        'replace' => 'GT',
                    ],
                    [
                        'search' => 'Guernsey',
                        'replace' => 'GG',
                    ],
                    [
                        'search' => 'Guinea',
                        'replace' => 'GN',
                    ],
                    [
                        'search' => 'Guinea-Bissau',
                        'replace' => 'GW',
                    ],
                    [
                        'search' => 'Guyana',
                        'replace' => 'GY',
                    ],
                    [
                        'search' => 'Haiti',
                        'replace' => 'HT',
                    ],
                    [
                        'search' => 'Heard Island and McDonald Islands',
                        'replace' => 'HM',
                    ],
                    [
                        'search' => 'Holy See',
                        'replace' => 'VA',
                    ],
                    [
                        'search' => 'Honduras',
                        'replace' => 'HN',
                    ],
                    [
                        'search' => 'Hong Kong',
                        'replace' => 'HK',
                    ],
                    [
                        'search' => 'Hungary',
                        'replace' => 'HU',
                    ],
                    [
                        'search' => 'Iceland',
                        'replace' => 'IS',
                    ],
                    [
                        'search' => 'India',
                        'replace' => 'IN',
                    ],
                    [
                        'search' => 'Indonesia',
                        'replace' => 'ID',
                    ],
                    [
                        'search' => 'Iran',
                        'replace' => 'IR',
                    ],
                    [
                        'search' => 'Iraq',
                        'replace' => 'IQ',
                    ],
                    [
                        'search' => 'Ireland',
                        'replace' => 'IE',
                    ],
                    [
                        'search' => 'Isle of Man',
                        'replace' => 'IM',
                    ],
                    [
                        'search' => 'Israel',
                        'replace' => 'IL',
                    ],
                    [
                        'search' => 'Italy',
                        'replace' => 'IT',
                    ],
                    [
                        'search' => 'Jamaica',
                        'replace' => 'JM',
                    ],
                    [
                        'search' => 'Japan',
                        'replace' => 'JP',
                    ],
                    [
                        'search' => 'Jersey',
                        'replace' => 'JE',
                    ],
                    [
                        'search' => 'Jordan',
                        'replace' => 'JO',
                    ],
                    [
                        'search' => 'Kazakhstan',
                        'replace' => 'KZ',
                    ],
                    [
                        'search' => 'Kenya',
                        'replace' => 'KE',
                    ],
                    [
                        'search' => 'Kiribati',
                        'replace' => 'KI',
                    ],
                    [
                        'search' => 'Korea, Democratic Peoples Republic of',
                        'replace' => 'KP',
                    ],
                    [
                        'search' => 'Korea, Republic of',
                        'replace' => 'KR',
                    ],
                    [
                        'search' => 'Kuwait',
                        'replace' => 'KW',
                    ],
                    [
                        'search' => 'Kyrgyzstan',
                        'replace' => 'KG',
                    ],
                    [
                        'search' => 'Lao Peoples Democratic Republic',
                        'replace' => 'LA',
                    ],
                    [
                        'search' => 'Latvia',
                        'replace' => 'LV',
                    ],
                    [
                        'search' => 'Lebanon',
                        'replace' => 'LB',
                    ],
                    [
                        'search' => 'Lesotho',
                        'replace' => 'LS',
                    ],
                    [
                        'search' => 'Liberia',
                        'replace' => 'LR',
                    ],
                    [
                        'search' => 'Libya',
                        'replace' => 'LY',
                    ],
                    [
                        'search' => 'Liechtenstein',
                        'replace' => 'LI',
                    ],
                    [
                        'search' => 'Lithuania',
                        'replace' => 'LT',
                    ],
                    [
                        'search' => 'Luxembourg',
                        'replace' => 'LU',
                    ],
                    [
                        'search' => 'Macao',
                        'replace' => 'MO',
                    ],
                    [
                        'search' => 'Madagascar',
                        'replace' => 'MG',
                    ],
                    [
                        'search' => 'Malawi',
                        'replace' => 'MW',
                    ],
                    [
                        'search' => 'Malaysia',
                        'replace' => 'MY',
                    ],
                    [
                        'search' => 'Maldives',
                        'replace' => 'MV',
                    ],
                    [
                        'search' => 'Mali',
                        'replace' => 'ML',
                    ],
                    [
                        'search' => 'Malta',
                        'replace' => 'MT',
                    ],
                    [
                        'search' => 'Marshall Islands',
                        'replace' => 'MH',
                    ],
                    [
                        'search' => 'Martinique',
                        'replace' => 'MQ',
                    ],
                    [
                        'search' => 'Mauritania',
                        'replace' => 'MR',
                    ],
                    [
                        'search' => 'Mauritius',
                        'replace' => 'MU',
                    ],
                    [
                        'search' => 'Mayotte',
                        'replace' => 'YT',
                    ],
                    [
                        'search' => 'Mexico',
                        'replace' => 'MX',
                    ],
                    [
                        'search' => 'Micronesia',
                        'replace' => 'FM',
                    ],
                    [
                        'search' => 'Moldova',
                        'replace' => 'MD',
                    ],
                    [
                        'search' => 'Monaco',
                        'replace' => 'MC',
                    ],
                    [
                        'search' => 'Mongolia',
                        'replace' => 'MN',
                    ],
                    [
                        'search' => 'Montenegro',
                        'replace' => 'ME',
                    ],
                    [
                        'search' => 'Montserrat',
                        'replace' => 'MS',
                    ],
                    [
                        'search' => 'Morocco',
                        'replace' => 'MA',
                    ],
                    [
                        'search' => 'Mozambique',
                        'replace' => 'MZ',
                    ],
                    [
                        'search' => 'Myanmar',
                        'replace' => 'MM',
                    ],
                    [
                        'search' => 'Namibia',
                        'replace' => 'NA',
                    ],
                    [
                        'search' => 'Nauru',
                        'replace' => 'NR',
                    ],
                    [
                        'search' => 'Nepal',
                        'replace' => 'NP',
                    ],
                    [
                        'search' => 'Netherlands',
                        'replace' => 'NL',
                    ],
                    [
                        'search' => 'New Caledonia',
                        'replace' => 'NC',
                    ],
                    [
                        'search' => 'New Zealand',
                        'replace' => 'NZ',
                    ],
                    [
                        'search' => 'Nicaragua',
                        'replace' => 'NI',
                    ],
                    [
                        'search' => 'Niger',
                        'replace' => 'NE',
                    ],
                    [
                        'search' => 'Nigeria',
                        'replace' => 'NG',
                    ],
                    [
                        'search' => 'Niue',
                        'replace' => 'NU',
                    ],
                    [
                        'search' => 'Norfolk Island',
                        'replace' => 'NF',
                    ],
                    [
                        'search' => 'North Macedonia',
                        'replace' => 'MK',
                    ],
                    [
                        'search' => 'Northern Mariana Islands',
                        'replace' => 'MP',
                    ],
                    [
                        'search' => 'Norway',
                        'replace' => 'NO',
                    ],
                    [
                        'search' => 'Oman',
                        'replace' => 'OM',
                    ],
                    [
                        'search' => 'Pakistan',
                        'replace' => 'PK',
                    ],
                    [
                        'search' => 'Palau',
                        'replace' => 'PW',
                    ],
                    [
                        'search' => 'Palestine, State of',
                        'replace' => 'PS',
                    ],
                    [
                        'search' => 'Panama',
                        'replace' => 'PA',
                    ],
                    [
                        'search' => 'Papua New Guinea',
                        'replace' => 'PG',
                    ],
                    [
                        'search' => 'Paraguay',
                        'replace' => 'PY',
                    ],
                    [
                        'search' => 'Peru',
                        'replace' => 'PE',
                    ],
                    [
                        'search' => 'Philippines',
                        'replace' => 'PH',
                    ],
                    [
                        'search' => 'Pitcairn',
                        'replace' => 'PN',
                    ],
                    [
                        'search' => 'Poland',
                        'replace' => 'PL',
                    ],
                    [
                        'search' => 'Portugal',
                        'replace' => 'PT',
                    ],
                    [
                        'search' => 'Puerto Rico',
                        'replace' => 'PR',
                    ],
                    [
                        'search' => 'Qatar',
                        'replace' => 'QA',
                    ],
                    [
                        'search' => 'Romania',
                        'replace' => 'RO',
                    ],
                    [
                        'search' => 'Russian Federation',
                        'replace' => 'RU',
                    ],
                    [
                        'search' => 'Rwanda',
                        'replace' => 'RW',
                    ],
                    [
                        'search' => 'Réunion',
                        'replace' => 'RE',
                    ],
                    [
                        'search' => 'Saint Barthélemy',
                        'replace' => 'BL',
                    ],
                    [
                        'search' => 'Saint Helena, Ascension and Tristan da Cunha',
                        'replace' => 'SH',
                    ],
                    [
                        'search' => 'Saint Kitts and Nevis',
                        'replace' => 'KN',
                    ],
                    [
                        'search' => 'Saint Lucia',
                        'replace' => 'LC',
                    ],
                    [
                        'search' => 'Saint Martin',
                        'replace' => 'MF',
                    ],
                    [
                        'search' => 'Saint Pierre and Miquelon',
                        'replace' => 'PM',
                    ],
                    [
                        'search' => 'Saint Vincent and the Grenadines',
                        'replace' => 'VC',
                    ],
                    [
                        'search' => 'Samoa',
                        'replace' => 'WS',
                    ],
                    [
                        'search' => 'San Marino',
                        'replace' => 'SM',
                    ],
                    [
                        'search' => 'Sao Tome and Principe',
                        'replace' => 'ST',
                    ],
                    [
                        'search' => 'Saudi Arabia',
                        'replace' => 'SA',
                    ],
                    [
                        'search' => 'Senegal',
                        'replace' => 'SN',
                    ],
                    [
                        'search' => 'Serbia',
                        'replace' => 'RS',
                    ],
                    [
                        'search' => 'Seychelles',
                        'replace' => 'SC',
                    ],
                    [
                        'search' => 'Sierra Leone',
                        'replace' => 'SL',
                    ],
                    [
                        'search' => 'Singapore',
                        'replace' => 'SG',
                    ],
                    [
                        'search' => 'Sint Maarten',
                        'replace' => 'SX',
                    ],
                    [
                        'search' => 'Slovakia',
                        'replace' => 'SK',
                    ],
                    [
                        'search' => 'Slovenia',
                        'replace' => 'SI',
                    ],
                    [
                        'search' => 'Solomon Islands',
                        'replace' => 'SB',
                    ],
                    [
                        'search' => 'Somalia',
                        'replace' => 'SO',
                    ],
                    [
                        'search' => 'South Africa',
                        'replace' => 'ZA',
                    ],
                    [
                        'search' => 'South Georgia and the South Sandwich Islands',
                        'replace' => 'GS',
                    ],
                    [
                        'search' => 'South Sudan',
                        'replace' => 'SS',
                    ],
                    [
                        'search' => 'Spain',
                        'replace' => 'ES',
                    ],
                    [
                        'search' => 'Sri Lanka',
                        'replace' => 'LK',
                    ],
                    [
                        'search' => 'Sudan',
                        'replace' => 'SD',
                    ],
                    [
                        'search' => 'Suriname',
                        'replace' => 'SR',
                    ],
                    [
                        'search' => 'Svalbard and Jan Mayen',
                        'replace' => 'SJ',
                    ],
                    [
                        'search' => 'Sweden',
                        'replace' => 'SE',
                    ],
                    [
                        'search' => 'Switzerland',
                        'replace' => 'CH',
                    ],
                    [
                        'search' => 'Syria Arab Republic',
                        'replace' => 'SY',
                    ],
                    [
                        'search' => 'Taiwan',
                        'replace' => 'TW',
                    ],
                    [
                        'search' => 'Tajikistan',
                        'replace' => 'TJ',
                    ],
                    [
                        'search' => 'Tanzania, the United Republic of',
                        'replace' => 'TZ',
                    ],
                    [
                        'search' => 'Thailand',
                        'replace' => 'TH',
                    ],
                    [
                        'search' => 'Timor-Leste',
                        'replace' => 'TL',
                    ],
                    [
                        'search' => 'Togo',
                        'replace' => 'TG',
                    ],
                    [
                        'search' => 'Tokelau',
                        'replace' => 'TK',
                    ],
                    [
                        'search' => 'Tonga',
                        'replace' => 'TO',
                    ],
                    [
                        'search' => 'Trinidad and Tobago',
                        'replace' => 'TT',
                    ],
                    [
                        'search' => 'Tunisia',
                        'replace' => 'TN',
                    ],
                    [
                        'search' => 'Turkmenistan',
                        'replace' => 'TM',
                    ],
                    [
                        'search' => 'Turks and Caicos Islands',
                        'replace' => 'TC',
                    ],
                    [
                        'search' => 'Tuvalu',
                        'replace' => 'TV',
                    ],
                    [
                        'search' => 'Türkiye',
                        'replace' => 'TR',
                    ],
                    [
                        'search' => 'US Minor Outlying Islands',
                        'replace' => 'UM',
                    ],
                    [
                        'search' => 'Uganda',
                        'replace' => 'UG',
                    ],
                    [
                        'search' => 'Ukraine',
                        'replace' => 'UA',
                    ],
                    [
                        'search' => 'United Arab Emirates',
                        'replace' => 'AE',
                    ],
                    [
                        'search' => 'United Kingdom',
                        'replace' => 'GB',
                    ],
                    [
                        'search' => 'United States',
                        'replace' => 'US',
                    ],
                    [
                        'search' => 'Uruguay',
                        'replace' => 'UY',
                    ],
                    [
                        'search' => 'Uzbekistan',
                        'replace' => 'UZ',
                    ],
                    [
                        'search' => 'Vanuatu',
                        'replace' => 'VU',
                    ],
                    [
                        'search' => 'Venezuela',
                        'replace' => 'VE',
                    ],
                    [
                        'search' => 'Viet Nam',
                        'replace' => 'VN',
                    ],
                    [
                        'search' => 'Virgin Islands, British',
                        'replace' => 'VG',
                    ],
                    [
                        'search' => 'Virgin Islands, U.S.',
                        'replace' => 'VI',
                    ],
                    [
                        'search' => 'Wallis and Futuna',
                        'replace' => 'WF',
                    ],
                    [
                        'search' => 'Western Sahara',
                        'replace' => 'EH',
                    ],
                    [
                        'search' => 'Yemen',
                        'replace' => 'YE',
                    ],
                    [
                        'search' => 'Zambia',
                        'replace' => 'ZM',
                    ],
                    [
                        'search' => 'Zimbabwe',
                        'replace' => 'ZW',
                    ],
                    [
                        'search' => 'Åland Islands',
                        'replace' => 'AX',
                    ],
                ],
            ],
        ],

        // Default Province
        'defaultProvince' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'default' => [
                        'addressType' => [
                            'canadian' => 'state_province_region_canadian_default',
                            'international' => null,
                            'us' => null,
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'Alberta',
                        'replace' => 'AB',
                    ],
                    [
                        'search' => 'British Columbia',
                        'replace' => 'BC',
                    ],
                    [
                        'search' => 'Manitoba',
                        'replace' => 'MB',
                    ],
                    [
                        'search' => 'New Brunswick',
                        'replace' => 'NB',
                    ],
                    [
                        'search' => 'Newfoundland and Labrador',
                        'replace' => 'NL',
                    ],
                    [
                        'search' => 'Northwest Territories',
                        'replace' => 'NT',
                    ],
                    [
                        'search' => 'Nova Scotia',
                        'replace' => 'NS',
                    ],
                    [
                        'search' => 'Nunavut',
                        'replace' => 'NU',
                    ],
                    [
                        'search' => 'Ontario',
                        'replace' => 'ON',
                    ],
                    [
                        'search' => 'Prince Edward Island',
                        'replace' => 'PE',
                    ],
                    [
                        'search' => 'Quebec',
                        'replace' => 'QC',
                    ],
                    [
                        'search' => 'Saskatchewan',
                        'replace' => 'SK',
                    ],
                    [
                        'search' => 'Yukon',
                        'replace' => 'YT',
                    ],
                ],
            ],
        ],

        // Default State
        'defaultState' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'default' => [
                        'addressType' => [
                            'canadian' => 'state_province_region_canadian_default',
                            'international' => null,
                            'us' => 'state_province_region_american_default',
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'based_on_other_attribute_value' => [
                    'default' => [
                        'addressType' => [
                            'canadian' => [
                                [
                                    'search' => 'Alberta',
                                    'replace' => 'AB',
                                ],
                                [
                                    'search' => 'British Columbia',
                                    'replace' => 'BC',
                                ],
                                [
                                    'search' => 'Manitoba',
                                    'replace' => 'MB',
                                ],
                                [
                                    'search' => 'New Brunswick',
                                    'replace' => 'NB',
                                ],
                                [
                                    'search' => 'Newfoundland and Labrador',
                                    'replace' => 'NL',
                                ],
                                [
                                    'search' => 'Northwest Territories',
                                    'replace' => 'NT',
                                ],
                                [
                                    'search' => 'Nova Scotia',
                                    'replace' => 'NS',
                                ],
                                [
                                    'search' => 'Nunavut',
                                    'replace' => 'NU',
                                ],
                                [
                                    'search' => 'Ontario',
                                    'replace' => 'ON',
                                ],
                                [
                                    'search' => 'Prince Edward Island',
                                    'replace' => 'PE',
                                ],
                                [
                                    'search' => 'Quebec',
                                    'replace' => 'QC',
                                ],
                                [
                                    'search' => 'Saskatchewan',
                                    'replace' => 'SK',
                                ],
                                [
                                    'search' => 'Yukon',
                                    'replace' => 'YT',
                                ],
                            ],
                            'us' => [
                                [
                                    'search' => 'Alabama',
                                    'replace' => 'AL',
                                ],
                                [
                                    'search' => 'Alaska',
                                    'replace' => 'AK',
                                ],
                                [
                                    'search' => 'American Samoa',
                                    'replace' => 'AS',
                                ],
                                [
                                    'search' => 'Arizona',
                                    'replace' => 'AZ',
                                ],
                                [
                                    'search' => 'Arkansas',
                                    'replace' => 'AR',
                                ],
                                [
                                    'search' => 'California',
                                    'replace' => 'CA',
                                ],
                                [
                                    'search' => 'Colorado',
                                    'replace' => 'CO',
                                ],
                                [
                                    'search' => 'Connecticut',
                                    'replace' => 'CT',
                                ],
                                [
                                    'search' => 'Delaware',
                                    'replace' => 'DE',
                                ],
                                [
                                    'search' => 'District of Columbia',
                                    'replace' => 'DC',
                                ],
                                [
                                    'search' => 'Florida',
                                    'replace' => 'FL',
                                ],
                                [
                                    'search' => 'Georgia',
                                    'replace' => 'GA',
                                ],
                                [
                                    'search' => 'Guam',
                                    'replace' => 'GU',
                                ],
                                [
                                    'search' => 'Hawaii',
                                    'replace' => 'HI',
                                ],
                                [
                                    'search' => 'Idaho',
                                    'replace' => 'ID',
                                ],
                                [
                                    'search' => 'Illinois',
                                    'replace' => 'IL',
                                ],
                                [
                                    'search' => 'Indiana',
                                    'replace' => 'IN',
                                ],
                                [
                                    'search' => 'Iowa',
                                    'replace' => 'IA',
                                ],
                                [
                                    'search' => 'Kansas',
                                    'replace' => 'KS',
                                ],
                                [
                                    'search' => 'Kentucky',
                                    'replace' => 'KY',
                                ],
                                [
                                    'search' => 'Louisiana',
                                    'replace' => 'LA',
                                ],
                                [
                                    'search' => 'Maine',
                                    'replace' => 'ME',
                                ],
                                [
                                    'search' => 'Maryland',
                                    'replace' => 'MD',
                                ],
                                [
                                    'search' => 'Massachusetts',
                                    'replace' => 'MA',
                                ],
                                [
                                    'search' => 'Michigan',
                                    'replace' => 'MI',
                                ],
                                [
                                    'search' => 'Minnesota',
                                    'replace' => 'MN',
                                ],
                                [
                                    'search' => 'Mississippi',
                                    'replace' => 'MS',
                                ],
                                [
                                    'search' => 'Missouri',
                                    'replace' => 'MO',
                                ],
                                [
                                    'search' => 'Montana',
                                    'replace' => 'MT',
                                ],
                                [
                                    'search' => 'Nebraska',
                                    'replace' => 'NE',
                                ],
                                [
                                    'search' => 'Nevada',
                                    'replace' => 'NV',
                                ],
                                [
                                    'search' => 'New Hampshire',
                                    'replace' => 'NH',
                                ],
                                [
                                    'search' => 'New Jersey',
                                    'replace' => 'NJ',
                                ],
                                [
                                    'search' => 'New Mexico',
                                    'replace' => 'NM',
                                ],
                                [
                                    'search' => 'New York',
                                    'replace' => 'NY',
                                ],
                                [
                                    'search' => 'North Carolina',
                                    'replace' => 'NC',
                                ],
                                [
                                    'search' => 'North Dakota',
                                    'replace' => 'ND',
                                ],
                                [
                                    'search' => 'Northern Mariana Islands',
                                    'replace' => 'MP',
                                ],
                                [
                                    'search' => 'Ohio',
                                    'replace' => 'OH',
                                ],
                                [
                                    'search' => 'Oklahoma',
                                    'replace' => 'OK',
                                ],
                                [
                                    'search' => 'Oregon',
                                    'replace' => 'OR',
                                ],
                                [
                                    'search' => 'Pennsylvania',
                                    'replace' => 'PA',
                                ],
                                [
                                    'search' => 'Puerto Rico',
                                    'replace' => 'PR',
                                ],
                                [
                                    'search' => 'Rhode Island',
                                    'replace' => 'RI',
                                ],
                                [
                                    'search' => 'South Carolina',
                                    'replace' => 'SC',
                                ],
                                [
                                    'search' => 'South Dakota',
                                    'replace' => 'SD',
                                ],
                                [
                                    'search' => 'Tennessee',
                                    'replace' => 'TN',
                                ],
                                [
                                    'search' => 'Texas',
                                    'replace' => 'TX',
                                ],
                                [
                                    'search' => 'Utah',
                                    'replace' => 'UT',
                                ],
                                [
                                    'search' => 'U.S. Virgin Islands',
                                    'replace' => 'VI',
                                ],
                                [
                                    'search' => 'Vermont',
                                    'replace' => 'VT',
                                ],
                                [
                                    'search' => 'Virginia',
                                    'replace' => 'VA',
                                ],
                                [
                                    'search' => 'Washington',
                                    'replace' => 'WA',
                                ],
                                [
                                    'search' => 'West Virginia',
                                    'replace' => 'WV',
                                ],
                                [
                                    'search' => 'Wisconsin',
                                    'replace' => 'WI',
                                ],
                                [
                                    'search' => 'Wyoming',
                                    'replace' => 'WY',
                                ],
                                [
                                    'search' => 'Armed Forces Americas',
                                    'replace' => null,
                                ],
                                [
                                    'search' => 'Armed Forces Europe',
                                    'replace' => null,
                                ],
                                [
                                    'search' => 'Armed Forces Pacific',
                                    'replace' => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // deleteIconUrl
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'deleteIconUrl' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Consent Agreement Text Placeholder (`consent` fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `descriptionPlaceholder` attribute is a string value. It acts
        // as instructions for the field in the form editor.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'descriptionPlaceholder' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Disable default margins
        // --------------------------------------------------------------------------------
        // The Gravity Forms `disableMargins` attribute is a boolean value.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'disableMargins' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // disableQuantity
        // --------------------------------------------------------------------------------
        // The Gravity Forms `disableQuantity` attribute is a boolean value. The
        // attribute is available when the "Single Product" type is selected. When
        // true, it disables the quantity subfield for the product field.
        // --------------------------------------------------------------------------------
        'disableQuantity' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'product' => 'disable_quantity',
                ],
                'based_on_attribute_value' => [
                    'specific' => [
                        'product' => [
                            'false' => null,
                            0 => null,
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'specific' => [
                    'product' => [
                        [
                            'search' => 'true',
                            'replace' => true,
                        ],
                        [
                            'search' => '1',
                            'replace' => true,
                        ],
                        [
                            'search' => 1,
                            'replace' => true,
                        ],
                    ],
                ],
            ],
        ],

        // Choice column flow
        // --------------------------------------------------------------------------------
        // Never reported (wordpress-importer-archive#1163). Gravity Forms core (`form_display.php`) reads it only while
        // `enableDisplayInColumns` is set and `displayColumns` is above 1, so the manual work
        // it describes is already named by `displayColumns`, which does report.
        // --------------------------------------------------------------------------------
        'displayAlignment' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported' => false,
        ],

        // Category: All Categories
        'displayAllCategories' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // displayAlt
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'displayAlt' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // displayCaption
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'displayCaption' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Choice columns
        // --------------------------------------------------------------------------------
        // Reported unless it is "1". Gravity Forms core (`form_display.php`) lays checkbox and
        // radio choices out in 2 to 5 columns from this value and treats "1" as no columns;
        // a multi-column choice layout has no import target, so it is manual work (wordpress-importer-archive#1163).
        // --------------------------------------------------------------------------------
        'displayColumns' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => '1',
        ],

        // displayDescription
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'displayDescription' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // displayOnly property
        // --------------------------------------------------------------------------------
        // The Gravity Forms `displayOnly` attribute is a boolean value. When true,
        // it indicates the field is only displayed and its contents are not submitted
        // with the form/saved with the entry.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'displayOnly' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // displayTitle
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'displayTitle' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Enable Email Confirmation
        // --------------------------------------------------------------------------------
        // The Gravity Forms `emailConfirmEnabled` attribute is a boolean value.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'emailConfirmEnabled' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Enable Autocomplete
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableAutocomplete` attribute is a boolean value. When true,
        // it enables the autocomplete for the field, displaying the
        // `autocompleteAttribute` field.
        //
        // There is no analog to this in Statamic, but we can still use the
        // `autocompleteAttribute` attribute to set the autocomplete for the field.
        // --------------------------------------------------------------------------------
        'enableAutocomplete' => [
            'statamic_attribute' => [
                'default' => 'autocomplete_query',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                        '#empty' => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => 'on',
                    ],
                    [
                        'search' => true,
                        'replace' => 'on',
                    ],
                    [
                        'search' => '1',
                        'replace' => 'on',
                    ],
                    [
                        'search' => 1,
                        'replace' => 'on',
                    ],
                ],
            ],
            'exclude_fieldtypes' => [
                'fileupload',
                'rcwdupload',
            ],
        ],

        // Enable Calculation
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableCalculation` attribute is a boolean value. When true,
        // it enables the calculation for the field.
        // --------------------------------------------------------------------------------
        'enableCalculation' => [
            'statamic_attribute' => [
                'default' => 'calculation_query',
                'based_on_other_attribute_value' => [
                    'default' => [
                        'calculationFormula' => [
                            '#empty' => null,
                        ],
                    ],
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        '#empty' => null,
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Choices: Show Values (`checkboxes` fieldtype, et al.)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableChoiceValue` attribute is a boolean value. When true,
        // it makes the "Value" field visible for each of the "Choices" rows.
        //
        // There is no analog to this in Statamic, as the "Value" field is always visible.
        // However, `enableChoiceValue` is used to determine whether to map the "Value"
        // field to the `Value` attribute in Statamic.
        // --------------------------------------------------------------------------------
        'enableChoiceValue' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Enable multiple columns
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableColumns` attribute is a boolean value. When true,
        // it allows the user to select multiple columns for the field.
        //
        // There is no analog to this in Statamic.
        //
        // If this attribute is set to true, we will need to change the Statamic fieldtype
        // to something like the `grid` fieldtype.
        // --------------------------------------------------------------------------------
        'enableColumns' => [
            'statamic_attribute' => [
                'default' => 'mode',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => 'stacked',
                    ],
                    [
                        'search' => true,
                        'replace' => 'stacked',
                    ],
                    [
                        'search' => '1',
                        'replace' => 'stacked',
                    ],
                    [
                        'search' => 1,
                        'replace' => 'stacked',
                    ],
                ],
            ],
        ],

        // enableCopyValuesOption
        'enableCopyValuesOption' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Choice columns enabled
        // --------------------------------------------------------------------------------
        // Never reported (wordpress-importer-archive#1163). It gates `displayColumns`, and Gravity Forms core renders no
        // columns while `displayColumns` is "1" or absent, so the layout it enables is
        // reported through `displayColumns` alone. True on all 38 fields that carry it.
        // --------------------------------------------------------------------------------
        'enableDisplayInColumns' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported' => false,
        ],

        // Enable enhanced user interface
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableEnhancedUI` attribute is a boolean value. When true,
        // it enables the enhanced user interface for the field.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'enableEnhancedUI' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Enable "other" choice
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableOtherChoice` attribute is a boolean value. When true,
        // it enables the "other" choice for the field.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'enableOtherChoice' => [
            'statamic_attribute' => [
                'default' => 'enable_other',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Enable Password Input
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enablePasswordInput` attribute is a boolean value. When true,
        // it enables the password input for the field.
        //
        // There is no analog to this in Statamic.
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'enablePasswordInput' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // enablePrice
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enablePrice` attribute is a boolean value. The attribute is
        // associated with the "Product" fieldtype, but does not appear to be exposed as
        // a configuration option in the UI. It seems to be automatically set as `true`
        // when the `inputType` is set to a value other than `singleproduct`.
        // --------------------------------------------------------------------------------
        'enablePrice' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Randomize Order of Options
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableRandomizeChoices` attribute is a boolean value. When
        // true, it randomizes the order of the options for the field.
        // --------------------------------------------------------------------------------
        'enableRandomizeChoices' => [
            'statamic_attribute' => [
                'default' => 'randomize_order',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Enable Select All (`checkboxes` fieldtype, et al.)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `enableSelectAll` attribute is a boolean value. When true,
        // it adds a "Select All" button to the field.
        //
        // There is no analog to this in Statamic, as the "Select All" button is not
        // supported.
        // --------------------------------------------------------------------------------
        'enableSelectAll' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // errors
        'errors' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // form_id
        'form_id' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // gfurclass (`rcwdupload` fieldtype)
        // --------------------------------------------------------------------------------
        // Deliberately left reporting at every value: classified as unknown (wordpress-importer-archive#1163).
        // "GFUserData" on all 10 `rcwdupload` fields measured, on one site.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gfurclass' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
        ],

        // gfurd (`rcwdupload` fieldtype)
        // --------------------------------------------------------------------------------
        // Deliberately left reporting at every value: classified as unknown (wordpress-importer-archive#1163).
        // "old" on all 10 `rcwdupload` fields measured, on one site.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gfurd' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
        ],

        // Gravity Perks: Date Time Calculator: unit
        // --------------------------------------------------------------------------------
        // Reported unless false. False on all 228 fields measured, on one site. A unit makes a
        // date calculation, which has no import target.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gp-date-time-calculator_unit' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Days of the Week
        // --------------------------------------------------------------------------------
        // Specify the days of the week for which dates should be selectable.
        //
        // The value is an array of integers representing the days of the week.
        //
        // The integers can be one of the following:
        //   - 0 (Sunday)
        //   - 1 (Monday)
        //   - 2 (Tuesday)
        //   - 3 (Wednesday)
        //   - 4 (Thursday)
        //   - 5 (Friday)
        //   - 6 (Saturday)
        //
        // Examples:
        //   - [1, 2, 3, 4, 5] (Monday through Friday)
        //   - [0, 6] (Sunday and Saturday)
        //
        // Fieldtypes:
        //   - date
        // --------------------------------------------------------------------------------
        'gpLimitDatesdaysOfWeek' => [
            'statamic_attribute' => [
                'default' => 'days_of_week',
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => [
                            '0',
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                            '6',
                        ],
                        'replace' => null,
                    ],
                    [
                        'search' => [
                            0,
                            1,
                            2,
                            3,
                            4,
                            5,
                            6,
                        ],
                        'replace' => null,
                    ],
                ],
            ],
            'value_transformations' => [
                'based_on_attribute_value' => [
                    'default' => [
                        '#not_empty' => [
                            'as_numeric_string' => true,
                        ],
                    ],
                ],
            ],
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Exceptions
        // --------------------------------------------------------------------------------
        // Specify any dates that should be excepted from the above rules.
        //
        // The value is an array of strings representing the dates.
        //
        // The dates can be in the following formats:
        //   - "MM/DD/YYYY"
        //
        // Examples:
        //   - ["01/02/2023", "01/16/2023", "02/20/2023", "05/29/2023"]
        //
        // Fieldtypes:
        //   - date
        //
        // The most recent date in active UAMS forms is "12/25/2023", so it would appear
        // that this attribute is not currently in active use.
        // --------------------------------------------------------------------------------
        'gpLimitDatesexceptions' => [
            'statamic_attribute' => [
                'default' => 'date_exceptions',
            ],
            'value_transformations' => [
                'based_on_attribute_value' => [
                    'default' => [
                        '#not_empty' => [
                            'as_date_iso_8601' => true,
                            'as_complex_array' => [
                                'id' => '[#field_handle]_date_exceptions_[#count]',
                                'item' => '[#value]',
                                'type' => 'single_date',
                                'enabled' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Enable Inline Date Picker
        // --------------------------------------------------------------------------------
        // Display a full calendar in place of an input.
        //
        // Fieldtypes:
        //   - date
        // --------------------------------------------------------------------------------
        'gpLimitDatesinlineDatepicker' => [
            'statamic_attribute' => [
                'default' => 'inline',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Maximum Date
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gpLimitDatesmaxDate` attribute is an enumerated string
        // value. It contains the maximum date for the field.
        //
        // The value can be one of the following:
        //   - `_custom_` (Set Specific Date)
        //   - `{today}` (Current Date)
        //   - An integer value representing the ID value of another date field in the
        //     form. // This is not used in any active UAMS forms.
        //
        // Selecting `_custom_` will display a unknown date picker field.
        //
        // Selecting `{today}` will display the `gpLimitDatesmaxDateMod` field.
        //
        // Fieldtypes:
        //   - date
        // --------------------------------------------------------------------------------
        'gpLimitDatesmaxDate' => [
            'statamic_attribute' => [
                'default' => 'latest_date_type',
            ],
            'value_translation' => [
                'default' => [
                    // [
                    //     'search' => '_custom_',
                    //     'replace' => 'custom',
                    // ],
                    // [
                    //     'search' => '{today}',
                    //     'replace' => 'today',
                    // ],
                    // [
                    //     'search' => '#not_empty',
                    //     'replace' => 'field',
                    // ],
                    [
                        'search' => '#not_empty',
                        'replace' => null,
                    ],
                ],
            ],
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Maximum Date: Modifier
        // --------------------------------------------------------------------------------
        // This configuration field is displayed when the `gpLimitDatesmaxDate` attribute
        // is set to `{today}`.
        //
        // Modify the selected date by adding or subracting days, weeks, months or years.
        //
        // Examples:
        //   - "+1 day"
        //   - "-2 weeks"
        //   - "+3 years"
        //
        // Value type: string
        //
        // Fieldtypes:
        //   - date
        //
        // This attribute is not used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'gpLimitDatesmaxDateMod' => [
            'statamic_attribute' => [
                'default' => 'latest_date_offset',
            ],

        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Minimum Date
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gpLimitDatesminDate` attribute is an enumerated string
        // value. It contains the minimum date for the field.
        //
        // The value can be one of the following:
        //   - `_custom_` (Set Specific Date)
        //   - `{today}` (Current Date)
        //   - An integer value representing the ID value of another date field in the
        //     form. // This is not used in any active UAMS forms.
        //
        // Selecting `_custom_` will display a unknown date picker field.
        //
        // Selecting `{today}` will display the `gpLimitDatesminDateMod` field.
        //
        // Fieldtypes:
        //   - date
        // --------------------------------------------------------------------------------
        'gpLimitDatesminDate' => [
            'statamic_attribute' => [
                'default' => 'earliest_date_type',
            ],
            'value_translation' => [
                'default' => [
                    // [
                    //     'search' => '_custom_',
                    //     'replace' => 'custom',
                    // ],
                    // [
                    //     'search' => '{today}',
                    //     'replace' => 'today',
                    // ],
                    // [
                    //     'search' => '#not_empty',
                    //     'replace' => 'field',
                    // ],
                    [
                        'search' => '#not_empty',
                        'replace' => 'today',
                    ],
                ],
            ],
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Minimum Date: Modifier
        // --------------------------------------------------------------------------------
        // This configuration field is displayed when the `gpLimitDatesminDate` attribute
        // is set to `{today}`.
        //
        // Modify the selected date by adding or subracting days, weeks, months or years.
        //
        // Examples:
        //   - "+1 day"
        //   - "-2 weeks"
        //   - "+3 years"
        //
        // Value type: string
        //
        // Fieldtypes:
        //   - date
        // --------------------------------------------------------------------------------
        'gpLimitDatesminDateMod' => [
            'statamic_attribute' => [
                'default' => 'earliest_date_offset',
            ],
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Exclude Dates Before Current Date
        // --------------------------------------------------------------------------------
        // This configuration field is displayed when the `gpLimitDatesminDate` attribute
        // is set to `{today}`.
        //
        // Value type: boolean
        //
        // Fieldtypes:
        //   - date
        // --------------------------------------------------------------------------------
        'gpLimitDatesminDateExcludeBeforeToday' => [
            'statamic_attribute' => [
                'default' => 'earliest_date_exclude_before_today',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
        ],

        // Gravity Wiz: Gravity Forms Limit Dates: Exclude Dates Before Current Date: Current Date Modifier
        // --------------------------------------------------------------------------------
        // This configuration field is displayed when the
        // `gpLimitDatesminDateExcludeBeforeToday` attribute is set to `true`.
        //
        // Modify the selected date by adding or subracting days, weeks, months or years.
        //
        // Examples:
        //   - "+1 day"
        //   - "-2 weeks"
        //   - "+3 years"
        //
        // Value type: string
        //
        // Fieldtypes:
        //   - date
        // --------------------------------------------------------------------------------
        'gpLimitDatesminDateExcludeBeforeTodayMod' => [
            'statamic_attribute' => [
                'default' => 'earliest_date_today_offset',
            ],
            'value_transformations' => [
                'based_on_attribute_value' => [
                    'default' => [
                        '+6 weeks' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 6,
                                    'unit' => 'week',
                                ],
                            ],
                        ],
                        '+2 weeks' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 2,
                                    'unit' => 'week',
                                ],
                            ],
                        ],
                        '+14 days' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 14,
                                    'unit' => 'day',
                                ],
                            ],
                        ],
                        '+7 days' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 7,
                                    'unit' => 'day',
                                ],
                            ],
                        ],
                        '+5 days' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 10,
                                    'unit' => 'day',
                                ],
                            ],
                        ],
                        '+3 days' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 3,
                                    'unit' => 'day',
                                ],
                            ],
                        ],
                        '+10 days' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 10,
                                    'unit' => 'day',
                                ],
                            ],
                        ],
                        '+10 weeks' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 10,
                                    'unit' => 'week',
                                ],
                            ],
                        ],
                        '+3 weeks' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 3,
                                    'unit' => 'week',
                                ],
                            ],
                        ],
                        '+8' => [
                            'as_complex_array' => [
                                [
                                    'id' => '[#field_handle]_earliest_date_today_offset_[#count]',
                                    'operator' => 'add',
                                    'number' => 8,
                                    'unit' => 'day',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // Gravity Wiz: Gravity Forms Nested Forms: Entry Labels: Plural
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // Examples:
        //   - "Courses"
        //
        // Fieldtypes:
        //   - form
        //
        // Instances of this in active UAMS forms:
        //   - https://registrar.uams.edu/
        //       - "Transfer Credit Applied" [ID: 2]
        //           - "Course Info"
        // --------------------------------------------------------------------------------
        'gpnfEntryLabelPlural' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Gravity Wiz: Gravity Forms Nested Forms: Entry Labels: Singular
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // Examples:
        //   - "Course"
        //
        // Fieldtypes:
        //   - form
        //
        // Instances of this in active UAMS forms:
        //   - https://registrar.uams.edu/
        //       - "Transfer Credit Applied" [ID: 2]
        //           - "Course Info"
        // --------------------------------------------------------------------------------
        'gpnfEntryLabelSingular' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Gravity Wiz: Gravity Forms Nested Forms: Summary Fields
        // --------------------------------------------------------------------------------
        // Select which fields from the nested entry will display in table on the current
        // form. This does not affect which fields will appear in the modal.
        //
        // Value type: array of integer strings
        //
        // Examples:
        //   - ["1", "2", "3"]
        //
        // Fieldtypes:
        //   - form
        //
        // Instances of this in active UAMS forms:
        //   - https://registrar.uams.edu/
        //       - "Transfer Credit Applied" [ID: 2]
        //           - "Course Info"
        // --------------------------------------------------------------------------------
        'gpnfFields' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Gravity Wiz: Gravity Forms Nested Forms: Nested Form: ID
        // --------------------------------------------------------------------------------
        // Select the form that should be used to create nested entries for this form.
        //
        // Value type: integer string
        //
        // Examples:
        //   - "7"
        //
        // Fieldtypes:
        //   - form
        //
        // Instances of this in active UAMS forms:
        //   - https://registrar.uams.edu/
        //       - "Transfer Credit Applied" [ID: 2]
        //           - "Course Info"
        // --------------------------------------------------------------------------------
        'gpnfForm' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Gravity Wiz: Gravity Forms Nested Forms: Nested Form: Title
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // Examples:
        //   - "Transfer Credit Applied Nested Form"
        //
        // Fieldtypes:
        //   - form
        //
        // It doesn't appear that this attribute's value has any mechanical function in
        // Gravity Forms. The ID from `gpnfForm` is used to determine which form to use as
        // the nested form.
        // --------------------------------------------------------------------------------
        'gpnfFormTitle' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Gravity Perks: Nested Forms: modal header color
        // --------------------------------------------------------------------------------
        // Never reported (wordpress-importer-archive#1163). A presentation detail of the nested-form modal; whether the
        // nested form itself imports is reported separately, and a header color is not work
        // an operator carries across.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gpnfModalHeaderColor' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported' => false,
        ],

        // Gravity Wiz: Populate Anything: dynamic choices
        // --------------------------------------------------------------------------------
        // Reported unless false. Set true, choices are populated from a query at render time,
        // which has no import target. False on both fields measured.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gppa-choices-enabled' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Gravity Wiz: Populate Anything: dynamic value
        // --------------------------------------------------------------------------------
        // Reported unless false. Set true, the value is populated from a query at render time,
        // which has no import target. False on all 5 fields measured.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gppa-values-enabled' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Gravity Wiz: Gravity Forms Populate Anything: Object Type
        // --------------------------------------------------------------------------------
        // Value type: string (enum):
        //   - 'term'
        //   - ???
        // --------------------------------------------------------------------------------
        'gppa-values-object-type' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Gravity Wiz: Populate Anything: disable
        // --------------------------------------------------------------------------------
        // Never reported (wordpress-importer-archive#1163). It switches population off, and the population itself is
        // reported by `gppa-choices-enabled` and `gppa-values-enabled`, so neither value of
        // this flag names work of its own. False on all 73 fields measured.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gppaDisable' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported' => false,
        ],

        // Randomize order of choices
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gquizEnableRandomizeQuizChoices` attribute is a boolean
        // value. When true, it randomizes the order of the choices for the field.
        //
        // Fieldtypes:
        //   - quiz
        // --------------------------------------------------------------------------------
        'gquizEnableRandomizeQuizChoices' => [
            'statamic_attribute' => [
                'default' => 'randomize_order',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Quiz Field Type
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gquizFieldType` attribute is an enumerated string with the
        // following options:
        //   - 'radio' (Radio Buttons)
        //   - 'select' (Drop Down) // Not used in any active UAMS forms
        //   - 'checkbox' (Checkboxes) // Not used in any active UAMS forms
        //
        // The value of this attribute is also used to set the value of `inputType`.
        //
        // Fieldtypes:
        //   - quiz
        // --------------------------------------------------------------------------------
        'gquizFieldType' => [
            'statamic_attribute' => [
                'default' => 'question_fieldtype',
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'checkbox',
                        'replace' => 'checkboxes',
                    ],
                ],
            ],
        ],

        // Enable answer explanation
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gquizShowAnswerExplanation` attribute is a boolean
        // value. When true, it enables `gquizAnswerExplanation`.
        //
        // Fieldtypes:
        //   - quiz
        // --------------------------------------------------------------------------------
        'gquizShowAnswerExplanation' => [
            'statamic_attribute' => [
                'default' => 'answer_explanation_query',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Quiz answer explanation
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gquizAnswerExplanation` attribute is a string value. It
        // contains the answer explanation for the field.
        //
        // Fieldtypes:
        //   - quiz
        // --------------------------------------------------------------------------------
        'gquizAnswerExplanation' => [
            'statamic_attribute' => [
                'default' => 'answer_explanation',
            ],
            'unescape_slashes' => true,
        ],

        // Enable weighted score
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gquizWeightedScoreEnabled` attribute is a boolean value.
        // When true, it enables weighted scoring for the question and its answers.
        //
        // Fieldtypes:
        //   - quiz
        // --------------------------------------------------------------------------------
        'gquizWeightedScoreEnabled' => [
            'statamic_attribute' => [
                'default' => 'weighted_score_query',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // GravityView: entry approval
        // --------------------------------------------------------------------------------
        // Reported at any non-empty value (wordpress-importer-archive#1163): it marks the field that drives GravityView's
        // entry-approval workflow, which has no import target. "1" on the one field measured.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gravityview_approved' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
        ],

        // Survey Field Type: Likert: Enable Multiple Rows
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gsurveyLikertEnableMultipleRows` attribute is a boolean
        // value. When true, it allows the user to select multiple rows for the field
        // (which are defined in the `gsurveyLikertRows` attribute).
        // --------------------------------------------------------------------------------
        'gsurveyLikertEnableMultipleRows' => [
            'statamic_attribute' => [
                'default' => 'enable_multiple_rows',
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Survey Field Type: Likert: Enable Scoring
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gsurveyLikertEnableScoring` attribute is a boolean value.
        // --------------------------------------------------------------------------------
        'gsurveyLikertEnableScoring' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Survey Field Type: Likert: Enable Multiple Rows: Rows
        // --------------------------------------------------------------------------------
        // The Gravity Forms `gsurveyLikertRows` attribute is an array of rows. Each row
        // is an object with a `text` property and a `value` property. This configuration
        // setting is made available when the `gsurveyLikertEnableMultipleRows` attribute
        // is set to `true.
        //
        // Instances of this on active UAMS forms:
        //   - https://inside.uams.edu/clinicaleducation/
        //       - "ProQOL Tool Survey (F8)" [ID: 28]
        //           - "Required Fields" [ID: 19]
        //           - "Required Fields" [ID: 31]
        //           - "Required Fields" [ID: 32]
        //           - "Required Fields" [ID: 24]
        //           - "Required Fields" [ID: 33]
        //           - "Required Fields" [ID: 34]
        // --------------------------------------------------------------------------------
        'gsurveyLikertRows' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_other_attribute_value' => [
                    'default' => [
                        'gsurveyLikertEnableMultipleRows' => [
                            'true' => 'multiple_rows',
                            'false' => null,
                            1 => 'multiple_rows',
                            0 => null,
                        ],
                    ],
                ],
            ],
            'extract_array_value' => 'text',
        ],

        // Gravity Wiz: Limit Checkboxes: enable
        // --------------------------------------------------------------------------------
        // Reported unless false. Set true, a checkbox field enforces a minimum or maximum
        // number of checked choices, a validation rule with no import target. False on all
        // 90 fields measured, on two sites.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gwlimitcheckboxes_enable' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Gravity Wiz: Limit Checkboxes: span multiple fields
        // --------------------------------------------------------------------------------
        // Never reported (wordpress-importer-archive#1163). It qualifies the limit `gwlimitcheckboxes_enable` turns on,
        // and has no effect while that is off; when it is on, that attribute reports. False
        // on all 90 fields measured.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gwlimitcheckboxes_span_multiple_fields' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported' => false,
        ],

        // Gravity Perks: Limit Choices: enable
        // --------------------------------------------------------------------------------
        // Reported unless false. Set true, each choice can be taken a limited number of times
        // across all submissions, which has no import target. False on 13 and true on 10 of
        // 23 fields measured.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gwlimitchoices_enableLimits' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Gravity Perks: Read Only: enable
        // --------------------------------------------------------------------------------
        // Reported unless false. Set true, the field renders read-only, which an editor has to
        // recreate by hand. False on all 11 fields measured, on one site.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'gwreadonly_enable' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Hide address line 2
        // --------------------------------------------------------------------------------
        // The legacy, field-level switch that drops the second street line from an
        // `address` field. Current Gravity Forms records the same thing per input, as
        // `inputs[].isHidden`, which the `address` subfield mapping already carries to
        // `address_line_2_include`. A form saved before that carries only this attribute:
        // the one field measured with it true, on a form exported with no `version`, has
        // no `isHidden` on its line-2 input, so without this entry the line reappears on
        // import (wordpress-importer-archive#1172). False on 80 fields and empty on 50, which write nothing, so an
        // input-level `isHidden` is never overridden by a false here. Mirrors `hideCountry`.
        // --------------------------------------------------------------------------------
        'hideAddress2' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'address' => 'address_line_2_include',
                ],
                'based_on_attribute_value' => [
                    'specific' => [
                        'address' => [
                            'false' => null,
                            0 => null,
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'specific' => [
                    'address' => [
                        [
                            'search' => 'true',
                            'replace' => false,
                        ],
                        [
                            'search' => true,
                            'replace' => false,
                        ],
                        [
                            'search' => '1',
                            'replace' => false,
                        ],
                        [
                            'search' => 1,
                            'replace' => false,
                        ],
                    ],
                ],
            ],
        ],

        // hideCountry
        'hideCountry' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'address' => 'country_include',
                ],
                'based_on_attribute_value' => [
                    'specific' => [
                        'address' => [
                            'false' => null,
                            0 => null,
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'specific' => [
                    'address' => [
                        [
                            'search' => 'true',
                            'replace' => false,
                        ],
                        [
                            'search' => true,
                            'replace' => false,
                        ],
                        [
                            'search' => '1',
                            'replace' => false,
                        ],
                        [
                            'search' => 1,
                            'replace' => false,
                        ],
                    ],
                ],
            ],
        ],

        // Hide state
        // --------------------------------------------------------------------------------
        // Reported only when true. Gravity Forms core drops the state input from an `address`
        // field when set, and the host's address template has no switch for it. False on all
        // 81 fields measured (wordpress-importer-archive#1163).
        // --------------------------------------------------------------------------------
        'hideState' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => false,
        ],

        // Choice Label Visibility (`image_choice` fieldtype)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `imageChoiceLabelVisibility` attribute is an enumerated string
        // with the following options:
        // - 'show' (Visible)
        // - 'hide' (Hidden)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'imageChoiceLabelVisibility' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // imageChoices_enableImages
        'imageChoices_enableImages' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // imageChoices_entrySetting
        'imageChoices_entrySetting' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // imageChoices_showLabels
        'imageChoices_showLabels' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // imageChoices_showPrices
        'imageChoices_showPrices' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // imageChoices_useLightbox
        'imageChoices_useLightbox' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // image_markup
        'image_markup' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Input Type
        // --------------------------------------------------------------------------------
        // The `inputType` attribute is used to determine the input type for various
        // fields, including:
        //   - multi_choice
        //       - 'radio' (Radio Buttons)
        //       - 'checkbox' (Checkboxes)
        //   - poll
        //       - 'select' (Drop Down)
        //       - 'radio' (Radio Buttons)
        //       - 'checkbox' (Checkboxes)
        //   - quiz
        //       - 'select' (Drop Down)
        //       - 'radio' (Radio Buttons)
        //       - 'checkbox' (Checkboxes)
        //   - survey
        //       - 'checkbox' (Checkboxes)
        //       - 'likert'
        //       - 'radio' (Radio Buttons)
        //       - 'rank'
        //       - 'rating'
        //       - 'select' (Drop Down)
        //       - 'text' (Text)
        //       - 'textarea' (Textarea)
        //   - product // corpus counts re-measured 2026-08-03 across 309 product fields (wordpress-importer-archive#299)
        //       - 'calculation' (Calculation) // 0 occurrences. Unsupported -- warns.
        //       - 'hiddenproduct' (Hidden) // 2 occurrences. Unsupported -- warns.
        //       - 'price' (User Defined Price) // 1 occurrence. Unsupported -- warns.
        //       - 'radio' (Radio Buttons) // 7 occurrences. Maps to `multiple`.
        //       - 'select' (Drop Down) // 10 occurrences. Maps to `multiple`.
        //       - 'singleproduct' (Single Product) // 289 occurrences. Maps to `single` (the Statamic default).
        //
        // The mapping for this specific attribute is handled in the $mapping array for
        // each relevant fieldtype.
        // --------------------------------------------------------------------------------
        'inputType' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    // The Statamic `option` fieldtype presents its choices per its own
                    // `input_type` config, so the Gravity Forms value passes through
                    // verbatim (`select` / `radio` / `checkbox`).
                    'option' => 'input_type',
                    'product' => 'selection_type',
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        'calculation' => null,
                        'hiddenproduct' => null,
                        'price' => null,
                        'singleproduct' => null, // would be `'selection_type' => 'single'`, but that is the default value in Statamic
                    ],
                ],
            ],
            'value_translation' => [
                'default' => null,
                'specific' => [
                    'product' => [
                        [
                            'search' => 'radio',
                            'replace' => 'multiple',
                        ],
                        [
                            'search' => 'select',
                            'replace' => 'multiple',
                        ],
                    ],
                ],
            ],
            'statamic_other_field_value_based_on_attribute_value' => [
                'default' => [
                    'multiselect' => [
                        'multiple' => true,
                    ],
                    'fileupload' => [
                        'container' => 'form_entry_uploads',
                        'folder' => '[#site_handle]',
                        'dynamic' => 'id',
                        'restrict' => true,
                    ],
                    'rcwdupload' => [
                        'container' => 'form_entry_uploads',
                        'folder' => '[#site_handle]',
                        'dynamic' => 'id',
                        'restrict' => true,
                    ],
                ],
            ],
        ],

        // Subfields
        // --------------------------------------------------------------------------------
        // The Gravity Forms `inputs` attribute is an array of subfields.
        //
        // This is addressed in the `$subfieldMapping` array.
        // --------------------------------------------------------------------------------
        'inputs' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // layoutGridColumnSpan
        // --------------------------------------------------------------------------------
        // The Gravity Forms `layoutGridColumnSpan` attribute is an integer value
        // representing the number of columns used for the field (out of 12 columns).
        // --------------------------------------------------------------------------------
        'layoutGridColumnSpan' => [
            'statamic_attribute' => [
                'default' => 'width',
                'based_on_attribute_value' => [
                    'default' => [
                        12 => null, // 100%, but doesn't need to be explicitly defined
                        11 => null, // 91.667%
                        10 => null, // 83.333% // not used in any active UAMS forms
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 9,
                        'replace' => 75,
                    ],
                    [
                        'search' => 8,
                        'replace' => 66,
                    ],
                    [
                        'search' => 7,
                        'replace' => 50,
                    ],
                    [
                        'search' => 6,
                        'replace' => 50,
                    ],
                    [
                        'search' => 5,
                        'replace' => 50,
                    ],
                    [
                        'search' => 4,
                        'replace' => 33,
                    ],
                    [
                        'search' => 3,
                        'replace' => 25,
                    ],
                ],
            ],
        ],

        // layoutGroupId
        // --------------------------------------------------------------------------------
        // The Gravity Forms `layoutGridColumnSpan` attribute is likely a string value. It
        // likely sets the ID or label for the group of columns for the field.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'layoutGroupId' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Row spacer width
        // --------------------------------------------------------------------------------
        // Reported unless 0. Gravity Forms core (`form_display.php`) renders an empty spacer
        // spanning this many of 12 columns after the field, and 0 renders none; a gap in a
        // row has no import target (wordpress-importer-archive#1163). 0 on 140 of 168 fields measured.
        // --------------------------------------------------------------------------------
        'layoutSpacerGridColumnSpan' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
            'alert_unsupported_default' => 0,
        ],

        // Maximum File Size
        // --------------------------------------------------------------------------------
        // The Gravity Forms `maxFileSize` attribute is a string value. It sets the
        // maximum file size for the file upload field.
        // --------------------------------------------------------------------------------
        'maxFileSize' => [
            'statamic_attribute' => [
                'default' => 'max_file_size',
            ],
            'value_substitution_regex' => [
                '/^(\d+)$/' => '$1MB',
                '/^(.+)(mb)$/' => '$1MB',
                '/^(.+)(kb)$/' => '$1KB',
            ],
            'convert_file_size' => 'MB',
        ],

        // Maximum Number of Files
        // --------------------------------------------------------------------------------
        // The Gravity Forms `maxFiles` attribute is a string value. It sets the
        // maximum number of files for the file upload field.
        //
        // This attribute field appears when the `multipleFiles` attribute is true.
        // --------------------------------------------------------------------------------
        'maxFiles' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'fileupload' => 'max_files',
                    'rcwdupload' => null,
                    'post_image' => 'max_files',
                ],
                'based_on_other_attribute_value' => [
                    'default' => [
                        'inputType' => [
                            'fileupload' => 'max_files',
                            'rcwdupload' => null,
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'based_on_other_attribute_value' => [
                    'default' => [
                        // When `multipleFiles` is enabled an empty `maxFiles` means
                        // unlimited (null → no key) and a set one passes through.
                        // Expressed with the `#empty => value` map form that
                        // applyValueTranslation() resolves — the previous
                        // `[[search => '#empty', ...]]` list form was never applied,
                        // dropping max_files entirely. wordpress-importer-archive#463
                        //
                        // The DISABLED case is not handled here. This translation only
                        // runs for a `maxFiles` actually present in the field data, and
                        // its branches are keyed on 'true'/'false'/1/0, which the
                        // corpus's `''` serialization never selects. Capping a
                        // single-file upload therefore lives in
                        // FormBlueprintCreator::getMappedFieldAttributes(). wordpress-importer-archive#470
                        'multipleFiles' => [
                            'true' => ['#empty' => null],
                            'false' => ['#empty' => 1],
                            1 => ['#empty' => null],
                            0 => ['#empty' => 1],
                        ],
                    ],
                ],
            ],
            'as_integer' => true,
        ],

        // Maximum Rows
        // --------------------------------------------------------------------------------
        // The Gravity Forms `maxRows` attribute is a string value. It sets the maximum
        // number of items for the `list` fieldtype.
        // --------------------------------------------------------------------------------
        'maxRows' => [
            'statamic_attribute' => [
                'default' => 'max_items',
                'based_on_other_attribute_value' => [
                    'default' => [
                        'enableColumns' => [
                            'true' => 'max_rows',
                            'false' => 'max_items',
                            1 => 'max_rows',
                            0 => 'max_items',
                        ],
                    ],
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        0 => null,
                    ],
                ],
            ],
        ],

        // Minimum Password Strength
        // --------------------------------------------------------------------------------
        // The Gravity Forms `minPasswordStrength` attribute (User Registration Add-On) is
        // an enumerated string naming the minimum acceptable Gravity Forms strength tier:
        // - 'short'
        // - 'bad'
        // - 'good'
        // - 'strong'
        //
        // It is gated by `passwordStrengthEnabled` and, together, the two enforce a
        // minimum password strength at signup. Statamic's form/validation layer has no
        // native password-strength-meter equivalent, so this setting is intentionally
        // dropped (see wordpress-importer-archive#315). Mirrors the `numberFormat` documented-drop precedent.
        // --------------------------------------------------------------------------------
        'minPasswordStrength' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // multiSelectSize
        'multiSelectSize' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Enable Multi-File Upload
        // --------------------------------------------------------------------------------
        // The Gravity Forms `multipleFiles` attribute is a boolean value. When true,
        // it allows the user to upload multiple files.
        //
        // This mapping is handled by the mapping for the `maxFiles` attribute.
        // --------------------------------------------------------------------------------
        'multipleFiles' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Name Format
        // --------------------------------------------------------------------------------
        // The Gravity Forms `nameFormat` attribute is an enumerated string with the
        // following options:
        // - 'extended' (Extended)
        // - 'advanced' (Advanced)
        //
        // This configuration field does not appear to be exposed in the Gravity Forms UI.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'nameFormat' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Next Button
        // --------------------------------------------------------------------------------
        // The Gravity Forms `nextButton` attribute stores an array containing:
        // - 'type' (string enum['text', 'image'])
        // - 'text' (string)
        // - 'imageUrl' (string)
        // - 'id' (int)
        // --------------------------------------------------------------------------------
        'nextButton' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Number Format
        // --------------------------------------------------------------------------------
        // The Gravity Forms `numberFormat` attribute is an enumerated string with the
        // following options:
        // - 'decimal_dot' (9,999.99)
        // - 'decimal_comma' (9.999,99)
        // - 'currency' (Currency)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'numberFormat' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Password Strength Enabled
        // --------------------------------------------------------------------------------
        // The Gravity Forms `passwordStrengthEnabled` attribute (User Registration
        // Add-On) is a boolean. When true (paired with `minPasswordStrength`), the
        // password field enforces a minimum password-strength tier at signup.
        //
        // Statamic's form/validation layer has no native password-strength-meter
        // equivalent, so this setting is intentionally dropped (see wordpress-importer-archive#315). Mirrors the
        // `numberFormat` documented-drop precedent.
        // --------------------------------------------------------------------------------
        'passwordStrengthEnabled' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // passwordVisibilityEnabled
        'passwordVisibilityEnabled' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Phone Format
        // --------------------------------------------------------------------------------
        // The Gravity Forms `phoneFormat` attribute is an enumerated string with the
        // following options:
        // - 'standard' ((###) ###-####)
        // - 'international' (International)
        // --------------------------------------------------------------------------------
        'phoneFormat' => [
            'statamic_attribute' => [
                'default' => 'preset',
                'based_on_attribute_value' => [
                    'default' => [
                        'standard' => null, // Statamic `phone` fieldtype's default value is 'tel-national'
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'international',
                        'replace' => 'tel',
                    ],
                ],
            ],
        ],

        // Placeholder
        'placeholder' => [
            'statamic_attribute' => [
                'default' => 'placeholder',
                'based_on_attribute_value' => [
                    'default' => [
                        'http://' => null,
                        'http:\/\/' => null,
                        'https://' => null,
                        'https:\/\/' => null,
                    ],
                ],
            ],
            'unescape_slashes' => true,
        ],

        // Poll Field Type
        // --------------------------------------------------------------------------------
        // The Gravity Forms `poll_field_type` attribute duplicates the `inputType`
        // attribute.
        // --------------------------------------------------------------------------------
        'poll_field_type' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // postCustomFieldName
        'postCustomFieldName' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'unescape_slashes' => true,
        ],

        // postFeaturedImage
        // --------------------------------------------------------------------------------
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'postFeaturedImage' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Previous Button
        // --------------------------------------------------------------------------------
        // The Gravity Forms `previousButton` attribute stores an array containing:
        // - 'type' (string enum['text', 'image'])
        // - 'text' (string)
        // - 'imageUrl' (string)
        // --------------------------------------------------------------------------------
        'previousButton' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Range: Max
        // --------------------------------------------------------------------------------
        // The Gravity Forms `rangeMax` attribute is a string value. It sets the maximum
        // value for the range field.
        //
        // Statamic v6 does not support the `max` attribute on the `time` fieldtype Vue
        // component; if this key were ever present on a `time` field, do not map it.
        // --------------------------------------------------------------------------------
        'rangeMax' => [
            'statamic_attribute' => [
                'default' => 'max',
                'specific' => [
                    'time' => null,
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_transformations' => [
                'default' => [
                    'as_numeric_string' => true,
                ],
            ],
        ],

        // Range: Min
        // --------------------------------------------------------------------------------
        // The Gravity Forms `rangeMin` attribute is a string value. It sets the minimum
        // value for the range field.
        //
        // Statamic v6 does not support the `min` attribute on the `time` fieldtype Vue
        // component; if this key were ever present on a `time` field, do not map it.
        // --------------------------------------------------------------------------------
        'rangeMin' => [
            'statamic_attribute' => [
                'default' => 'min',
                'specific' => [
                    'time' => null,
                ],
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_transformations' => [
                'default' => [
                    'as_numeric_string' => true,
                ],
            ],
        ],

        // Upload the file after selection
        // --------------------------------------------------------------------------------
        // Files will be automatically uploaded after selection.
        //
        // Fieldtype:
        //   - rcwdupload
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rcwdupload_autoupload' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        'N' => null,
                        'n' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => 'Y',
                        'replace' => true,
                    ],
                    [
                        'search' => 'y',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Filter the files
        // --------------------------------------------------------------------------------
        // Enter the file extensions, separated by commas, to enable file uploading.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_filter' => [
            'statamic_attribute' => [
                'default' => 'file_type_restrictions',
                // 'based_on_other_attribute_value' => [
                //     'default' => [
                //         'rcwdupload_filter_compr' => [
                //             '#empty' => null,
                //             '#not_set' => null,
                //         ],
                //     ],
                // ],
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        'N' => null,
                        'n' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => true,
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => 'Y',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => 'y',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => '1',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => 1,
                        'replace' => 'mime_types',
                    ],
                ],
            ],
        ],

        // Filter the files: Compressed files
        // --------------------------------------------------------------------------------
        // Enter the file extensions, separated by commas, to enable file uploading.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_filter_compr' => [
            'statamic_attribute' => [
                'default' => 'mime_types',
                'based_on_other_attribute_value' => [
                    'default' => [
                        'rcwdupload_filter' => [
                            'N' => null,
                            'n' => null,
                            '#empty' => null,
                            '#not_set' => null,
                        ],
                    ],
                ],
            ],
            'combine_value_with_other_attribute_value' => [
                'combination_type' => 'string',
                'string_separator' => ',',
                'attribute_names' => [
                    'rcwdupload_filter_docs',
                    'rcwdupload_filter_images',
                    'rcwdupload_filter_others',
                ],
            ],
            'explode_string' => ',',
            'trim' => true,
            'trim_characters' => [
                '.',
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => '0fs',
                        'replace' => 'application/vnd.erofs',
                    ],
                    [
                        'search' => '123',
                        'replace' => 'application/vnd.lotus-1-2-3',
                    ],
                    [
                        'search' => '1905.1',
                        'replace' => 'application/vnd.ieee.1905',
                    ],
                    [
                        'search' => '1clr',
                        'replace' => 'application/clr',
                    ],
                    [
                        'search' => '1km',
                        'replace' => 'application/vnd.1000minds.decision-model+xml',
                    ],
                    [
                        'search' => '210',
                        'replace' => 'application/p21',
                    ],
                    [
                        'search' => '3dml',
                        'replace' => 'text/vnd.in3d.3dml',
                    ],
                    [
                        'search' => '3ds',
                        'replace' => 'image/x-3ds',
                    ],
                    [
                        'search' => '3g2',
                        'replace' => 'video/3gpp2',
                    ],
                    [
                        'search' => '3gp',
                        'replace' => 'video/3gpp',
                    ],
                    [
                        'search' => '3gpp',
                        'replace' => 'video/3gpp',
                    ],
                    [
                        'search' => '3mf',
                        'replace' => 'model/3mf',
                    ],
                    [
                        'search' => '3tz',
                        'replace' => 'application/vnd.maxar.archive.3tz+zip',
                    ],
                    [
                        'search' => '726',
                        'replace' => 'audio/32kadpcm',
                    ],
                    [
                        'search' => '7z',
                        'replace' => 'application/x-7z-compressed',
                    ],
                    [
                        'search' => 'a',
                        'replace' => 'text/vnd.a',
                    ],
                    [
                        'search' => 'a2l',
                        'replace' => 'application/a2l',
                    ],
                    [
                        'search' => 'aa3',
                        'replace' => 'audio/atrac3',
                    ],
                    [
                        'search' => 'aab',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'aac',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'aal',
                        'replace' => 'audio/atrac-advanced-lossless',
                    ],
                    [
                        'search' => 'aam',
                        'replace' => 'application/x-authorware-map',
                    ],
                    [
                        'search' => 'aas',
                        'replace' => 'application/x-authorware-seg',
                    ],
                    [
                        'search' => 'abc',
                        'replace' => 'text/vnd.abc',
                    ],
                    [
                        'search' => 'abw',
                        'replace' => 'application/x-abiword',
                    ],
                    [
                        'search' => 'ac',
                        'replace' => 'application/pkix-attr-cert',
                    ],
                    [
                        'search' => 'ac2',
                        'replace' => 'application/vnd.banana-accounting',
                    ],
                    [
                        'search' => 'acc',
                        'replace' => 'application/vnd.americandynamics.acc',
                    ],
                    [
                        'search' => 'ace',
                        'replace' => 'application/x-ace-compressed',
                    ],
                    [
                        'search' => 'acu',
                        'replace' => 'application/vnd.acucobol',
                    ],
                    [
                        'search' => 'acutc',
                        'replace' => 'application/vnd.acucorp',
                    ],
                    [
                        'search' => 'adp',
                        'replace' => 'audio/adpcm',
                    ],
                    [
                        'search' => 'adts',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'aep',
                        'replace' => 'application/vnd.audiograph',
                    ],
                    [
                        'search' => 'afm',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'afp',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'age',
                        'replace' => 'application/vnd.age',
                    ],
                    [
                        'search' => 'ahead',
                        'replace' => 'application/vnd.ahead.space',
                    ],
                    [
                        'search' => 'ai',
                        'replace' => 'application/postscript',
                    ],
                    [
                        'search' => 'aif',
                        'replace' => 'audio/x-aiff',
                    ],
                    [
                        'search' => 'aifc',
                        'replace' => 'audio/x-aiff',
                    ],
                    [
                        'search' => 'aiff',
                        'replace' => 'audio/x-aiff',
                    ],
                    [
                        'search' => 'aion',
                        'replace' => 'application/vnd.veritone.aion+json',
                    ],
                    [
                        'search' => 'air',
                        'replace' => 'application/vnd.adobe.air-application-installer-package+zip',
                    ],
                    [
                        'search' => 'ait',
                        'replace' => 'application/vnd.dvb.ait',
                    ],
                    [
                        'search' => 'ami',
                        'replace' => 'application/vnd.amiga.ami',
                    ],
                    [
                        'search' => 'aml',
                        'replace' => 'application/automationml-aml+xml',
                    ],
                    [
                        'search' => 'amlx',
                        'replace' => 'application/automationml-amlx+zip',
                    ],
                    [
                        'search' => 'amr',
                        'replace' => 'audio/amr',
                    ],
                    [
                        'search' => 'apex',
                        'replace' => 'application/vnd.apexlang',
                    ],
                    [
                        'search' => 'apexlang',
                        'replace' => 'application/vnd.apexlang',
                    ],
                    [
                        'search' => 'apk',
                        'replace' => 'application/vnd.android.package-archive',
                    ],
                    [
                        'search' => 'apkg',
                        'replace' => 'application/vnd.anki',
                    ],
                    [
                        'search' => 'apng',
                        'replace' => 'image/apng',
                    ],
                    [
                        'search' => 'appcache',
                        'replace' => 'text/cache-manifest',
                    ],
                    [
                        'search' => 'appinstaller',
                        'replace' => 'application/appinstaller',
                    ],
                    [
                        'search' => 'application',
                        'replace' => 'application/x-ms-application',
                    ],
                    [
                        'search' => 'appx',
                        'replace' => 'application/appx',
                    ],
                    [
                        'search' => 'appxbundle',
                        'replace' => 'application/appxbundle',
                    ],
                    [
                        'search' => 'apr',
                        'replace' => 'application/vnd.lotus-approach',
                    ],
                    [
                        'search' => 'apxml',
                        'replace' => 'application/auth-policy+xml',
                    ],
                    [
                        'search' => 'arc',
                        'replace' => 'application/x-freearc',
                    ],
                    [
                        'search' => 'arj',
                        'replace' => 'application/x-arj',
                    ],
                    [
                        'search' => 'arrow',
                        'replace' => 'application/vnd.apache.arrow.file',
                    ],
                    [
                        'search' => 'arrows',
                        'replace' => 'application/vnd.apache.arrow.stream',
                    ],
                    [
                        'search' => 'artisan',
                        'replace' => 'application/vnd.artisan+json',
                    ],
                    [
                        'search' => 'asc',
                        'replace' => 'application/pgp-signature',
                    ],
                    [
                        'search' => 'ascii',
                        'replace' => 'text/vnd.ascii-art',
                    ],
                    [
                        'search' => 'asf',
                        'replace' => 'video/x-ms-asf',
                    ],
                    [
                        'search' => 'asice',
                        'replace' => 'application/vnd.etsi.asic-e+zip',
                    ],
                    [
                        'search' => 'asics',
                        'replace' => 'application/vnd.etsi.asic-s+zip',
                    ],
                    [
                        'search' => 'asm',
                        'replace' => 'text/x-asm',
                    ],
                    [
                        'search' => 'aso',
                        'replace' => 'application/vnd.accpac.simply.aso',
                    ],
                    [
                        'search' => 'ass',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'asx',
                        'replace' => 'video/x-ms-asf',
                    ],
                    [
                        'search' => 'at3',
                        'replace' => 'audio/atrac3',
                    ],
                    [
                        'search' => 'atc',
                        'replace' => 'application/vnd.acucorp',
                    ],
                    [
                        'search' => 'atf',
                        'replace' => 'application/atf',
                    ],
                    [
                        'search' => 'atfx',
                        'replace' => 'application/atfx',
                    ],
                    [
                        'search' => 'atom',
                        'replace' => 'application/atom+xml',
                    ],
                    [
                        'search' => 'atomcat',
                        'replace' => 'application/atomcat+xml',
                    ],
                    [
                        'search' => 'atomdeleted',
                        'replace' => 'application/atomdeleted+xml',
                    ],
                    [
                        'search' => 'atomsvc',
                        'replace' => 'application/atomsvc+xml',
                    ],
                    [
                        'search' => 'atx',
                        'replace' => 'application/vnd.antix.game-component',
                    ],
                    [
                        'search' => 'atxml',
                        'replace' => 'application/atxml',
                    ],
                    [
                        'search' => 'au',
                        'replace' => 'audio/basic',
                    ],
                    [
                        'search' => 'auc',
                        'replace' => 'application/tamp-apex-update-confirm',
                    ],
                    [
                        'search' => 'avci',
                        'replace' => 'image/avci',
                    ],
                    [
                        'search' => 'avcs',
                        'replace' => 'image/avcs',
                    ],
                    [
                        'search' => 'avi',
                        'replace' => 'video/x-msvideo',
                    ],
                    [
                        'search' => 'avif',
                        'replace' => 'image/avif',
                    ],
                    [
                        'search' => 'aw',
                        'replace' => 'application/applixware',
                    ],
                    [
                        'search' => 'awb',
                        'replace' => 'audio/amr-wb',
                    ],
                    [
                        'search' => 'axdl',
                        'replace' => 'application/vnd.apexlang',
                    ],
                    [
                        'search' => 'axx',
                        'replace' => 'application/vnd.xecrets-encrypted',
                    ],
                    [
                        'search' => 'azf',
                        'replace' => 'application/vnd.airzip.filesecure.azf',
                    ],
                    [
                        'search' => 'azs',
                        'replace' => 'application/vnd.airzip.filesecure.azs',
                    ],
                    [
                        'search' => 'azv',
                        'replace' => 'image/vnd.airzip.accelerator.azv',
                    ],
                    [
                        'search' => 'azw',
                        'replace' => 'application/vnd.amazon.ebook',
                    ],
                    [
                        'search' => 'azw3',
                        'replace' => 'application/vnd.amazon.mobi8-ebook',
                    ],
                    [
                        'search' => 'b16',
                        'replace' => 'image/vnd.pco.b16',
                    ],
                    [
                        'search' => 'bar',
                        'replace' => 'application/vnd.qualcomm.brew-app-res',
                    ],
                    [
                        'search' => 'bary',
                        'replace' => 'model/vnd.bary',
                    ],
                    [
                        'search' => 'bat',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'bcpio',
                        'replace' => 'application/x-bcpio',
                    ],
                    [
                        'search' => 'bdf',
                        'replace' => 'application/x-font-bdf',
                    ],
                    [
                        'search' => 'bdm',
                        'replace' => 'application/vnd.syncml.dm+wbxml',
                    ],
                    [
                        'search' => 'bdo',
                        'replace' => 'application/vnd.nato.bindingdataobject+xml',
                    ],
                    [
                        'search' => 'bdob',
                        'replace' => 'application/vnd.nato.bindingdataobject+cbor',
                    ],
                    [
                        'search' => 'bdoc',
                        'replace' => 'application/bdoc',
                    ],
                    [
                        'search' => 'bdoj',
                        'replace' => 'application/vnd.nato.bindingdataobject+json',
                    ],
                    [
                        'search' => 'bed',
                        'replace' => 'application/vnd.realvnc.bed',
                    ],
                    [
                        'search' => 'bh2',
                        'replace' => 'application/vnd.fujitsu.oasysprs',
                    ],
                    [
                        'search' => 'bik',
                        'replace' => 'video/vnd.radgamettools.bink',
                    ],
                    [
                        'search' => 'bin',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'bk2',
                        'replace' => 'video/vnd.radgamettools.bink',
                    ],
                    [
                        'search' => 'bkm',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'blb',
                        'replace' => 'application/x-blorb',
                    ],
                    [
                        'search' => 'blend',
                        'replace' => 'application/x-blender',
                    ],
                    [
                        'search' => 'blorb',
                        'replace' => 'application/x-blorb',
                    ],
                    [
                        'search' => 'bmed',
                        'replace' => 'multipart/vnd.bint.med-plus',
                    ],
                    [
                        'search' => 'bmi',
                        'replace' => 'application/vnd.bmi',
                    ],
                    [
                        'search' => 'bmml',
                        'replace' => 'application/vnd.balsamiq.bmml+xml',
                    ],
                    [
                        'search' => 'bmp',
                        'replace' => 'image/bmp',
                    ],
                    [
                        'search' => 'bmpr',
                        'replace' => 'application/vnd.balsamiq.bmpr',
                    ],
                    [
                        'search' => 'book',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'box',
                        'replace' => 'application/vnd.previewsystems.box',
                    ],
                    [
                        'search' => 'boz',
                        'replace' => 'application/x-bzip2',
                    ],
                    [
                        'search' => 'bpd',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'bpf',
                        'replace' => 'application/vnd.bpf',
                    ],
                    [
                        'search' => 'bpk',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'brush',
                        'replace' => 'application/vnd.procreate.brush',
                    ],
                    [
                        'search' => 'brushset',
                        'replace' => 'application/vnd.procrate.brushset',
                    ],
                    [
                        'search' => 'bsp',
                        'replace' => 'model/vnd.valve.source.compiled-map',
                    ],
                    [
                        'search' => 'btf',
                        'replace' => 'image/prs.btif',
                    ],
                    [
                        'search' => 'btif',
                        'replace' => 'image/prs.btif',
                    ],
                    [
                        'search' => 'buffer',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'bz',
                        'replace' => 'application/x-bzip',
                    ],
                    [
                        'search' => 'bz2',
                        'replace' => 'application/x-bzip2',
                    ],
                    [
                        'search' => 'bz3',
                        'replace' => 'application/vnd.bzip3',
                    ],
                    [
                        'search' => 'c',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'c11amc',
                        'replace' => 'application/vnd.cluetrust.cartomobile-config',
                    ],
                    [
                        'search' => 'c11amz',
                        'replace' => 'application/vnd.cluetrust.cartomobile-config-pkg',
                    ],
                    [
                        'search' => 'c2pa',
                        'replace' => 'application/c2pa',
                    ],
                    [
                        'search' => 'c3ex',
                        'replace' => 'application/cccex',
                    ],
                    [
                        'search' => 'c4d',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4f',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4g',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4p',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c4u',
                        'replace' => 'application/vnd.clonk.c4group',
                    ],
                    [
                        'search' => 'c9r',
                        'replace' => 'application/vnd.cryptomator.encrypted',
                    ],
                    [
                        'search' => 'c9s',
                        'replace' => 'application/vnd.cryptomator.encrypted',
                    ],
                    [
                        'search' => 'cab',
                        'replace' => 'application/vnd.ms-cab-compressed',
                    ],
                    [
                        'search' => 'caf',
                        'replace' => 'audio/x-caf',
                    ],
                    [
                        'search' => 'cap',
                        'replace' => 'application/vnd.tcpdump.pcap',
                    ],
                    [
                        'search' => 'car',
                        'replace' => 'application/vnd.curl.car',
                    ],
                    [
                        'search' => 'carjson',
                        'replace' => 'application/vnd.eu.kasparian.car+json',
                    ],
                    [
                        'search' => 'cat',
                        'replace' => 'application/vnd.ms-pki.seccat',
                    ],
                    [
                        'search' => 'cb7',
                        'replace' => 'application/x-cbr',
                    ],
                    [
                        'search' => 'cba',
                        'replace' => 'application/x-cbr',
                    ],
                    [
                        'search' => 'cbor',
                        'replace' => 'application/cbor',
                    ],
                    [
                        'search' => 'cbr',
                        'replace' => 'application/vnd.comicbook-rar',
                    ],
                    [
                        'search' => 'cbt',
                        'replace' => 'application/x-cbr',
                    ],
                    [
                        'search' => 'cbz',
                        'replace' => 'application/vnd.comicbook+zip',
                    ],
                    [
                        'search' => 'cc',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'ccc',
                        'replace' => 'text/vnd.net2phone.commcenter.command',
                    ],
                    [
                        'search' => 'ccmp',
                        'replace' => 'application/ccmp+xml',
                    ],
                    [
                        'search' => 'cco',
                        'replace' => 'application/x-cocoa',
                    ],
                    [
                        'search' => 'cct',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'ccxml',
                        'replace' => 'application/ccxml+xml',
                    ],
                    [
                        'search' => 'cdbcmsg',
                        'replace' => 'application/vnd.contact.cmsg',
                    ],
                    [
                        'search' => 'cdf',
                        'replace' => 'application/x-netcdf',
                    ],
                    [
                        'search' => 'cdfx',
                        'replace' => 'application/cdfx+xml',
                    ],
                    [
                        'search' => 'cdkey',
                        'replace' => 'application/vnd.mediastation.cdkey',
                    ],
                    [
                        'search' => 'cdmia',
                        'replace' => 'application/cdmi-capability',
                    ],
                    [
                        'search' => 'cdmic',
                        'replace' => 'application/cdmi-container',
                    ],
                    [
                        'search' => 'cdmid',
                        'replace' => 'application/cdmi-domain',
                    ],
                    [
                        'search' => 'cdmio',
                        'replace' => 'application/cdmi-object',
                    ],
                    [
                        'search' => 'cdmiq',
                        'replace' => 'application/cdmi-queue',
                    ],
                    [
                        'search' => 'cdx',
                        'replace' => 'chemical/x-cdx',
                    ],
                    [
                        'search' => 'cdxml',
                        'replace' => 'application/vnd.chemdraw+xml',
                    ],
                    [
                        'search' => 'cdy',
                        'replace' => 'application/vnd.cinderella',
                    ],
                    [
                        'search' => 'cea',
                        'replace' => 'application/cea',
                    ],
                    [
                        'search' => 'cel',
                        'replace' => 'application/vnd.cel',
                    ],
                    [
                        'search' => 'cellml',
                        'replace' => 'application/cellml+xml',
                    ],
                    [
                        'search' => 'cer',
                        'replace' => 'application/pkix-cert',
                    ],
                    [
                        'search' => 'cfs',
                        'replace' => 'application/x-cfs-compressed',
                    ],
                    [
                        'search' => 'cgm',
                        'replace' => 'image/cgm',
                    ],
                    [
                        'search' => 'chat',
                        'replace' => 'application/x-chat',
                    ],
                    [
                        'search' => 'chm',
                        'replace' => 'application/vnd.ms-htmlhelp',
                    ],
                    [
                        'search' => 'chrt',
                        'replace' => 'application/vnd.kde.kchart',
                    ],
                    [
                        'search' => 'cif',
                        'replace' => 'chemical/x-cif',
                    ],
                    [
                        'search' => 'cii',
                        'replace' => 'application/vnd.anser-web-certificate-issue-initiation',
                    ],
                    [
                        'search' => 'cil',
                        'replace' => 'application/vnd.ms-artgalry',
                    ],
                    [
                        'search' => 'cjs',
                        'replace' => 'application/node',
                    ],
                    [
                        'search' => 'cl',
                        'replace' => 'application/simple-filter+xml',
                    ],
                    [
                        'search' => 'cla',
                        'replace' => 'application/vnd.claymore',
                    ],
                    [
                        'search' => 'class',
                        'replace' => 'application/java-vm',
                    ],
                    [
                        'search' => 'cld',
                        'replace' => 'model/vnd.cld',
                    ],
                    [
                        'search' => 'clip',
                        'replace' => 'image/vnd.clip',
                    ],
                    [
                        'search' => 'clkk',
                        'replace' => 'application/vnd.crick.clicker.keyboard',
                    ],
                    [
                        'search' => 'clkp',
                        'replace' => 'application/vnd.crick.clicker.palette',
                    ],
                    [
                        'search' => 'clkt',
                        'replace' => 'application/vnd.crick.clicker.template',
                    ],
                    [
                        'search' => 'clkw',
                        'replace' => 'application/vnd.crick.clicker.wordbank',
                    ],
                    [
                        'search' => 'clkx',
                        'replace' => 'application/vnd.crick.clicker',
                    ],
                    [
                        'search' => 'clp',
                        'replace' => 'application/x-msclip',
                    ],
                    [
                        'search' => 'clue',
                        'replace' => 'application/clue_info+xml',
                    ],
                    [
                        'search' => 'cmc',
                        'replace' => 'application/vnd.cosmocaller',
                    ],
                    [
                        'search' => 'cmdf',
                        'replace' => 'chemical/x-cmdf',
                    ],
                    [
                        'search' => 'cml',
                        'replace' => 'chemical/x-cml',
                    ],
                    [
                        'search' => 'cmp',
                        'replace' => 'application/vnd.yellowriver-custom-menu',
                    ],
                    [
                        'search' => 'cmsc',
                        'replace' => 'application/cms',
                    ],
                    [
                        'search' => 'cmx',
                        'replace' => 'image/x-cmx',
                    ],
                    [
                        'search' => 'cnd',
                        'replace' => 'text/jcr-cnd',
                    ],
                    [
                        'search' => 'cod',
                        'replace' => 'application/vnd.rim.cod',
                    ],
                    [
                        'search' => 'coffee',
                        'replace' => 'text/coffeescript',
                    ],
                    [
                        'search' => 'com',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'conf',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'coswid',
                        'replace' => 'application/swid+cbor',
                    ],
                    [
                        'search' => 'cpio',
                        'replace' => 'application/x-cpio',
                    ],
                    [
                        'search' => 'cpj',
                        'replace' => 'application/vnd.xarin.cpj',
                    ],
                    [
                        'search' => 'cpkg',
                        'replace' => 'application/vnd.xmpie.cpkg',
                    ],
                    [
                        'search' => 'cpl',
                        'replace' => 'application/cpl+xml',
                    ],
                    [
                        'search' => 'cpp',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'cpt',
                        'replace' => 'application/mac-compactpro',
                    ],
                    [
                        'search' => 'cql',
                        'replace' => 'text/cql',
                    ],
                    [
                        'search' => 'crd',
                        'replace' => 'application/x-mscardfile',
                    ],
                    [
                        'search' => 'crl',
                        'replace' => 'application/pkix-crl',
                    ],
                    [
                        'search' => 'crt',
                        'replace' => 'application/x-x509-ca-cert',
                    ],
                    [
                        'search' => 'crtr',
                        'replace' => 'application/vnd.multiad.creator',
                    ],
                    [
                        'search' => 'crx',
                        'replace' => 'application/x-chrome-extension',
                    ],
                    [
                        'search' => 'cryptomator',
                        'replace' => 'application/vnd.cryptomator.vault',
                    ],
                    [
                        'search' => 'cryptonote',
                        'replace' => 'application/vnd.rig.cryptonote',
                    ],
                    [
                        'search' => 'csh',
                        'replace' => 'application/x-csh',
                    ],
                    [
                        'search' => 'csl',
                        'replace' => 'application/vnd.citationstyles.style+xml',
                    ],
                    [
                        'search' => 'csml',
                        'replace' => 'chemical/x-csml',
                    ],
                    [
                        'search' => 'csp',
                        'replace' => 'application/vnd.commonspace',
                    ],
                    [
                        'search' => 'csrattrs',
                        'replace' => 'application/csrattrs',
                    ],
                    [
                        'search' => 'css',
                        'replace' => 'text/css',
                    ],
                    [
                        'search' => 'cst',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'csv',
                        'replace' => 'text/csv',
                    ],
                    [
                        'search' => 'csvs',
                        'replace' => 'text/csv-schema',
                    ],
                    [
                        'search' => 'cu',
                        'replace' => 'application/cu-seeme',
                    ],
                    [
                        'search' => 'cuc',
                        'replace' => 'application/tamp-community-update-confirm',
                    ],
                    [
                        'search' => 'curl',
                        'replace' => 'text/vnd.curl',
                    ],
                    [
                        'search' => 'cw',
                        'replace' => 'application/prs.cww',
                    ],
                    [
                        'search' => 'cwl',
                        'replace' => 'application/cwl',
                    ],
                    [
                        'search' => 'cwl.json',
                        'replace' => 'application/cwl+json',
                    ],
                    [
                        'search' => 'cwl.yaml',
                        'replace' => 'application/cwl+yaml',
                    ],
                    [
                        'search' => 'cww',
                        'replace' => 'application/prs.cww',
                    ],
                    [
                        'search' => 'cxt',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'cxx',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'dae',
                        'replace' => 'model/vnd.collada+xml',
                    ],
                    [
                        'search' => 'daf',
                        'replace' => 'application/vnd.mobius.daf',
                    ],
                    [
                        'search' => 'dart',
                        'replace' => 'application/vnd.dart',
                    ],
                    [
                        'search' => 'dataless',
                        'replace' => 'application/vnd.fdsn.seed',
                    ],
                    [
                        'search' => 'davmount',
                        'replace' => 'application/davmount+xml',
                    ],
                    [
                        'search' => 'db',
                        'replace' => 'application/vnd.sqlite3',
                    ],
                    [
                        'search' => 'dbf',
                        'replace' => 'application/vnd.dbf',
                    ],
                    [
                        'search' => 'dbk',
                        'replace' => 'application/docbook+xml',
                    ],
                    [
                        'search' => 'dcd',
                        'replace' => 'application/dcd',
                    ],
                    [
                        'search' => 'dcm',
                        'replace' => 'application/dicom',
                    ],
                    [
                        'search' => 'dcmp',
                        'replace' => 'application/vnd.dcmp+xml',
                    ],
                    [
                        'search' => 'dcr',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'dcurl',
                        'replace' => 'text/vnd.curl.dcurl',
                    ],
                    [
                        'search' => 'dd2',
                        'replace' => 'application/vnd.oma.dd2+xml',
                    ],
                    [
                        'search' => 'ddd',
                        'replace' => 'application/vnd.fujixerox.ddd',
                    ],
                    [
                        'search' => 'ddf',
                        'replace' => 'application/vnd.syncml.dmddf+xml',
                    ],
                    [
                        'search' => 'dds',
                        'replace' => 'image/vnd.ms-dds',
                    ],
                    [
                        'search' => 'deb',
                        'replace' => 'application/x-debian-package',
                    ],
                    [
                        'search' => 'def',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'deploy',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'der',
                        'replace' => 'application/x-x509-ca-cert',
                    ],
                    [
                        'search' => 'dfac',
                        'replace' => 'application/vnd.dreamfactory',
                    ],
                    [
                        'search' => 'dgc',
                        'replace' => 'application/x-dgc-compressed',
                    ],
                    [
                        'search' => 'dib',
                        'replace' => 'image/bmp',
                    ],
                    [
                        'search' => 'dic',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'did',
                        'replace' => 'application/did',
                    ],
                    [
                        'search' => 'dii',
                        'replace' => 'application/dii',
                    ],
                    [
                        'search' => 'dim',
                        'replace' => 'application/vnd.fastcopy-disk-image',
                    ],
                    [
                        'search' => 'dir',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'dis',
                        'replace' => 'application/vnd.mobius.dis',
                    ],
                    [
                        'search' => 'disposition-notification',
                        'replace' => 'message/disposition-notification',
                    ],
                    [
                        'search' => 'dist',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'distz',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'dit',
                        'replace' => 'application/dit',
                    ],
                    [
                        'search' => 'dive',
                        'replace' => 'application/vnd.patentdive',
                    ],
                    [
                        'search' => 'djv',
                        'replace' => 'image/vnd.djvu',
                    ],
                    [
                        'search' => 'djvu',
                        'replace' => 'image/vnd.djvu',
                    ],
                    [
                        'search' => 'dl',
                        'replace' => 'application/vnd.datalog',
                    ],
                    [
                        'search' => 'dll',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'dls',
                        'replace' => 'audio/dls',
                    ],
                    [
                        'search' => 'dmg',
                        'replace' => 'application/x-apple-diskimage',
                    ],
                    [
                        'search' => 'dmp',
                        'replace' => 'application/vnd.tcpdump.pcap',
                    ],
                    [
                        'search' => 'dms',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'dna',
                        'replace' => 'application/vnd.dna',
                    ],
                    [
                        'search' => 'dng',
                        'replace' => 'image/x-adobe-dng',
                    ],
                    [
                        'search' => 'do',
                        'replace' => 'application/vnd.httphone',
                    ],
                    [
                        'search' => 'doc',
                        'replace' => 'application/msword',
                    ],
                    [
                        'search' => 'docjson',
                        'replace' => 'application/vnd.document+json',
                    ],
                    [
                        'search' => 'docm',
                        'replace' => 'application/vnd.ms-word.document.macroenabled.12',
                    ],
                    [
                        'search' => 'docx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ],
                    [
                        'search' => 'dor',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'dot',
                        'replace' => 'application/msword',
                    ],
                    [
                        'search' => 'dotm',
                        'replace' => 'application/vnd.ms-word.template.macroenabled.12',
                    ],
                    [
                        'search' => 'dotx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
                    ],
                    [
                        'search' => 'dp',
                        'replace' => 'application/vnd.osgi.dp',
                    ],
                    [
                        'search' => 'dpg',
                        'replace' => 'application/vnd.dpgraph',
                    ],
                    [
                        'search' => 'dpx',
                        'replace' => 'image/dpx',
                    ],
                    [
                        'search' => 'dra',
                        'replace' => 'audio/vnd.dra',
                    ],
                    [
                        'search' => 'drle',
                        'replace' => 'image/dicom-rle',
                    ],
                    [
                        'search' => 'drm',
                        'replace' => 'application/vnd.procreate.dream',
                    ],
                    [
                        'search' => 'dsc',
                        'replace' => 'text/prs.lines.tag',
                    ],
                    [
                        'search' => 'dsm',
                        'replace' => 'application/vnd.desmume.movie',
                    ],
                    [
                        'search' => 'dspx',
                        'replace' => 'application/vnd.openvpi.dspx+json',
                    ],
                    [
                        'search' => 'dssc',
                        'replace' => 'application/dssc+der',
                    ],
                    [
                        'search' => 'dtb',
                        'replace' => 'application/x-dtbook+xml',
                    ],
                    [
                        'search' => 'dtd',
                        'replace' => 'application/xml-dtd',
                    ],
                    [
                        'search' => 'dts',
                        'replace' => 'audio/vnd.dts',
                    ],
                    [
                        'search' => 'dtshd',
                        'replace' => 'audio/vnd.dts.hd',
                    ],
                    [
                        'search' => 'dump',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'dvb',
                        'replace' => 'video/vnd.dvb.file',
                    ],
                    [
                        'search' => 'dvc',
                        'replace' => 'application/dvcs',
                    ],
                    [
                        'search' => 'dvi',
                        'replace' => 'application/x-dvi',
                    ],
                    [
                        'search' => 'dwd',
                        'replace' => 'application/atsc-dwd+xml',
                    ],
                    [
                        'search' => 'dwf',
                        'replace' => 'model/vnd.dwf',
                    ],
                    [
                        'search' => 'dwg',
                        'replace' => 'image/vnd.dwg',
                    ],
                    [
                        'search' => 'dxf',
                        'replace' => 'image/vnd.dxf',
                    ],
                    [
                        'search' => 'dxp',
                        'replace' => 'application/vnd.spotfire.dxp',
                    ],
                    [
                        'search' => 'dxr',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'dzr',
                        'replace' => 'application/vnd.dzr',
                    ],
                    [
                        'search' => 'ear',
                        'replace' => 'application/java-archive',
                    ],
                    [
                        'search' => 'ebuild',
                        'replace' => 'application/vnd.gentoo.ebuild',
                    ],
                    [
                        'search' => 'ecelp4800',
                        'replace' => 'audio/vnd.nuera.ecelp4800',
                    ],
                    [
                        'search' => 'ecelp7470',
                        'replace' => 'audio/vnd.nuera.ecelp7470',
                    ],
                    [
                        'search' => 'ecelp9600',
                        'replace' => 'audio/vnd.nuera.ecelp9600',
                    ],
                    [
                        'search' => 'ecig',
                        'replace' => 'application/vnd.evolv.ecig.settings',
                    ],
                    [
                        'search' => 'ecigprofile',
                        'replace' => 'application/vnd.evolv.ecig.profile',
                    ],
                    [
                        'search' => 'ecigtheme',
                        'replace' => 'application/vnd.evolv.ecig.theme',
                    ],
                    [
                        'search' => 'eclass',
                        'replace' => 'application/vnd.gentoo.eclass',
                    ],
                    [
                        'search' => 'ecma',
                        'replace' => 'application/ecmascript',
                    ],
                    [
                        'search' => 'edm',
                        'replace' => 'application/vnd.novadigm.edm',
                    ],
                    [
                        'search' => 'edx',
                        'replace' => 'application/vnd.novadigm.edx',
                    ],
                    [
                        'search' => 'efi',
                        'replace' => 'application/efi',
                    ],
                    [
                        'search' => 'efif',
                        'replace' => 'application/vnd.picsel',
                    ],
                    [
                        'search' => 'ei6',
                        'replace' => 'application/vnd.pg.osasli',
                    ],
                    [
                        'search' => 'elc',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'eln',
                        'replace' => 'application/vnd.eln+zip',
                    ],
                    [
                        'search' => 'emf',
                        'replace' => 'image/emf',
                    ],
                    [
                        'search' => 'eml',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'emm',
                        'replace' => 'application/vnd.ibm.electronic-media',
                    ],
                    [
                        'search' => 'emma',
                        'replace' => 'application/emma+xml',
                    ],
                    [
                        'search' => 'emotionml',
                        'replace' => 'application/emotionml+xml',
                    ],
                    [
                        'search' => 'emz',
                        'replace' => 'application/x-msmetafile',
                    ],
                    [
                        'search' => 'ent',
                        'replace' => 'application/xml-external-parsed-entity',
                    ],
                    [
                        'search' => 'entity',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'enw',
                        'replace' => 'audio/evrcnw',
                    ],
                    [
                        'search' => 'eol',
                        'replace' => 'audio/vnd.digital-winds',
                    ],
                    [
                        'search' => 'eot',
                        'replace' => 'application/vnd.ms-fontobject',
                    ],
                    [
                        'search' => 'ep',
                        'replace' => 'application/vnd.bluetooth.ep.oob',
                    ],
                    [
                        'search' => 'eps',
                        'replace' => 'application/postscript',
                    ],
                    [
                        'search' => 'epub',
                        'replace' => 'application/epub+zip',
                    ],
                    [
                        'search' => 'erofs',
                        'replace' => 'application/vnd.erofs',
                    ],
                    [
                        'search' => 'es',
                        'replace' => 'application/ecmascript',
                    ],
                    [
                        'search' => 'es3',
                        'replace' => 'application/vnd.eszigno3+xml',
                    ],
                    [
                        'search' => 'esa',
                        'replace' => 'application/vnd.osgi.subsystem',
                    ],
                    [
                        'search' => 'esf',
                        'replace' => 'application/vnd.epson.esf',
                    ],
                    [
                        'search' => 'espass',
                        'replace' => 'application/vnd.espass-espass+zip',
                    ],
                    [
                        'search' => 'et3',
                        'replace' => 'application/vnd.eszigno3+xml',
                    ],
                    [
                        'search' => 'etx',
                        'replace' => 'text/x-setext',
                    ],
                    [
                        'search' => 'eva',
                        'replace' => 'application/x-eva',
                    ],
                    [
                        'search' => 'evb',
                        'replace' => 'audio/evrcb',
                    ],
                    [
                        'search' => 'evc',
                        'replace' => 'audio/evrc',
                    ],
                    [
                        'search' => 'evw',
                        'replace' => 'audio/evrcwb',
                    ],
                    [
                        'search' => 'evy',
                        'replace' => 'application/x-envoy',
                    ],
                    [
                        'search' => 'exe',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'exi',
                        'replace' => 'application/exi',
                    ],
                    [
                        'search' => 'exp',
                        'replace' => 'application/express',
                    ],
                    [
                        'search' => 'exr',
                        'replace' => 'image/aces',
                    ],
                    [
                        'search' => 'ext',
                        'replace' => 'application/vnd.novadigm.ext',
                    ],
                    [
                        'search' => 'ez',
                        'replace' => 'application/andrew-inset',
                    ],
                    [
                        'search' => 'ez2',
                        'replace' => 'application/vnd.ezpix-album',
                    ],
                    [
                        'search' => 'ez3',
                        'replace' => 'application/vnd.ezpix-package',
                    ],
                    [
                        'search' => 'f',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'f4v',
                        'replace' => 'video/x-f4v',
                    ],
                    [
                        'search' => 'f77',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'f90',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'facta',
                        'replace' => 'audio/vnd.blockfact.facta',
                    ],
                    [
                        'search' => 'facti',
                        'replace' => 'image/vnd.blockfact.facti',
                    ],
                    [
                        'search' => 'factv',
                        'replace' => 'video/vnd.blockfact.factv',
                    ],
                    [
                        'search' => 'fbs',
                        'replace' => 'image/vnd.fastbidsheet',
                    ],
                    [
                        'search' => 'fbx',
                        'replace' => 'application/vnd.autodesk.fbx',
                    ],
                    [
                        'search' => 'fcdt',
                        'replace' => 'application/vnd.adobe.formscentral.fcdt',
                    ],
                    [
                        'search' => 'fcs',
                        'replace' => 'application/vnd.isac.fcs',
                    ],
                    [
                        'search' => 'fdf',
                        'replace' => 'application/vnd.fdf',
                    ],
                    [
                        'search' => 'fdt',
                        'replace' => 'application/fdt+xml',
                    ],
                    [
                        'search' => 'fe_launch',
                        'replace' => 'application/vnd.denovo.fcselayout-link',
                    ],
                    [
                        'search' => 'fg5',
                        'replace' => 'application/vnd.fujitsu.oasysgp',
                    ],
                    [
                        'search' => 'fgb',
                        'replace' => 'application/vnd.fgb',
                    ],
                    [
                        'search' => 'fgd',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'fh',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fh4',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fh5',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fh7',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fhc',
                        'replace' => 'image/x-freehand',
                    ],
                    [
                        'search' => 'fig',
                        'replace' => 'application/x-xfig',
                    ],
                    [
                        'search' => 'finf',
                        'replace' => 'application/fastinfoset',
                    ],
                    [
                        'search' => 'fits',
                        'replace' => 'image/fits',
                    ],
                    [
                        'search' => 'fla',
                        'replace' => 'application/vnd.dtg.local.flash',
                    ],
                    [
                        'search' => 'flac',
                        'replace' => 'audio/flac',
                    ],
                    [
                        'search' => 'flb',
                        'replace' => 'application/vnd.ficlab.flb+zip',
                    ],
                    [
                        'search' => 'fli',
                        'replace' => 'video/x-fli',
                    ],
                    [
                        'search' => 'flo',
                        'replace' => 'application/vnd.micrografx.flo',
                    ],
                    [
                        'search' => 'flt',
                        'replace' => 'text/vnd.ficlab.flt',
                    ],
                    [
                        'search' => 'flv',
                        'replace' => 'video/x-flv',
                    ],
                    [
                        'search' => 'flw',
                        'replace' => 'application/vnd.kde.kivio',
                    ],
                    [
                        'search' => 'flx',
                        'replace' => 'text/vnd.fmi.flexstor',
                    ],
                    [
                        'search' => 'fly',
                        'replace' => 'text/vnd.fly',
                    ],
                    [
                        'search' => 'fm',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'fnc',
                        'replace' => 'application/vnd.frogans.fnc',
                    ],
                    [
                        'search' => 'fo',
                        'replace' => 'application/vnd.software602.filler.form+xml',
                    ],
                    [
                        'search' => 'font',
                        'replace' => 'font/ttf',
                    ],
                    [
                        'search' => 'for',
                        'replace' => 'text/x-fortran',
                    ],
                    [
                        'search' => 'fpx',
                        'replace' => 'image/vnd.fpx',
                    ],
                    [
                        'search' => 'frame',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'frm',
                        'replace' => 'application/vnd.xfdl',
                    ],
                    [
                        'search' => 'fsc',
                        'replace' => 'application/vnd.fsc.weblaunch',
                    ],
                    [
                        'search' => 'fst',
                        'replace' => 'image/vnd.fst',
                    ],
                    [
                        'search' => 'ftc',
                        'replace' => 'application/vnd.fluxtime.clip',
                    ],
                    [
                        'search' => 'fti',
                        'replace' => 'application/vnd.anser-web-funds-transfer-initiation',
                    ],
                    [
                        'search' => 'fvt',
                        'replace' => 'video/vnd.fvt',
                    ],
                    [
                        'search' => 'fxp',
                        'replace' => 'application/vnd.adobe.fxp',
                    ],
                    [
                        'search' => 'fxpl',
                        'replace' => 'application/vnd.adobe.fxp',
                    ],
                    [
                        'search' => 'fzs',
                        'replace' => 'application/vnd.fuzzysheet',
                    ],
                    [
                        'search' => 'g2w',
                        'replace' => 'application/vnd.geoplan',
                    ],
                    [
                        'search' => 'g3',
                        'replace' => 'image/g3fax',
                    ],
                    [
                        'search' => 'g3fc',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc0',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc1',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc2',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc3',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc4',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc5',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc6',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc7',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc8',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3fc9',
                        'replace' => 'application/vnd.g3pix.g3fc',
                    ],
                    [
                        'search' => 'g3w',
                        'replace' => 'application/vnd.geospace',
                    ],
                    [
                        'search' => 'gac',
                        'replace' => 'application/vnd.groove-account',
                    ],
                    [
                        'search' => 'gam',
                        'replace' => 'application/x-tads',
                    ],
                    [
                        'search' => 'gbr',
                        'replace' => 'application/rpki-ghostbusters',
                    ],
                    [
                        'search' => 'gca',
                        'replace' => 'application/x-gca-compressed',
                    ],
                    [
                        'search' => 'gdl',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'gdoc',
                        'replace' => 'application/vnd.google-apps.document',
                    ],
                    [
                        'search' => 'gdraw',
                        'replace' => 'application/vnd.google-apps.drawing',
                    ],
                    [
                        'search' => 'gdz',
                        'replace' => 'application/vnd.familysearch.gedcom+zip',
                    ],
                    [
                        'search' => 'ged',
                        'replace' => 'text/vnd.familysearch.gedcom',
                    ],
                    [
                        'search' => 'genozip',
                        'replace' => 'application/vnd.genozip',
                    ],
                    [
                        'search' => 'geo',
                        'replace' => 'application/vnd.dynageo',
                    ],
                    [
                        'search' => 'geojson',
                        'replace' => 'application/geo+json',
                    ],
                    [
                        'search' => 'gex',
                        'replace' => 'application/vnd.geometry-explorer',
                    ],
                    [
                        'search' => 'gff3',
                        'replace' => 'text/gff3',
                    ],
                    [
                        'search' => 'gform',
                        'replace' => 'application/vnd.google-apps.form',
                    ],
                    [
                        'search' => 'ggb',
                        'replace' => 'application/vnd.geogebra.file',
                    ],
                    [
                        'search' => 'ggp',
                        'replace' => 'application/vnd.geogebra.pinboard',
                    ],
                    [
                        'search' => 'ggs',
                        'replace' => 'application/vnd.geogebra.slides',
                    ],
                    [
                        'search' => 'ggt',
                        'replace' => 'application/vnd.geogebra.tool',
                    ],
                    [
                        'search' => 'ghf',
                        'replace' => 'application/vnd.groove-help',
                    ],
                    [
                        'search' => 'gif',
                        'replace' => 'image/gif',
                    ],
                    [
                        'search' => 'gim',
                        'replace' => 'application/vnd.groove-identity-message',
                    ],
                    [
                        'search' => 'gjam',
                        'replace' => 'application/vnd.google-apps.jam',
                    ],
                    [
                        'search' => 'glb',
                        'replace' => 'model/gltf-binary',
                    ],
                    [
                        'search' => 'glbin',
                        'replace' => 'application/gltf-buffer',
                    ],
                    [
                        'search' => 'glbuf',
                        'replace' => 'application/gltf-buffer',
                    ],
                    [
                        'search' => 'gltf',
                        'replace' => 'model/gltf+json',
                    ],
                    [
                        'search' => 'gmap',
                        'replace' => 'application/vnd.google-apps.map',
                    ],
                    [
                        'search' => 'gml',
                        'replace' => 'application/gml+xml',
                    ],
                    [
                        'search' => 'gmx',
                        'replace' => 'application/vnd.gmx',
                    ],
                    [
                        'search' => 'gnumeric',
                        'replace' => 'application/x-gnumeric',
                    ],
                    [
                        'search' => 'gph',
                        'replace' => 'application/vnd.flographit',
                    ],
                    [
                        'search' => 'gpkg',
                        'replace' => 'application/geopackage+sqlite3',
                    ],
                    [
                        'search' => 'gpkg.tar',
                        'replace' => 'application/vnd.gentoo.gpkg',
                    ],
                    [
                        'search' => 'gpx',
                        'replace' => 'application/gpx+xml',
                    ],
                    [
                        'search' => 'gqf',
                        'replace' => 'application/vnd.grafeq',
                    ],
                    [
                        'search' => 'gqs',
                        'replace' => 'application/vnd.grafeq',
                    ],
                    [
                        'search' => 'gram',
                        'replace' => 'application/srgs',
                    ],
                    [
                        'search' => 'gramps',
                        'replace' => 'application/x-gramps-xml',
                    ],
                    [
                        'search' => 'grd',
                        'replace' => 'application/vnd.gentics.grd+json',
                    ],
                    [
                        'search' => 'gre',
                        'replace' => 'application/vnd.geometry-explorer',
                    ],
                    [
                        'search' => 'grv',
                        'replace' => 'application/vnd.groove-injector',
                    ],
                    [
                        'search' => 'grxml',
                        'replace' => 'application/srgs+xml',
                    ],
                    [
                        'search' => 'gscript',
                        'replace' => 'application/vnd.google-apps.script',
                    ],
                    [
                        'search' => 'gsf',
                        'replace' => 'application/x-font-ghostscript',
                    ],
                    [
                        'search' => 'gsheet',
                        'replace' => 'application/vnd.google-apps.spreadsheet',
                    ],
                    [
                        'search' => 'gsite',
                        'replace' => 'application/vnd.google-apps.site',
                    ],
                    [
                        'search' => 'gslides',
                        'replace' => 'application/vnd.google-apps.presentation',
                    ],
                    [
                        'search' => 'gsm',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'gtar',
                        'replace' => 'application/x-gtar',
                    ],
                    [
                        'search' => 'gtm',
                        'replace' => 'application/vnd.groove-tool-message',
                    ],
                    [
                        'search' => 'gtw',
                        'replace' => 'model/vnd.gtw',
                    ],
                    [
                        'search' => 'gv',
                        'replace' => 'text/vnd.graphviz',
                    ],
                    [
                        'search' => 'gxf',
                        'replace' => 'application/gxf',
                    ],
                    [
                        'search' => 'gxt',
                        'replace' => 'application/vnd.geonext',
                    ],
                    [
                        'search' => 'gz',
                        'replace' => 'application/gzip',
                    ],
                    [
                        'search' => 'h',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'h261',
                        'replace' => 'video/h261',
                    ],
                    [
                        'search' => 'h263',
                        'replace' => 'video/h263',
                    ],
                    [
                        'search' => 'h264',
                        'replace' => 'video/h264',
                    ],
                    [
                        'search' => 'h5',
                        'replace' => 'application/sipc',
                    ],
                    [
                        'search' => 'hal',
                        'replace' => 'application/vnd.hal+xml',
                    ],
                    [
                        'search' => 'hans',
                        'replace' => 'text/vnd.hans',
                    ],
                    [
                        'search' => 'haptic',
                        'replace' => 'haptics/ivs',
                    ],
                    [
                        'search' => 'hbc',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'hbci',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'hbs',
                        'replace' => 'text/x-handlebars-template',
                    ],
                    [
                        'search' => 'hdd',
                        'replace' => 'application/x-virtualbox-hdd',
                    ],
                    [
                        'search' => 'hdf',
                        'replace' => 'application/x-hdf',
                    ],
                    [
                        'search' => 'hdr',
                        'replace' => 'image/vnd.radiance',
                    ],
                    [
                        'search' => 'hdt',
                        'replace' => 'application/vnd.hdt',
                    ],
                    [
                        'search' => 'heic',
                        'replace' => 'image/heic',
                    ],
                    [
                        'search' => 'heics',
                        'replace' => 'image/heic-sequence',
                    ],
                    [
                        'search' => 'heif',
                        'replace' => 'image/heif',
                    ],
                    [
                        'search' => 'heifs',
                        'replace' => 'image/heif-sequence',
                    ],
                    [
                        'search' => 'hej2',
                        'replace' => 'image/hej2k',
                    ],
                    [
                        'search' => 'held',
                        'replace' => 'application/atsc-held+xml',
                    ],
                    [
                        'search' => 'heldxml',
                        'replace' => 'application/held+xml',
                    ],
                    [
                        'search' => 'hgl',
                        'replace' => 'text/vnd.hgl',
                    ],
                    [
                        'search' => 'hh',
                        'replace' => 'text/x-c',
                    ],
                    [
                        'search' => 'hif',
                        'replace' => 'image/heic',
                    ],
                    [
                        'search' => 'hjson',
                        'replace' => 'application/hjson',
                    ],
                    [
                        'search' => 'hlp',
                        'replace' => 'application/winhlp',
                    ],
                    [
                        'search' => 'hpgl',
                        'replace' => 'application/vnd.hp-hpgl',
                    ],
                    [
                        'search' => 'hpi',
                        'replace' => 'application/vnd.hp-hpid',
                    ],
                    [
                        'search' => 'hpid',
                        'replace' => 'application/vnd.hp-hpid',
                    ],
                    [
                        'search' => 'hps',
                        'replace' => 'application/vnd.hp-hps',
                    ],
                    [
                        'search' => 'hpub',
                        'replace' => 'application/prs.hpub+zip',
                    ],
                    [
                        'search' => 'hqx',
                        'replace' => 'application/mac-binhex40',
                    ],
                    [
                        'search' => 'hsl',
                        'replace' => 'application/vnd.hsl',
                    ],
                    [
                        'search' => 'htc',
                        'replace' => 'text/x-component',
                    ],
                    [
                        'search' => 'htke',
                        'replace' => 'application/vnd.kenameaapp',
                    ],
                    [
                        'search' => 'htm',
                        'replace' => 'text/html',
                    ],
                    [
                        'search' => 'html',
                        'replace' => 'text/html',
                    ],
                    [
                        'search' => 'hvd',
                        'replace' => 'application/vnd.yamaha.hv-dic',
                    ],
                    [
                        'search' => 'hvp',
                        'replace' => 'application/vnd.yamaha.hv-voice',
                    ],
                    [
                        'search' => 'hvs',
                        'replace' => 'application/vnd.yamaha.hv-script',
                    ],
                    [
                        'search' => 'i2g',
                        'replace' => 'application/vnd.intergeo',
                    ],
                    [
                        'search' => 'ica',
                        'replace' => 'application/vnd.commerce-battelle',
                    ],
                    [
                        'search' => 'icc',
                        'replace' => 'application/vnd.iccprofile',
                    ],
                    [
                        'search' => 'icd',
                        'replace' => 'application/vnd.commerce-battelle',
                    ],
                    [
                        'search' => 'ice',
                        'replace' => 'x-conference/x-cooltalk',
                    ],
                    [
                        'search' => 'icf',
                        'replace' => 'application/vnd.commerce-battelle',
                    ],
                    [
                        'search' => 'icm',
                        'replace' => 'application/vnd.iccprofile',
                    ],
                    [
                        'search' => 'ico',
                        'replace' => 'image/vnd.microsoft.icon',
                    ],
                    [
                        'search' => 'ics',
                        'replace' => 'text/calendar',
                    ],
                    [
                        'search' => 'ief',
                        'replace' => 'image/ief',
                    ],
                    [
                        'search' => 'iepd',
                        'replace' => 'application/vnd.nato.openxmlformats-package.iepd+zip',
                    ],
                    [
                        'search' => 'ifb',
                        'replace' => 'text/calendar',
                    ],
                    [
                        'search' => 'ifc',
                        'replace' => 'application/p21',
                    ],
                    [
                        'search' => 'ifm',
                        'replace' => 'application/vnd.shana.informed.formdata',
                    ],
                    [
                        'search' => 'iges',
                        'replace' => 'model/iges',
                    ],
                    [
                        'search' => 'igl',
                        'replace' => 'application/vnd.igloader',
                    ],
                    [
                        'search' => 'igm',
                        'replace' => 'application/vnd.insors.igm',
                    ],
                    [
                        'search' => 'ign',
                        'replace' => 'application/vnd.coreos.ignition+json',
                    ],
                    [
                        'search' => 'ignition',
                        'replace' => 'application/vnd.coreos.ignition+json',
                    ],
                    [
                        'search' => 'igs',
                        'replace' => 'model/iges',
                    ],
                    [
                        'search' => 'igx',
                        'replace' => 'application/vnd.micrografx.igx',
                    ],
                    [
                        'search' => 'iif',
                        'replace' => 'application/vnd.shana.informed.interchange',
                    ],
                    [
                        'search' => 'imf',
                        'replace' => 'application/vnd.imagemeter.folder+zip',
                    ],
                    [
                        'search' => 'img',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'imgcal',
                        'replace' => 'application/vnd.3lightssoftware.imagescal',
                    ],
                    [
                        'search' => 'imi',
                        'replace' => 'application/vnd.imagemeter.image+zip',
                    ],
                    [
                        'search' => 'imp',
                        'replace' => 'application/vnd.accpac.simply.imp',
                    ],
                    [
                        'search' => 'ims',
                        'replace' => 'application/vnd.ms-ims',
                    ],
                    [
                        'search' => 'imscc',
                        'replace' => 'application/vnd.ims.imsccv1p3',
                    ],
                    [
                        'search' => 'in',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'ini',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'ink',
                        'replace' => 'application/inkml+xml',
                    ],
                    [
                        'search' => 'inkml',
                        'replace' => 'application/inkml+xml',
                    ],
                    [
                        'search' => 'install',
                        'replace' => 'application/x-install-instructions',
                    ],
                    [
                        'search' => 'iota',
                        'replace' => 'application/vnd.astraea-software.iota',
                    ],
                    [
                        'search' => 'ipfix',
                        'replace' => 'application/ipfix',
                    ],
                    [
                        'search' => 'ipk',
                        'replace' => 'application/vnd.shana.informed.package',
                    ],
                    [
                        'search' => 'ipns-record',
                        'replace' => 'application/vnd.ipfs.ipns-record',
                    ],
                    [
                        'search' => 'ipynb',
                        'replace' => 'application/x-ipynb+json',
                    ],
                    [
                        'search' => 'irm',
                        'replace' => 'application/vnd.ibm.rights-management',
                    ],
                    [
                        'search' => 'irp',
                        'replace' => 'application/vnd.irepository.package+xml',
                    ],
                    [
                        'search' => 'ism',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'iso',
                        'replace' => 'application/x-iso9660-image',
                    ],
                    [
                        'search' => 'istc',
                        'replace' => 'application/vnd.veryant.thin',
                    ],
                    [
                        'search' => 'isws',
                        'replace' => 'application/vnd.veryant.thin',
                    ],
                    [
                        'search' => 'itp',
                        'replace' => 'application/vnd.shana.informed.formtemplate',
                    ],
                    [
                        'search' => 'its',
                        'replace' => 'application/its+xml',
                    ],
                    [
                        'search' => 'ivp',
                        'replace' => 'application/vnd.immervision-ivp',
                    ],
                    [
                        'search' => 'ivu',
                        'replace' => 'application/vnd.immervision-ivu',
                    ],
                    [
                        'search' => 'j2c',
                        'replace' => 'image/j2c',
                    ],
                    [
                        'search' => 'j2k',
                        'replace' => 'image/j2c',
                    ],
                    [
                        'search' => 'jad',
                        'replace' => 'text/vnd.sun.j2me.app-descriptor',
                    ],
                    [
                        'search' => 'jade',
                        'replace' => 'text/jade',
                    ],
                    [
                        'search' => 'jaii',
                        'replace' => 'image/jaii',
                    ],
                    [
                        'search' => 'jais',
                        'replace' => 'image/jais',
                    ],
                    [
                        'search' => 'jam',
                        'replace' => 'application/vnd.jam',
                    ],
                    [
                        'search' => 'jar',
                        'replace' => 'application/java-archive',
                    ],
                    [
                        'search' => 'jardiff',
                        'replace' => 'application/x-java-archive-diff',
                    ],
                    [
                        'search' => 'java',
                        'replace' => 'text/x-java-source',
                    ],
                    [
                        'search' => 'jfif',
                        'replace' => 'image/pjpeg',
                    ],
                    [
                        'search' => 'jhc',
                        'replace' => 'image/jphc',
                    ],
                    [
                        'search' => 'jisp',
                        'replace' => 'application/vnd.jisp',
                    ],
                    [
                        'search' => 'jls',
                        'replace' => 'image/jls',
                    ],
                    [
                        'search' => 'jlt',
                        'replace' => 'application/vnd.hp-jlyt',
                    ],
                    [
                        'search' => 'jng',
                        'replace' => 'image/x-jng',
                    ],
                    [
                        'search' => 'jnlp',
                        'replace' => 'application/x-java-jnlp-file',
                    ],
                    [
                        'search' => 'joda',
                        'replace' => 'application/vnd.joost.joda-archive',
                    ],
                    [
                        'search' => 'jp2',
                        'replace' => 'image/jp2',
                    ],
                    [
                        'search' => 'jpe',
                        'replace' => 'image/jpeg',
                    ],
                    [
                        'search' => 'jpeg',
                        'replace' => 'image/jpeg',
                    ],
                    [
                        'search' => 'jpf',
                        'replace' => 'image/jpx',
                    ],
                    [
                        'search' => 'jpg',
                        'replace' => 'image/jpeg',
                    ],
                    [
                        'search' => 'jpg2',
                        'replace' => 'image/jp2',
                    ],
                    [
                        'search' => 'jpgm',
                        'replace' => 'image/jpm',
                    ],
                    [
                        'search' => 'jpgv',
                        'replace' => 'video/jpeg',
                    ],
                    [
                        'search' => 'jph',
                        'replace' => 'image/jph',
                    ],
                    [
                        'search' => 'jpm',
                        'replace' => 'image/jpm',
                    ],
                    [
                        'search' => 'jpx',
                        'replace' => 'image/jpx',
                    ],
                    [
                        'search' => 'jrd',
                        'replace' => 'application/jrd+json',
                    ],
                    [
                        'search' => 'js',
                        'replace' => 'text/javascript',
                    ],
                    [
                        'search' => 'json',
                        'replace' => 'application/json',
                    ],
                    [
                        'search' => 'json-patch',
                        'replace' => 'application/json-patch+json',
                    ],
                    [
                        'search' => 'json5',
                        'replace' => 'application/json5',
                    ],
                    [
                        'search' => 'jsonld',
                        'replace' => 'application/ld+json',
                    ],
                    [
                        'search' => 'jsonml',
                        'replace' => 'application/jsonml+json',
                    ],
                    [
                        'search' => 'jsontd',
                        'replace' => 'application/td+json',
                    ],
                    [
                        'search' => 'jsontm',
                        'replace' => 'application/tm+json',
                    ],
                    [
                        'search' => 'jsx',
                        'replace' => 'text/jsx',
                    ],
                    [
                        'search' => 'jt',
                        'replace' => 'model/jt',
                    ],
                    [
                        'search' => 'jtd',
                        'replace' => 'text/vnd.esmertec.theme-descriptor',
                    ],
                    [
                        'search' => 'jxl',
                        'replace' => 'image/jxl',
                    ],
                    [
                        'search' => 'jxr',
                        'replace' => 'image/jxr',
                    ],
                    [
                        'search' => 'jxra',
                        'replace' => 'image/jxra',
                    ],
                    [
                        'search' => 'jxrs',
                        'replace' => 'image/jxrs',
                    ],
                    [
                        'search' => 'jxs',
                        'replace' => 'image/jxs',
                    ],
                    [
                        'search' => 'jxsc',
                        'replace' => 'image/jxsc',
                    ],
                    [
                        'search' => 'jxsi',
                        'replace' => 'image/jxsi',
                    ],
                    [
                        'search' => 'jxss',
                        'replace' => 'image/jxss',
                    ],
                    [
                        'search' => 'kar',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'karbon',
                        'replace' => 'application/vnd.kde.karbon',
                    ],
                    [
                        'search' => 'kcl',
                        'replace' => 'text/vnd.zoo.kcl',
                    ],
                    [
                        'search' => 'kcm',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'kdbx',
                        'replace' => 'application/x-keepass2',
                    ],
                    [
                        'search' => 'kdl',
                        'replace' => 'application/vnd.kdl',
                    ],
                    [
                        'search' => 'key',
                        'replace' => 'application/vnd.apple.keynote',
                    ],
                    [
                        'search' => 'kfo',
                        'replace' => 'application/vnd.kde.kformula',
                    ],
                    [
                        'search' => 'kia',
                        'replace' => 'application/vnd.kidspiration',
                    ],
                    [
                        'search' => 'kml',
                        'replace' => 'application/vnd.google-earth.kml+xml',
                    ],
                    [
                        'search' => 'kmp',
                        'replace' => 'application/vnd.keyman.kmp+zip',
                    ],
                    [
                        'search' => 'kmx',
                        'replace' => 'application/vnd.keyman.kmx',
                    ],
                    [
                        'search' => 'kmz',
                        'replace' => 'application/vnd.google-earth.kmz',
                    ],
                    [
                        'search' => 'kne',
                        'replace' => 'application/vnd.kinar',
                    ],
                    [
                        'search' => 'knp',
                        'replace' => 'application/vnd.kinar',
                    ],
                    [
                        'search' => 'kom',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'kon',
                        'replace' => 'application/vnd.kde.kontour',
                    ],
                    [
                        'search' => 'koz',
                        'replace' => 'audio/vnd.audiokoz',
                    ],
                    [
                        'search' => 'kpr',
                        'replace' => 'application/vnd.kde.kpresenter',
                    ],
                    [
                        'search' => 'kpt',
                        'replace' => 'application/vnd.kde.kpresenter',
                    ],
                    [
                        'search' => 'kpxx',
                        'replace' => 'application/vnd.ds-keypoint',
                    ],
                    [
                        'search' => 'ksp',
                        'replace' => 'application/vnd.kde.kspread',
                    ],
                    [
                        'search' => 'ktr',
                        'replace' => 'application/vnd.kahootz',
                    ],
                    [
                        'search' => 'ktx',
                        'replace' => 'image/ktx',
                    ],
                    [
                        'search' => 'ktx2',
                        'replace' => 'image/ktx2',
                    ],
                    [
                        'search' => 'ktz',
                        'replace' => 'application/vnd.kahootz',
                    ],
                    [
                        'search' => 'kwd',
                        'replace' => 'application/vnd.kde.kword',
                    ],
                    [
                        'search' => 'kwt',
                        'replace' => 'application/vnd.kde.kword',
                    ],
                    [
                        'search' => 'las',
                        'replace' => 'application/vnd.las',
                    ],
                    [
                        'search' => 'lasjson',
                        'replace' => 'application/vnd.las.las+json',
                    ],
                    [
                        'search' => 'lasxml',
                        'replace' => 'application/vnd.las.las+xml',
                    ],
                    [
                        'search' => 'latex',
                        'replace' => 'application/x-latex',
                    ],
                    [
                        'search' => 'laz',
                        'replace' => 'application/vnd.laszip',
                    ],
                    [
                        'search' => 'lbc',
                        'replace' => 'audio/ilbc',
                    ],
                    [
                        'search' => 'lbd',
                        'replace' => 'application/vnd.llamagraphics.life-balance.desktop',
                    ],
                    [
                        'search' => 'lbe',
                        'replace' => 'application/vnd.llamagraphics.life-balance.exchange+xml',
                    ],
                    [
                        'search' => 'lca',
                        'replace' => 'application/vnd.logipipe.circuit+zip',
                    ],
                    [
                        'search' => 'lcs',
                        'replace' => 'application/vnd.logipipe.circuit+zip',
                    ],
                    [
                        'search' => 'le',
                        'replace' => 'application/vnd.bluetooth.le.oob',
                    ],
                    [
                        'search' => 'les',
                        'replace' => 'application/vnd.hhe.lesson-player',
                    ],
                    [
                        'search' => 'less',
                        'replace' => 'text/less',
                    ],
                    [
                        'search' => 'lgr',
                        'replace' => 'application/lgr+xml',
                    ],
                    [
                        'search' => 'lha',
                        'replace' => 'application/x-lzh-compressed',
                    ],
                    [
                        'search' => 'lhzd',
                        'replace' => 'application/vnd.belightsoft.lhzd+zip',
                    ],
                    [
                        'search' => 'lhzl',
                        'replace' => 'application/vnd.belightsoft.lhzl+zip',
                    ],
                    [
                        'search' => 'line',
                        'replace' => 'application/vnd.nebumind.line',
                    ],
                    [
                        'search' => 'link66',
                        'replace' => 'application/vnd.route66.link66+xml',
                    ],
                    [
                        'search' => 'list',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'list3820',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'listafp',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'litcoffee',
                        'replace' => 'text/coffeescript',
                    ],
                    [
                        'search' => 'lmp',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'lnk',
                        'replace' => 'application/x-ms-shortcut',
                    ],
                    [
                        'search' => 'loas',
                        'replace' => 'audio/aac',
                    ],
                    [
                        'search' => 'log',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'loom',
                        'replace' => 'application/vnd.loom',
                    ],
                    [
                        'search' => 'lostsyncxml',
                        'replace' => 'application/lostsync+xml',
                    ],
                    [
                        'search' => 'lostxml',
                        'replace' => 'application/lost+xml',
                    ],
                    [
                        'search' => 'lot',
                        'replace' => 'video/lottie+json',
                    ],
                    [
                        'search' => 'lottie',
                        'replace' => 'application/zip+dotlottie',
                    ],
                    [
                        'search' => 'lpf',
                        'replace' => 'application/lpf+zip',
                    ],
                    [
                        'search' => 'lrf',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'lrm',
                        'replace' => 'application/vnd.ms-lrm',
                    ],
                    [
                        'search' => 'ltf',
                        'replace' => 'application/vnd.frogans.ltf',
                    ],
                    [
                        'search' => 'lua',
                        'replace' => 'text/x-lua',
                    ],
                    [
                        'search' => 'luac',
                        'replace' => 'application/x-lua-bytecode',
                    ],
                    [
                        'search' => 'lvp',
                        'replace' => 'audio/vnd.lucent.voice',
                    ],
                    [
                        'search' => 'lwp',
                        'replace' => 'application/vnd.lotus-wordpro',
                    ],
                    [
                        'search' => 'lxf',
                        'replace' => 'application/lxf',
                    ],
                    [
                        'search' => 'lzh',
                        'replace' => 'application/x-lzh-compressed',
                    ],
                    [
                        'search' => 'm',
                        'replace' => 'application/vnd.wolfram.mathematica.package',
                    ],
                    [
                        'search' => 'm13',
                        'replace' => 'application/x-msmediaview',
                    ],
                    [
                        'search' => 'm14',
                        'replace' => 'application/x-msmediaview',
                    ],
                    [
                        'search' => 'm1v',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'm21',
                        'replace' => 'application/mp21',
                    ],
                    [
                        'search' => 'm2a',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'm2t',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'm2ts',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'm2v',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'm3a',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'm3u',
                        'replace' => 'audio/x-mpegurl',
                    ],
                    [
                        'search' => 'm3u8',
                        'replace' => 'application/vnd.apple.mpegurl',
                    ],
                    [
                        'search' => 'm4a',
                        'replace' => 'audio/mp4',
                    ],
                    [
                        'search' => 'm4b',
                        'replace' => 'audio/mp4',
                    ],
                    [
                        'search' => 'm4p',
                        'replace' => 'application/mp4',
                    ],
                    [
                        'search' => 'm4s',
                        'replace' => 'video/iso.segment',
                    ],
                    [
                        'search' => 'm4u',
                        'replace' => 'video/vnd.mpegurl',
                    ],
                    [
                        'search' => 'm4v',
                        'replace' => 'video/x-m4v',
                    ],
                    [
                        'search' => 'ma',
                        'replace' => 'application/mathematica',
                    ],
                    [
                        'search' => 'mads',
                        'replace' => 'application/mads+xml',
                    ],
                    [
                        'search' => 'maei',
                        'replace' => 'application/mmt-aei+xml',
                    ],
                    [
                        'search' => 'mag',
                        'replace' => 'application/vnd.ecowin.chart',
                    ],
                    [
                        'search' => 'maker',
                        'replace' => 'application/vnd.framemaker',
                    ],
                    [
                        'search' => 'man',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'manifest',
                        'replace' => 'text/cache-manifest',
                    ],
                    [
                        'search' => 'map',
                        'replace' => 'application/json',
                    ],
                    [
                        'search' => 'mar',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'markdown',
                        'replace' => 'text/markdown',
                    ],
                    [
                        'search' => 'mathml',
                        'replace' => 'application/mathml+xml',
                    ],
                    [
                        'search' => 'mayf',
                        'replace' => 'application/prs.mayfile',
                    ],
                    [
                        'search' => 'mb',
                        'replace' => 'application/mathematica',
                    ],
                    [
                        'search' => 'mbk',
                        'replace' => 'application/vnd.mobius.mbk',
                    ],
                    [
                        'search' => 'mbox',
                        'replace' => 'application/mbox',
                    ],
                    [
                        'search' => 'mbsdf',
                        'replace' => 'application/vnd.mdl-mbsdf',
                    ],
                    [
                        'search' => 'mc1',
                        'replace' => 'application/vnd.medcalcdata',
                    ],
                    [
                        'search' => 'mc2',
                        'replace' => 'text/vnd.senx.warpscript',
                    ],
                    [
                        'search' => 'mcd',
                        'replace' => 'application/vnd.mcd',
                    ],
                    [
                        'search' => 'mcurl',
                        'replace' => 'text/vnd.curl.mcurl',
                    ],
                    [
                        'search' => 'md',
                        'replace' => 'text/markdown',
                    ],
                    [
                        'search' => 'mdb',
                        'replace' => 'application/x-msaccess',
                    ],
                    [
                        'search' => 'mdc',
                        'replace' => 'application/vnd.marlin.drm.mdcf',
                    ],
                    [
                        'search' => 'mdi',
                        'replace' => 'image/vnd.ms-modi',
                    ],
                    [
                        'search' => 'mdl',
                        'replace' => 'application/vnd.mdl',
                    ],
                    [
                        'search' => 'mdx',
                        'replace' => 'text/mdx',
                    ],
                    [
                        'search' => 'me',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'mermaid',
                        'replace' => 'application/vnd.mermaid',
                    ],
                    [
                        'search' => 'mesh',
                        'replace' => 'model/mesh',
                    ],
                    [
                        'search' => 'meta4',
                        'replace' => 'application/metalink4+xml',
                    ],
                    [
                        'search' => 'metalink',
                        'replace' => 'application/metalink+xml',
                    ],
                    [
                        'search' => 'mets',
                        'replace' => 'application/mets+xml',
                    ],
                    [
                        'search' => 'mf4',
                        'replace' => 'application/mf4',
                    ],
                    [
                        'search' => 'mfm',
                        'replace' => 'application/vnd.mfmp',
                    ],
                    [
                        'search' => 'mft',
                        'replace' => 'application/rpki-manifest',
                    ],
                    [
                        'search' => 'mgp',
                        'replace' => 'application/vnd.osgeo.mapguide.package',
                    ],
                    [
                        'search' => 'mgz',
                        'replace' => 'application/vnd.proteus.magazine',
                    ],
                    [
                        'search' => 'mhas',
                        'replace' => 'audio/mhas',
                    ],
                    [
                        'search' => 'mht',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'mhtml',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'mid',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'midi',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'midi2',
                        'replace' => 'audio/midi-clip',
                    ],
                    [
                        'search' => 'mie',
                        'replace' => 'application/x-mie',
                    ],
                    [
                        'search' => 'mif',
                        'replace' => 'application/vnd.mif',
                    ],
                    [
                        'search' => 'mime',
                        'replace' => 'message/rfc822',
                    ],
                    [
                        'search' => 'miz',
                        'replace' => 'text/mizar',
                    ],
                    [
                        'search' => 'mj2',
                        'replace' => 'video/mj2',
                    ],
                    [
                        'search' => 'mjp2',
                        'replace' => 'video/mj2',
                    ],
                    [
                        'search' => 'mjs',
                        'replace' => 'text/javascript',
                    ],
                    [
                        'search' => 'mk3d',
                        'replace' => 'video/matroska-3d',
                    ],
                    [
                        'search' => 'mka',
                        'replace' => 'audio/matroska',
                    ],
                    [
                        'search' => 'mkd',
                        'replace' => 'text/x-markdown',
                    ],
                    [
                        'search' => 'mks',
                        'replace' => 'video/x-matroska',
                    ],
                    [
                        'search' => 'mkv',
                        'replace' => 'video/matroska',
                    ],
                    [
                        'search' => 'ml2',
                        'replace' => 'application/vnd.sybyl.mol2',
                    ],
                    [
                        'search' => 'mlp',
                        'replace' => 'application/vnd.dolby.mlp',
                    ],
                    [
                        'search' => 'mmd',
                        'replace' => 'application/vnd.chipnuts.karaoke-mmd',
                    ],
                    [
                        'search' => 'mmdb',
                        'replace' => 'application/vnd.maxmind.maxmind-db',
                    ],
                    [
                        'search' => 'mmf',
                        'replace' => 'application/vnd.smaf',
                    ],
                    [
                        'search' => 'mml',
                        'replace' => 'text/mathml',
                    ],
                    [
                        'search' => 'mmr',
                        'replace' => 'image/vnd.fujixerox.edmics-mmr',
                    ],
                    [
                        'search' => 'mng',
                        'replace' => 'video/x-mng',
                    ],
                    [
                        'search' => 'mny',
                        'replace' => 'application/x-msmoney',
                    ],
                    [
                        'search' => 'mobi',
                        'replace' => 'application/x-mobipocket-ebook',
                    ],
                    [
                        'search' => 'mod',
                        'replace' => 'application/xml-dtd',
                    ],
                    [
                        'search' => 'model-inter',
                        'replace' => 'application/vnd.vd-study',
                    ],
                    [
                        'search' => 'modl',
                        'replace' => 'application/vnd.modl',
                    ],
                    [
                        'search' => 'mods',
                        'replace' => 'application/mods+xml',
                    ],
                    [
                        'search' => 'mol2',
                        'replace' => 'application/vnd.sybyl.mol2',
                    ],
                    [
                        'search' => 'mov',
                        'replace' => 'video/quicktime',
                    ],
                    [
                        'search' => 'movie',
                        'replace' => 'video/x-sgi-movie',
                    ],
                    [
                        'search' => 'mp1',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp2',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp21',
                        'replace' => 'application/mp21',
                    ],
                    [
                        'search' => 'mp2a',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp3',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mp4',
                        'replace' => 'video/mp4',
                    ],
                    [
                        'search' => 'mp4a',
                        'replace' => 'audio/mp4',
                    ],
                    [
                        'search' => 'mp4s',
                        'replace' => 'application/mp4',
                    ],
                    [
                        'search' => 'mp4v',
                        'replace' => 'video/mp4',
                    ],
                    [
                        'search' => 'mpc',
                        'replace' => 'application/vnd.mophun.certificate',
                    ],
                    [
                        'search' => 'mpd',
                        'replace' => 'application/dash+xml',
                    ],
                    [
                        'search' => 'mpdd',
                        'replace' => 'application/dashdelta',
                    ],
                    [
                        'search' => 'mpe',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'mpeg',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'mpf',
                        'replace' => 'application/media-policy-dataset+xml',
                    ],
                    [
                        'search' => 'mpg',
                        'replace' => 'video/mpeg',
                    ],
                    [
                        'search' => 'mpg4',
                        'replace' => 'video/mp4',
                    ],
                    [
                        'search' => 'mpga',
                        'replace' => 'audio/mpeg',
                    ],
                    [
                        'search' => 'mpkg',
                        'replace' => 'application/vnd.apple.installer+xml',
                    ],
                    [
                        'search' => 'mpm',
                        'replace' => 'application/vnd.blueice.multipass',
                    ],
                    [
                        'search' => 'mpn',
                        'replace' => 'application/vnd.mophun.application',
                    ],
                    [
                        'search' => 'mpp',
                        'replace' => 'application/vnd.ms-project',
                    ],
                    [
                        'search' => 'mpt',
                        'replace' => 'application/vnd.ms-project',
                    ],
                    [
                        'search' => 'mpw',
                        'replace' => 'application/vnd.exstream-empower+zip',
                    ],
                    [
                        'search' => 'mpy',
                        'replace' => 'application/vnd.ibm.minipay',
                    ],
                    [
                        'search' => 'mqy',
                        'replace' => 'application/vnd.mobius.mqy',
                    ],
                    [
                        'search' => 'mrc',
                        'replace' => 'application/marc',
                    ],
                    [
                        'search' => 'mrcx',
                        'replace' => 'application/marcxml+xml',
                    ],
                    [
                        'search' => 'ms',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'msa',
                        'replace' => 'application/vnd.msa-disk-image',
                    ],
                    [
                        'search' => 'mscml',
                        'replace' => 'application/mediaservercontrol+xml',
                    ],
                    [
                        'search' => 'mseed',
                        'replace' => 'application/vnd.fdsn.mseed',
                    ],
                    [
                        'search' => 'mseed3',
                        'replace' => 'application/vnd.fdsn.mseed',
                    ],
                    [
                        'search' => 'mseq',
                        'replace' => 'application/vnd.mseq',
                    ],
                    [
                        'search' => 'msf',
                        'replace' => 'application/vnd.epson.msf',
                    ],
                    [
                        'search' => 'msg',
                        'replace' => 'application/vnd.ms-outlook',
                    ],
                    [
                        'search' => 'msh',
                        'replace' => 'model/mesh',
                    ],
                    [
                        'search' => 'msi',
                        'replace' => 'application/x-msdownload',
                    ],
                    [
                        'search' => 'msix',
                        'replace' => 'application/msix',
                    ],
                    [
                        'search' => 'msixbundle',
                        'replace' => 'application/msixbundle',
                    ],
                    [
                        'search' => 'msl',
                        'replace' => 'application/vnd.mobius.msl',
                    ],
                    [
                        'search' => 'msm',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'msp',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'msty',
                        'replace' => 'application/vnd.muvee.style',
                    ],
                    [
                        'search' => 'mtl',
                        'replace' => 'model/mtl',
                    ],
                    [
                        'search' => 'mts',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'multitrack',
                        'replace' => 'audio/vnd.presonus.multitrack',
                    ],
                    [
                        'search' => 'mus',
                        'replace' => 'application/vnd.musician',
                    ],
                    [
                        'search' => 'musd',
                        'replace' => 'application/mmt-usd+xml',
                    ],
                    [
                        'search' => 'musicxml',
                        'replace' => 'application/vnd.recordare.musicxml+xml',
                    ],
                    [
                        'search' => 'mvb',
                        'replace' => 'application/x-msmediaview',
                    ],
                    [
                        'search' => 'mvt',
                        'replace' => 'application/vnd.mapbox-vector-tile',
                    ],
                    [
                        'search' => 'mwf',
                        'replace' => 'application/vnd.mfer',
                    ],
                    [
                        'search' => 'mxf',
                        'replace' => 'application/mxf',
                    ],
                    [
                        'search' => 'mxi',
                        'replace' => 'application/vnd.vd-study',
                    ],
                    [
                        'search' => 'mxl',
                        'replace' => 'application/vnd.recordare.musicxml',
                    ],
                    [
                        'search' => 'mxmf',
                        'replace' => 'audio/mobile-xmf',
                    ],
                    [
                        'search' => 'mxml',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'mxs',
                        'replace' => 'application/vnd.triscape.mxs',
                    ],
                    [
                        'search' => 'mxu',
                        'replace' => 'video/vnd.mpegurl',
                    ],
                    [
                        'search' => 'n-gage',
                        'replace' => 'application/vnd.nokia.n-gage.symbian.install',
                    ],
                    [
                        'search' => 'n3',
                        'replace' => 'text/n3',
                    ],
                    [
                        'search' => 'na',
                        'replace' => 'application/vnd.wfa.dpp',
                    ],
                    [
                        'search' => 'nb',
                        'replace' => 'application/mathematica',
                    ],
                    [
                        'search' => 'nbp',
                        'replace' => 'application/vnd.wolfram.player',
                    ],
                    [
                        'search' => 'nc',
                        'replace' => 'application/x-netcdf',
                    ],
                    [
                        'search' => 'ncx',
                        'replace' => 'application/x-dtbncx+xml',
                    ],
                    [
                        'search' => 'ndc',
                        'replace' => 'application/vnd.osa.netdeploy',
                    ],
                    [
                        'search' => 'nds',
                        'replace' => 'application/vnd.nintendo.nitro.rom',
                    ],
                    [
                        'search' => 'nebul',
                        'replace' => 'application/vnd.nebumind.line',
                    ],
                    [
                        'search' => 'nfo',
                        'replace' => 'text/x-nfo',
                    ],
                    [
                        'search' => 'ngdat',
                        'replace' => 'application/vnd.nokia.n-gage.data',
                    ],
                    [
                        'search' => 'nim',
                        'replace' => 'video/vnd.nokia.interleaved-multimedia',
                    ],
                    [
                        'search' => 'nimn',
                        'replace' => 'application/vnd.nimn',
                    ],
                    [
                        'search' => 'nitf',
                        'replace' => 'application/vnd.nitf',
                    ],
                    [
                        'search' => 'nlu',
                        'replace' => 'application/vnd.neurolanguage.nlu',
                    ],
                    [
                        'search' => 'nml',
                        'replace' => 'application/vnd.enliven',
                    ],
                    [
                        'search' => 'nnd',
                        'replace' => 'application/vnd.noblenet-directory',
                    ],
                    [
                        'search' => 'nns',
                        'replace' => 'application/vnd.noblenet-sealer',
                    ],
                    [
                        'search' => 'nnw',
                        'replace' => 'application/vnd.noblenet-web',
                    ],
                    [
                        'search' => 'not',
                        'replace' => 'application/vnd.sss-dtf',
                    ],
                    [
                        'search' => 'notebook',
                        'replace' => 'application/vnd.smart.notebook',
                    ],
                    [
                        'search' => 'npx',
                        'replace' => 'image/vnd.net-fpx',
                    ],
                    [
                        'search' => 'nq',
                        'replace' => 'application/n-quads',
                    ],
                    [
                        'search' => 'nsc',
                        'replace' => 'application/x-conference',
                    ],
                    [
                        'search' => 'nsf',
                        'replace' => 'application/vnd.lotus-notes',
                    ],
                    [
                        'search' => 'nt',
                        'replace' => 'application/n-triples',
                    ],
                    [
                        'search' => 'ntf',
                        'replace' => 'application/vnd.nitf',
                    ],
                    [
                        'search' => 'numbers',
                        'replace' => 'application/vnd.apple.numbers',
                    ],
                    [
                        'search' => 'nzb',
                        'replace' => 'application/x-nzb',
                    ],
                    [
                        'search' => 'oa2',
                        'replace' => 'application/vnd.fujitsu.oasys2',
                    ],
                    [
                        'search' => 'oa3',
                        'replace' => 'application/vnd.fujitsu.oasys3',
                    ],
                    [
                        'search' => 'oas',
                        'replace' => 'application/vnd.fujitsu.oasys',
                    ],
                    [
                        'search' => 'ob',
                        'replace' => 'application/vnd.1ob',
                    ],
                    [
                        'search' => 'obd',
                        'replace' => 'application/x-msbinder',
                    ],
                    [
                        'search' => 'obg',
                        'replace' => 'application/vnd.openblox.game-binary',
                    ],
                    [
                        'search' => 'obgx',
                        'replace' => 'application/vnd.openblox.game+xml',
                    ],
                    [
                        'search' => 'obj',
                        'replace' => 'model/obj',
                    ],
                    [
                        'search' => 'ocf',
                        'replace' => 'application/epub+zip',
                    ],
                    [
                        'search' => 'oda',
                        'replace' => 'application/oda',
                    ],
                    [
                        'search' => 'odb',
                        'replace' => 'application/vnd.oasis.opendocument.database',
                    ],
                    [
                        'search' => 'odc',
                        'replace' => 'application/vnd.oasis.opendocument.chart',
                    ],
                    [
                        'search' => 'odf',
                        'replace' => 'application/vnd.oasis.opendocument.formula',
                    ],
                    [
                        'search' => 'odft',
                        'replace' => 'application/vnd.oasis.opendocument.formula-template',
                    ],
                    [
                        'search' => 'odg',
                        'replace' => 'application/vnd.oasis.opendocument.graphics',
                    ],
                    [
                        'search' => 'odi',
                        'replace' => 'application/vnd.oasis.opendocument.image',
                    ],
                    [
                        'search' => 'odm',
                        'replace' => 'application/vnd.oasis.opendocument.text-master',
                    ],
                    [
                        'search' => 'odp',
                        'replace' => 'application/vnd.oasis.opendocument.presentation',
                    ],
                    [
                        'search' => 'ods',
                        'replace' => 'application/vnd.oasis.opendocument.spreadsheet',
                    ],
                    [
                        'search' => 'odt',
                        'replace' => 'application/vnd.oasis.opendocument.text',
                    ],
                    [
                        'search' => 'odx',
                        'replace' => 'application/odx',
                    ],
                    [
                        'search' => 'oeb',
                        'replace' => 'application/vnd.openeye.oeb',
                    ],
                    [
                        'search' => 'often',
                        'replace' => 'application/vnd.netfpx',
                    ],
                    [
                        'search' => 'oga',
                        'replace' => 'audio/ogg',
                    ],
                    [
                        'search' => 'ogex',
                        'replace' => 'model/vnd.opengex',
                    ],
                    [
                        'search' => 'ogg',
                        'replace' => 'audio/ogg',
                    ],
                    [
                        'search' => 'ogv',
                        'replace' => 'video/ogg',
                    ],
                    [
                        'search' => 'ogx',
                        'replace' => 'application/ogg',
                    ],
                    [
                        'search' => 'omdoc',
                        'replace' => 'application/omdoc+xml',
                    ],
                    [
                        'search' => 'omg',
                        'replace' => 'audio/atrac3',
                    ],
                    [
                        'search' => 'one',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onea',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onepkg',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onetmp',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onetoc',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'onetoc2',
                        'replace' => 'application/onenote',
                    ],
                    [
                        'search' => 'opf',
                        'replace' => 'application/oebps-package+xml',
                    ],
                    [
                        'search' => 'opml',
                        'replace' => 'text/x-opml',
                    ],
                    [
                        'search' => 'oprc',
                        'replace' => 'application/vnd.palm',
                    ],
                    [
                        'search' => 'opus',
                        'replace' => 'audio/opus',
                    ],
                    [
                        'search' => 'or3',
                        'replace' => 'application/vnd.lotus-organizer',
                    ],
                    [
                        'search' => 'org',
                        'replace' => 'application/vnd.lotus-organizer',
                    ],
                    [
                        'search' => 'orq',
                        'replace' => 'application/ocsp-request',
                    ],
                    [
                        'search' => 'ors',
                        'replace' => 'application/ocsp-response',
                    ],
                    [
                        'search' => 'osf',
                        'replace' => 'application/vnd.yamaha.openscoreformat',
                    ],
                    [
                        'search' => 'osfpvg',
                        'replace' => 'application/vnd.yamaha.openscoreformat.osfpvg+xml',
                    ],
                    [
                        'search' => 'osm',
                        'replace' => 'application/vnd.openstreetmap.data+xml',
                    ],
                    [
                        'search' => 'ota',
                        'replace' => 'application/vnd.android.ota',
                    ],
                    [
                        'search' => 'otc',
                        'replace' => 'application/vnd.oasis.opendocument.chart-template',
                    ],
                    [
                        'search' => 'otf',
                        'replace' => 'font/otf',
                    ],
                    [
                        'search' => 'otg',
                        'replace' => 'application/vnd.oasis.opendocument.graphics-template',
                    ],
                    [
                        'search' => 'oth',
                        'replace' => 'application/vnd.oasis.opendocument.text-web',
                    ],
                    [
                        'search' => 'oti',
                        'replace' => 'application/vnd.oasis.opendocument.image-template',
                    ],
                    [
                        'search' => 'otm',
                        'replace' => 'application/vnd.oasis.opendocument.text-master-template',
                    ],
                    [
                        'search' => 'otp',
                        'replace' => 'application/vnd.oasis.opendocument.presentation-template',
                    ],
                    [
                        'search' => 'ots',
                        'replace' => 'application/vnd.oasis.opendocument.spreadsheet-template',
                    ],
                    [
                        'search' => 'ott',
                        'replace' => 'application/vnd.oasis.opendocument.text-template',
                    ],
                    [
                        'search' => 'ova',
                        'replace' => 'application/x-virtualbox-ova',
                    ],
                    [
                        'search' => 'ovf',
                        'replace' => 'application/x-virtualbox-ovf',
                    ],
                    [
                        'search' => 'ovl',
                        'replace' => 'application/vnd.afpc.modca-overlay',
                    ],
                    [
                        'search' => 'owl',
                        'replace' => 'application/rdf+xml',
                    ],
                    [
                        'search' => 'oxlicg',
                        'replace' => 'application/vnd.oxli.countgraph',
                    ],
                    [
                        'search' => 'oxps',
                        'replace' => 'application/oxps',
                    ],
                    [
                        'search' => 'oxt',
                        'replace' => 'application/vnd.openofficeorg.extension',
                    ],
                    [
                        'search' => 'p',
                        'replace' => 'text/x-pascal',
                    ],
                    [
                        'search' => 'p10',
                        'replace' => 'application/pkcs10',
                    ],
                    [
                        'search' => 'p12',
                        'replace' => 'application/x-pkcs12',
                    ],
                    [
                        'search' => 'p21',
                        'replace' => 'application/p21',
                    ],
                    [
                        'search' => 'p2p',
                        'replace' => 'application/vnd.wfa.p2p',
                    ],
                    [
                        'search' => 'p7b',
                        'replace' => 'application/x-pkcs7-certificates',
                    ],
                    [
                        'search' => 'p7c',
                        'replace' => 'application/pkcs7-mime',
                    ],
                    [
                        'search' => 'p7m',
                        'replace' => 'application/pkcs7-mime',
                    ],
                    [
                        'search' => 'p7r',
                        'replace' => 'application/x-pkcs7-certreqresp',
                    ],
                    [
                        'search' => 'p7s',
                        'replace' => 'application/pkcs7-signature',
                    ],
                    [
                        'search' => 'p8',
                        'replace' => 'application/pkcs8',
                    ],
                    [
                        'search' => 'p8e',
                        'replace' => 'application/pkcs8-encrypted',
                    ],
                    [
                        'search' => 'pac',
                        'replace' => 'application/x-ns-proxy-autoconfig',
                    ],
                    [
                        'search' => 'package',
                        'replace' => 'application/vnd.autopackage',
                    ],
                    [
                        'search' => 'pages',
                        'replace' => 'application/vnd.apple.pages',
                    ],
                    [
                        'search' => 'parquet',
                        'replace' => 'application/vnd.apache.parquet',
                    ],
                    [
                        'search' => 'pas',
                        'replace' => 'text/x-pascal',
                    ],
                    [
                        'search' => 'paw',
                        'replace' => 'application/vnd.pawaafile',
                    ],
                    [
                        'search' => 'pbd',
                        'replace' => 'application/vnd.powerbuilder6',
                    ],
                    [
                        'search' => 'pbm',
                        'replace' => 'image/x-portable-bitmap',
                    ],
                    [
                        'search' => 'pcap',
                        'replace' => 'application/vnd.tcpdump.pcap',
                    ],
                    [
                        'search' => 'pcf',
                        'replace' => 'application/x-font-pcf',
                    ],
                    [
                        'search' => 'pcl',
                        'replace' => 'application/vnd.hp-pcl',
                    ],
                    [
                        'search' => 'pclxl',
                        'replace' => 'application/vnd.hp-pclxl',
                    ],
                    [
                        'search' => 'pct',
                        'replace' => 'image/x-pict',
                    ],
                    [
                        'search' => 'pcurl',
                        'replace' => 'application/vnd.curl.pcurl',
                    ],
                    [
                        'search' => 'pcx',
                        'replace' => 'image/x-pcx',
                    ],
                    [
                        'search' => 'pdb',
                        'replace' => 'application/vnd.palm',
                    ],
                    [
                        'search' => 'pde',
                        'replace' => 'text/x-processing',
                    ],
                    [
                        'search' => 'pdf',
                        'replace' => 'application/pdf',
                    ],
                    [
                        'search' => 'pdx',
                        'replace' => 'application/pdx',
                    ],
                    [
                        'search' => 'pem',
                        'replace' => 'application/x-x509-ca-cert',
                    ],
                    [
                        'search' => 'pfa',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'pfb',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'pfm',
                        'replace' => 'application/x-font-type1',
                    ],
                    [
                        'search' => 'pfr',
                        'replace' => 'application/font-tdpfr',
                    ],
                    [
                        'search' => 'pfx',
                        'replace' => 'application/x-pkcs12',
                    ],
                    [
                        'search' => 'pgb',
                        'replace' => 'image/vnd.globalgraphics.pgb',
                    ],
                    [
                        'search' => 'pgm',
                        'replace' => 'image/x-portable-graymap',
                    ],
                    [
                        'search' => 'pgn',
                        'replace' => 'application/x-chess-pgn',
                    ],
                    [
                        'search' => 'pgp',
                        'replace' => 'application/pgp-encrypted',
                    ],
                    [
                        'search' => 'php',
                        'replace' => 'application/x-httpd-php',
                    ],
                    [
                        'search' => 'pic',
                        'replace' => 'image/x-pict',
                    ],
                    [
                        'search' => 'pil',
                        'replace' => 'application/vnd.piaccess.application-licence',
                    ],
                    [
                        'search' => 'pkd',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'pkg',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'pki',
                        'replace' => 'application/pkixcmp',
                    ],
                    [
                        'search' => 'pkipath',
                        'replace' => 'application/pkix-pkipath',
                    ],
                    [
                        'search' => 'pkpass',
                        'replace' => 'application/vnd.apple.pkpass',
                    ],
                    [
                        'search' => 'pl',
                        'replace' => 'application/x-perl',
                    ],
                    [
                        'search' => 'plb',
                        'replace' => 'application/vnd.3gpp.pic-bw-large',
                    ],
                    [
                        'search' => 'plc',
                        'replace' => 'application/vnd.mobius.plc',
                    ],
                    [
                        'search' => 'plf',
                        'replace' => 'application/vnd.pocketlearn',
                    ],
                    [
                        'search' => 'plj',
                        'replace' => 'audio/vnd.everad.plj',
                    ],
                    [
                        'search' => 'plp',
                        'replace' => 'application/vnd.panoply',
                    ],
                    [
                        'search' => 'pls',
                        'replace' => 'application/pls+xml',
                    ],
                    [
                        'search' => 'pm',
                        'replace' => 'application/x-perl',
                    ],
                    [
                        'search' => 'pml',
                        'replace' => 'application/vnd.ctc-posml',
                    ],
                    [
                        'search' => 'pmtiles',
                        'replace' => 'application/vnd.pmtiles',
                    ],
                    [
                        'search' => 'png',
                        'replace' => 'image/png',
                    ],
                    [
                        'search' => 'pnm',
                        'replace' => 'image/x-portable-anymap',
                    ],
                    [
                        'search' => 'portpkg',
                        'replace' => 'application/vnd.macports.portpkg',
                    ],
                    [
                        'search' => 'pot',
                        'replace' => 'application/vnd.ms-powerpoint',
                    ],
                    [
                        'search' => 'potm',
                        'replace' => 'application/vnd.ms-powerpoint.template.macroenabled.12',
                    ],
                    [
                        'search' => 'potx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
                    ],
                    [
                        'search' => 'ppam',
                        'replace' => 'application/vnd.ms-powerpoint.addin.macroenabled.12',
                    ],
                    [
                        'search' => 'ppd',
                        'replace' => 'application/vnd.cups-ppd',
                    ],
                    [
                        'search' => 'ppd.gz',
                        'replace' => 'application/vnd.cups-ppd',
                    ],
                    [
                        'search' => 'ppkg',
                        'replace' => 'application/vnd.xmpie.ppkg',
                    ],
                    [
                        'search' => 'ppm',
                        'replace' => 'image/x-portable-pixmap',
                    ],
                    [
                        'search' => 'pps',
                        'replace' => 'application/vnd.ms-powerpoint',
                    ],
                    [
                        'search' => 'ppsm',
                        'replace' => 'application/vnd.ms-powerpoint.slideshow.macroenabled.12',
                    ],
                    [
                        'search' => 'ppsx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
                    ],
                    [
                        'search' => 'ppt',
                        'replace' => 'application/vnd.ms-powerpoint',
                    ],
                    [
                        'search' => 'pptm',
                        'replace' => 'application/vnd.ms-powerpoint.presentation.macroenabled.12',
                    ],
                    [
                        'search' => 'ppttc',
                        'replace' => 'application/vnd.think-cell.ppttc+json',
                    ],
                    [
                        'search' => 'pptx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    ],
                    [
                        'search' => 'pqa',
                        'replace' => 'application/vnd.palm',
                    ],
                    [
                        'search' => 'prc',
                        'replace' => 'application/x-mobipocket-ebook',
                    ],
                    [
                        'search' => 'pre',
                        'replace' => 'application/vnd.lotus-freelance',
                    ],
                    [
                        'search' => 'preminet',
                        'replace' => 'application/vnd.preminet',
                    ],
                    [
                        'search' => 'prf',
                        'replace' => 'application/pics-rules',
                    ],
                    [
                        'search' => 'provn',
                        'replace' => 'text/provenance-notation',
                    ],
                    [
                        'search' => 'provx',
                        'replace' => 'application/provenance+xml',
                    ],
                    [
                        'search' => 'prz',
                        'replace' => 'application/vnd.lotus-freelance',
                    ],
                    [
                        'search' => 'ps',
                        'replace' => 'application/postscript',
                    ],
                    [
                        'search' => 'psb',
                        'replace' => 'application/vnd.3gpp.pic-bw-small',
                    ],
                    [
                        'search' => 'psd',
                        'replace' => 'image/vnd.adobe.photoshop',
                    ],
                    [
                        'search' => 'pseg3820',
                        'replace' => 'application/vnd.ibm.modcap',
                    ],
                    [
                        'search' => 'psf',
                        'replace' => 'application/x-font-linux-psf',
                    ],
                    [
                        'search' => 'psfs',
                        'replace' => 'application/vnd.psfs',
                    ],
                    [
                        'search' => 'psg',
                        'replace' => 'application/vnd.afpc.modca-pagesegment',
                    ],
                    [
                        'search' => 'psid',
                        'replace' => 'audio/prs.sid',
                    ],
                    [
                        'search' => 'pskcxml',
                        'replace' => 'application/pskc+xml',
                    ],
                    [
                        'search' => 'pt',
                        'replace' => 'application/vnd.snesdev-page-table',
                    ],
                    [
                        'search' => 'pti',
                        'replace' => 'application/vnd.pvi.ptid1',
                    ],
                    [
                        'search' => 'ptid',
                        'replace' => 'application/vnd.pvi.ptid1',
                    ],
                    [
                        'search' => 'ptrom',
                        'replace' => 'application/vnd.snesdev-page-table',
                    ],
                    [
                        'search' => 'pub',
                        'replace' => 'application/x-mspublisher',
                    ],
                    [
                        'search' => 'pvb',
                        'replace' => 'application/vnd.3gpp.pic-bw-var',
                    ],
                    [
                        'search' => 'pwn',
                        'replace' => 'application/vnd.3m.post-it-notes',
                    ],
                    [
                        'search' => 'pya',
                        'replace' => 'audio/vnd.ms-playready.media.pya',
                    ],
                    [
                        'search' => 'pyo',
                        'replace' => 'model/vnd.pytha.pyox',
                    ],
                    [
                        'search' => 'pyon',
                        'replace' => 'application/vnd.pyon+json',
                    ],
                    [
                        'search' => 'pyox',
                        'replace' => 'model/vnd.pytha.pyox',
                    ],
                    [
                        'search' => 'pyv',
                        'replace' => 'video/vnd.ms-playready.media.pyv',
                    ],
                    [
                        'search' => 'qam',
                        'replace' => 'application/vnd.epson.quickanime',
                    ],
                    [
                        'search' => 'qbo',
                        'replace' => 'application/vnd.intu.qbo',
                    ],
                    [
                        'search' => 'qca',
                        'replace' => 'application/vnd.ericsson.quickcall',
                    ],
                    [
                        'search' => 'qcall',
                        'replace' => 'application/vnd.ericsson.quickcall',
                    ],
                    [
                        'search' => 'qcp',
                        'replace' => 'audio/evrc-qcp',
                    ],
                    [
                        'search' => 'qfx',
                        'replace' => 'application/vnd.intu.qfx',
                    ],
                    [
                        'search' => 'qps',
                        'replace' => 'application/vnd.publishare-delta-tree',
                    ],
                    [
                        'search' => 'qt',
                        'replace' => 'video/quicktime',
                    ],
                    [
                        'search' => 'quiz',
                        'replace' => 'application/vnd.quobject-quoxdocument',
                    ],
                    [
                        'search' => 'quox',
                        'replace' => 'application/vnd.quobject-quoxdocument',
                    ],
                    [
                        'search' => 'qwd',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qwt',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxb',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxd',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxl',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'qxt',
                        'replace' => 'application/vnd.quark.quarkxpress',
                    ],
                    [
                        'search' => 'ra',
                        'replace' => 'audio/x-pn-realaudio',
                    ],
                    [
                        'search' => 'ram',
                        'replace' => 'audio/x-pn-realaudio',
                    ],
                    [
                        'search' => 'raml',
                        'replace' => 'application/raml+yaml',
                    ],
                    [
                        'search' => 'rapd',
                        'replace' => 'application/route-apd+xml',
                    ],
                    [
                        'search' => 'rar',
                        'replace' => 'application/x-rar-compressed',
                    ],
                    [
                        'search' => 'ras',
                        'replace' => 'image/x-cmu-raster',
                    ],
                    [
                        'search' => 'rcprofile',
                        'replace' => 'application/vnd.ipunplugged.rcprofile',
                    ],
                    [
                        'search' => 'rct',
                        'replace' => 'application/prs.nprend',
                    ],
                    [
                        'search' => 'rdf',
                        'replace' => 'application/rdf+xml',
                    ],
                    [
                        'search' => 'rdf-crypt',
                        'replace' => 'application/prs.rdf-xml-crypt',
                    ],
                    [
                        'search' => 'rdz',
                        'replace' => 'application/vnd.data-vision.rdz',
                    ],
                    [
                        'search' => 'relo',
                        'replace' => 'application/p2p-overlay+xml',
                    ],
                    [
                        'search' => 'reload',
                        'replace' => 'application/vnd.resilient.logic',
                    ],
                    [
                        'search' => 'rep',
                        'replace' => 'application/vnd.businessobjects',
                    ],
                    [
                        'search' => 'req',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'request',
                        'replace' => 'application/vnd.nervana',
                    ],
                    [
                        'search' => 'res',
                        'replace' => 'application/x-dtbresource+xml',
                    ],
                    [
                        'search' => 'rfc',
                        'replace' => 'application/dns+json',
                    ],
                    [
                        'search' => 'rfcxml',
                        'replace' => 'application/rfc+xml',
                    ],
                    [
                        'search' => 'rgb',
                        'replace' => 'image/x-rgb',
                    ],
                    [
                        'search' => 'rgbe',
                        'replace' => 'image/vnd.radiance',
                    ],
                    [
                        'search' => 'rif',
                        'replace' => 'application/reginfo+xml',
                    ],
                    [
                        'search' => 'rip',
                        'replace' => 'audio/vnd.rip',
                    ],
                    [
                        'search' => 'ris',
                        'replace' => 'application/x-research-info-systems',
                    ],
                    [
                        'search' => 'rl',
                        'replace' => 'application/resource-lists+xml',
                    ],
                    [
                        'search' => 'rlc',
                        'replace' => 'image/vnd.fujixerox.edmics-rlc',
                    ],
                    [
                        'search' => 'rld',
                        'replace' => 'application/resource-lists-diff+xml',
                    ],
                    [
                        'search' => 'rlm',
                        'replace' => 'application/vnd.resilient.logic',
                    ],
                    [
                        'search' => 'rm',
                        'replace' => 'application/vnd.rn-realmedia',
                    ],
                    [
                        'search' => 'rmi',
                        'replace' => 'audio/midi',
                    ],
                    [
                        'search' => 'rmp',
                        'replace' => 'audio/x-pn-realaudio-plugin',
                    ],
                    [
                        'search' => 'rms',
                        'replace' => 'application/vnd.jcp.javame.midlet-rms',
                    ],
                    [
                        'search' => 'rmvb',
                        'replace' => 'application/vnd.rn-realmedia-vbr',
                    ],
                    [
                        'search' => 'rnc',
                        'replace' => 'application/relax-ng-compact-syntax',
                    ],
                    [
                        'search' => 'rnd',
                        'replace' => 'application/prs.nprend',
                    ],
                    [
                        'search' => 'rng',
                        'replace' => 'application/xml',
                    ],
                    [
                        'search' => 'roa',
                        'replace' => 'application/rpki-roa',
                    ],
                    [
                        'search' => 'roff',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'rp',
                        'replace' => 'application/vnd.relpipe',
                    ],
                    [
                        'search' => 'rp9',
                        'replace' => 'application/vnd.cloanto.rp9',
                    ],
                    [
                        'search' => 'rpm',
                        'replace' => 'application/x-redhat-package-manager',
                    ],
                    [
                        'search' => 'rpss',
                        'replace' => 'application/vnd.nokia.radio-presets',
                    ],
                    [
                        'search' => 'rpst',
                        'replace' => 'application/vnd.nokia.radio-preset',
                    ],
                    [
                        'search' => 'rq',
                        'replace' => 'application/sparql-query',
                    ],
                    [
                        'search' => 'rs',
                        'replace' => 'application/rls-services+xml',
                    ],
                    [
                        'search' => 'rsat',
                        'replace' => 'application/atsc-rsat+xml',
                    ],
                    [
                        'search' => 'rsd',
                        'replace' => 'application/rsd+xml',
                    ],
                    [
                        'search' => 'rsheet',
                        'replace' => 'application/urc-ressheet+xml',
                    ],
                    [
                        'search' => 'rsm',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'rss',
                        'replace' => 'application/rss+xml',
                    ],
                    [
                        'search' => 'rst',
                        'replace' => 'text/prs.fallenstein.rst',
                    ],
                    [
                        'search' => 'rtf',
                        'replace' => 'application/rtf',
                    ],
                    [
                        'search' => 'rtx',
                        'replace' => 'text/richtext',
                    ],
                    [
                        'search' => 'run',
                        'replace' => 'application/x-makeself',
                    ],
                    [
                        'search' => 'rusd',
                        'replace' => 'application/route-usd+xml',
                    ],
                    [
                        'search' => 'rxr',
                        'replace' => 'application/vnd.medicalholodeck.recordxr',
                    ],
                    [
                        'search' => 's',
                        'replace' => 'text/x-asm',
                    ],
                    [
                        'search' => 's11',
                        'replace' => 'video/vnd.sealed.mpeg1',
                    ],
                    [
                        'search' => 's14',
                        'replace' => 'video/vnd.sealed.mpeg4',
                    ],
                    [
                        'search' => 's1a',
                        'replace' => 'application/vnd.sealedmedia.softseal.pdf',
                    ],
                    [
                        'search' => 's1e',
                        'replace' => 'application/vnd.sealed.xls',
                    ],
                    [
                        'search' => 's1g',
                        'replace' => 'image/vnd.sealedmedia.softseal.gif',
                    ],
                    [
                        'search' => 's1h',
                        'replace' => 'application/vnd.sealedmedia.softseal.html',
                    ],
                    [
                        'search' => 's1j',
                        'replace' => 'image/vnd.sealedmedia.softseal.jpg',
                    ],
                    [
                        'search' => 's1m',
                        'replace' => 'audio/vnd.sealedmedia.softseal.mpeg',
                    ],
                    [
                        'search' => 's1n',
                        'replace' => 'image/vnd.sealed.png',
                    ],
                    [
                        'search' => 's1p',
                        'replace' => 'application/vnd.sealed.ppt',
                    ],
                    [
                        'search' => 's1q',
                        'replace' => 'video/vnd.sealedmedia.softseal.mov',
                    ],
                    [
                        'search' => 's1w',
                        'replace' => 'application/vnd.sealed.doc',
                    ],
                    [
                        'search' => 's3df',
                        'replace' => 'application/vnd.sealed.3df',
                    ],
                    [
                        'search' => 's3m',
                        'replace' => 'audio/s3m',
                    ],
                    [
                        'search' => 'sac',
                        'replace' => 'application/tamp-sequence-adjust-confirm',
                    ],
                    [
                        'search' => 'saf',
                        'replace' => 'application/vnd.yamaha.smaf-audio',
                    ],
                    [
                        'search' => 'sar',
                        'replace' => 'application/vnd.sar',
                    ],
                    [
                        'search' => 'sarif',
                        'replace' => 'application/sarif+json',
                    ],
                    [
                        'search' => 'sarif-external-properties',
                        'replace' => 'application/sarif-external-properties+json',
                    ],
                    [
                        'search' => 'sarif-external-properties.json',
                        'replace' => 'application/sarif-external-properties+json',
                    ],
                    [
                        'search' => 'sarif.json',
                        'replace' => 'application/sarif+json',
                    ],
                    [
                        'search' => 'sass',
                        'replace' => 'text/x-sass',
                    ],
                    [
                        'search' => 'sbml',
                        'replace' => 'application/sbml+xml',
                    ],
                    [
                        'search' => 'sbxls',
                        'replace' => 'application/vnd.r74n.sandboxels+json',
                    ],
                    [
                        'search' => 'sc',
                        'replace' => 'application/vnd.ibm.secure-container',
                    ],
                    [
                        'search' => 'scd',
                        'replace' => 'application/x-msschedule',
                    ],
                    [
                        'search' => 'scd.gz',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'sce',
                        'replace' => 'application/vnd.etsi.asic-e+zip',
                    ],
                    [
                        'search' => 'scim',
                        'replace' => 'application/scim+json',
                    ],
                    [
                        'search' => 'scl',
                        'replace' => 'application/vnd.sycle+xml',
                    ],
                    [
                        'search' => 'scld',
                        'replace' => 'application/vnd.doremir.scorecloud-binary-document',
                    ],
                    [
                        'search' => 'scm',
                        'replace' => 'application/vnd.lotus-screencam',
                    ],
                    [
                        'search' => 'scq',
                        'replace' => 'application/scvp-cv-request',
                    ],
                    [
                        'search' => 'scs',
                        'replace' => 'application/scvp-cv-response',
                    ],
                    [
                        'search' => 'scsf',
                        'replace' => 'application/vnd.sealed.csf',
                    ],
                    [
                        'search' => 'scss',
                        'replace' => 'text/x-scss',
                    ],
                    [
                        'search' => 'scurl',
                        'replace' => 'text/vnd.curl.scurl',
                    ],
                    [
                        'search' => 'sda',
                        'replace' => 'application/vnd.stardivision.draw',
                    ],
                    [
                        'search' => 'sdc',
                        'replace' => 'application/vnd.stardivision.calc',
                    ],
                    [
                        'search' => 'sdd',
                        'replace' => 'application/vnd.stardivision.impress',
                    ],
                    [
                        'search' => 'sdf.json',
                        'replace' => 'application/sdf+json',
                    ],
                    [
                        'search' => 'sdkd',
                        'replace' => 'application/vnd.solent.sdkm+xml',
                    ],
                    [
                        'search' => 'sdkm',
                        'replace' => 'application/vnd.solent.sdkm+xml',
                    ],
                    [
                        'search' => 'sdo',
                        'replace' => 'application/vnd.sealed.doc',
                    ],
                    [
                        'search' => 'sdoc',
                        'replace' => 'application/vnd.sealed.doc',
                    ],
                    [
                        'search' => 'sdp',
                        'replace' => 'application/sdp',
                    ],
                    [
                        'search' => 'sdw',
                        'replace' => 'application/vnd.stardivision.writer',
                    ],
                    [
                        'search' => 'sea',
                        'replace' => 'application/x-sea',
                    ],
                    [
                        'search' => 'see',
                        'replace' => 'application/vnd.seemail',
                    ],
                    [
                        'search' => 'seed',
                        'replace' => 'application/vnd.fdsn.seed',
                    ],
                    [
                        'search' => 'sem',
                        'replace' => 'application/vnd.sealed.eml',
                    ],
                    [
                        'search' => 'sema',
                        'replace' => 'application/vnd.sema',
                    ],
                    [
                        'search' => 'semd',
                        'replace' => 'application/vnd.semd',
                    ],
                    [
                        'search' => 'semf',
                        'replace' => 'application/vnd.semf',
                    ],
                    [
                        'search' => 'seml',
                        'replace' => 'application/vnd.sealed.eml',
                    ],
                    [
                        'search' => 'senml',
                        'replace' => 'application/senml+json',
                    ],
                    [
                        'search' => 'senml-etchc',
                        'replace' => 'application/senml-etch+cbor',
                    ],
                    [
                        'search' => 'senml-etchj',
                        'replace' => 'application/senml-etch+json',
                    ],
                    [
                        'search' => 'senmlc',
                        'replace' => 'application/senml+cbor',
                    ],
                    [
                        'search' => 'senmle',
                        'replace' => 'application/senml-exi',
                    ],
                    [
                        'search' => 'senmlx',
                        'replace' => 'application/senml+xml',
                    ],
                    [
                        'search' => 'sensml',
                        'replace' => 'application/sensml+json',
                    ],
                    [
                        'search' => 'sensmlc',
                        'replace' => 'application/sensml+cbor',
                    ],
                    [
                        'search' => 'sensmle',
                        'replace' => 'application/sensml-exi',
                    ],
                    [
                        'search' => 'sensmlx',
                        'replace' => 'application/sensml+xml',
                    ],
                    [
                        'search' => 'ser',
                        'replace' => 'application/java-serialized-object',
                    ],
                    [
                        'search' => 'setpay',
                        'replace' => 'application/set-payment-initiation',
                    ],
                    [
                        'search' => 'setreg',
                        'replace' => 'application/set-registration-initiation',
                    ],
                    [
                        'search' => 'sfc',
                        'replace' => 'application/vnd.nintendo.snes.rom',
                    ],
                    [
                        'search' => 'sfd',
                        'replace' => 'application/vnd.font-fontforge-sfd',
                    ],
                    [
                        'search' => 'sfd-hdstx',
                        'replace' => 'application/vnd.hydrostatix.sof-data',
                    ],
                    [
                        'search' => 'sfs',
                        'replace' => 'application/vnd.spotfire.sfs',
                    ],
                    [
                        'search' => 'sfv',
                        'replace' => 'text/x-sfv',
                    ],
                    [
                        'search' => 'sgi',
                        'replace' => 'image/sgi',
                    ],
                    [
                        'search' => 'sgif',
                        'replace' => 'image/vnd.sealedmedia.softseal.gif',
                    ],
                    [
                        'search' => 'sgl',
                        'replace' => 'application/vnd.stardivision.writer-global',
                    ],
                    [
                        'search' => 'sgm',
                        'replace' => 'text/sgml',
                    ],
                    [
                        'search' => 'sgml',
                        'replace' => 'text/sgml',
                    ],
                    [
                        'search' => 'sh',
                        'replace' => 'application/x-sh',
                    ],
                    [
                        'search' => 'shaclc',
                        'replace' => 'text/shaclc',
                    ],
                    [
                        'search' => 'shar',
                        'replace' => 'application/x-shar',
                    ],
                    [
                        'search' => 'shc',
                        'replace' => 'text/shaclc',
                    ],
                    [
                        'search' => 'shex',
                        'replace' => 'text/shex',
                    ],
                    [
                        'search' => 'shf',
                        'replace' => 'application/shf+xml',
                    ],
                    [
                        'search' => 'shp',
                        'replace' => 'application/vnd.shp',
                    ],
                    [
                        'search' => 'shtml',
                        'replace' => 'text/html',
                    ],
                    [
                        'search' => 'shx',
                        'replace' => 'application/vnd.shx',
                    ],
                    [
                        'search' => 'si',
                        'replace' => 'text/vnd.wap.si',
                    ],
                    [
                        'search' => 'sic',
                        'replace' => 'application/vnd.wap.sic',
                    ],
                    [
                        'search' => 'sid',
                        'replace' => 'audio/prs.sid',
                    ],
                    [
                        'search' => 'sieve',
                        'replace' => 'application/sieve',
                    ],
                    [
                        'search' => 'sig',
                        'replace' => 'application/pgp-signature',
                    ],
                    [
                        'search' => 'sil',
                        'replace' => 'audio/silk',
                    ],
                    [
                        'search' => 'silo',
                        'replace' => 'model/mesh',
                    ],
                    [
                        'search' => 'sipa',
                        'replace' => 'application/vnd.smintio.portals.archive',
                    ],
                    [
                        'search' => 'sis',
                        'replace' => 'application/vnd.symbian.install',
                    ],
                    [
                        'search' => 'sisx',
                        'replace' => 'application/vnd.symbian.install',
                    ],
                    [
                        'search' => 'sit',
                        'replace' => 'application/x-stuffit',
                    ],
                    [
                        'search' => 'sitx',
                        'replace' => 'application/x-stuffitx',
                    ],
                    [
                        'search' => 'siv',
                        'replace' => 'application/sieve',
                    ],
                    [
                        'search' => 'sjp',
                        'replace' => 'image/vnd.sealedmedia.softseal.jpg',
                    ],
                    [
                        'search' => 'sjpg',
                        'replace' => 'image/vnd.sealedmedia.softseal.jpg',
                    ],
                    [
                        'search' => 'skd',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'sketch',
                        'replace' => 'application/vnd.sketchometry',
                    ],
                    [
                        'search' => 'sketcho',
                        'replace' => 'application/vnd.sketchometry',
                    ],
                    [
                        'search' => 'skm',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'skp',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'skt',
                        'replace' => 'application/vnd.koan',
                    ],
                    [
                        'search' => 'sl',
                        'replace' => 'text/vnd.wap.sl',
                    ],
                    [
                        'search' => 'sla',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'sla.gz',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'slaz',
                        'replace' => 'application/vnd.scribus',
                    ],
                    [
                        'search' => 'slc',
                        'replace' => 'application/vnd.wap.slc',
                    ],
                    [
                        'search' => 'sldm',
                        'replace' => 'application/vnd.ms-powerpoint.slide.macroenabled.12',
                    ],
                    [
                        'search' => 'sldx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
                    ],
                    [
                        'search' => 'slim',
                        'replace' => 'text/slim',
                    ],
                    [
                        'search' => 'slm',
                        'replace' => 'text/slim',
                    ],
                    [
                        'search' => 'sls',
                        'replace' => 'application/route-s-tsid+xml',
                    ],
                    [
                        'search' => 'slt',
                        'replace' => 'application/vnd.epson.salt',
                    ],
                    [
                        'search' => 'sm',
                        'replace' => 'application/vnd.stepmania.stepchart',
                    ],
                    [
                        'search' => 'smc',
                        'replace' => 'application/vnd.nintendo.snes.rom',
                    ],
                    [
                        'search' => 'smf',
                        'replace' => 'application/vnd.stardivision.math',
                    ],
                    [
                        'search' => 'smh',
                        'replace' => 'application/vnd.sealed.mht',
                    ],
                    [
                        'search' => 'smht',
                        'replace' => 'application/vnd.sealed.mht',
                    ],
                    [
                        'search' => 'smi',
                        'replace' => 'application/smil+xml',
                    ],
                    [
                        'search' => 'smil',
                        'replace' => 'application/smil+xml',
                    ],
                    [
                        'search' => 'smk',
                        'replace' => 'video/vnd.radgamettools.smacker',
                    ],
                    [
                        'search' => 'sml',
                        'replace' => 'application/smil',
                    ],
                    [
                        'search' => 'smo',
                        'replace' => 'video/vnd.sealedmedia.softseal.mov',
                    ],
                    [
                        'search' => 'smov',
                        'replace' => 'video/vnd.sealedmedia.softseal.mov',
                    ],
                    [
                        'search' => 'smp',
                        'replace' => 'audio/vnd.sealedmedia.softseal.mpeg',
                    ],
                    [
                        'search' => 'smp3',
                        'replace' => 'audio/vnd.sealedmedia.softseal.mpeg',
                    ],
                    [
                        'search' => 'smpg',
                        'replace' => 'video/vnd.sealed.mpeg4',
                    ],
                    [
                        'search' => 'sms',
                        'replace' => 'application/vnd.3gpp.sms',
                    ],
                    [
                        'search' => 'smv',
                        'replace' => 'video/x-smv',
                    ],
                    [
                        'search' => 'smzip',
                        'replace' => 'application/vnd.stepmania.package',
                    ],
                    [
                        'search' => 'snd',
                        'replace' => 'audio/basic',
                    ],
                    [
                        'search' => 'snf',
                        'replace' => 'application/x-font-snf',
                    ],
                    [
                        'search' => 'so',
                        'replace' => 'application/octet-stream',
                    ],
                    [
                        'search' => 'soa',
                        'replace' => 'text/dns',
                    ],
                    [
                        'search' => 'sofa',
                        'replace' => 'audio/sofa',
                    ],
                    [
                        'search' => 'sos',
                        'replace' => 'text/vnd.sosi',
                    ],
                    [
                        'search' => 'spc',
                        'replace' => 'application/x-pkcs7-certificates',
                    ],
                    [
                        'search' => 'spd',
                        'replace' => 'application/vnd.sealedmedia.softseal.pdf',
                    ],
                    [
                        'search' => 'spdf',
                        'replace' => 'application/vnd.sealedmedia.softseal.pdf',
                    ],
                    [
                        'search' => 'spdx',
                        'replace' => 'text/spdx',
                    ],
                    [
                        'search' => 'spdx.json',
                        'replace' => 'application/spdx+json',
                    ],
                    [
                        'search' => 'spf',
                        'replace' => 'application/vnd.yamaha.smaf-phrase',
                    ],
                    [
                        'search' => 'spl',
                        'replace' => 'application/x-futuresplash',
                    ],
                    [
                        'search' => 'spn',
                        'replace' => 'image/vnd.sealed.png',
                    ],
                    [
                        'search' => 'spng',
                        'replace' => 'image/vnd.sealed.png',
                    ],
                    [
                        'search' => 'spo',
                        'replace' => 'text/vnd.in3d.spot',
                    ],
                    [
                        'search' => 'spot',
                        'replace' => 'text/vnd.in3d.spot',
                    ],
                    [
                        'search' => 'spp',
                        'replace' => 'application/scvp-vp-response',
                    ],
                    [
                        'search' => 'sppt',
                        'replace' => 'application/vnd.sealed.ppt',
                    ],
                    [
                        'search' => 'spq',
                        'replace' => 'application/scvp-vp-request',
                    ],
                    [
                        'search' => 'spx',
                        'replace' => 'audio/ogg',
                    ],
                    [
                        'search' => 'sql',
                        'replace' => 'application/sql',
                    ],
                    [
                        'search' => 'sqlite',
                        'replace' => 'application/vnd.sqlite3',
                    ],
                    [
                        'search' => 'sqlite3',
                        'replace' => 'application/vnd.sqlite3',
                    ],
                    [
                        'search' => 'sr',
                        'replace' => 'application/vnd.sigrok.session',
                    ],
                    [
                        'search' => 'src',
                        'replace' => 'application/x-wais-source',
                    ],
                    [
                        'search' => 'srt',
                        'replace' => 'application/x-subrip',
                    ],
                    [
                        'search' => 'sru',
                        'replace' => 'application/sru+xml',
                    ],
                    [
                        'search' => 'srx',
                        'replace' => 'application/sparql-results+xml',
                    ],
                    [
                        'search' => 'ssdl',
                        'replace' => 'application/ssdl+xml',
                    ],
                    [
                        'search' => 'sse',
                        'replace' => 'application/vnd.kodak-descriptor',
                    ],
                    [
                        'search' => 'ssf',
                        'replace' => 'application/vnd.epson.ssf',
                    ],
                    [
                        'search' => 'ssml',
                        'replace' => 'application/ssml+xml',
                    ],
                    [
                        'search' => 'ssv',
                        'replace' => 'application/vnd.shade-save-file',
                    ],
                    [
                        'search' => 'ssvc',
                        'replace' => 'application/vnd.crypto-shade-file',
                    ],
                    [
                        'search' => 'ssw',
                        'replace' => 'video/vnd.sealed.swf',
                    ],
                    [
                        'search' => 'sswf',
                        'replace' => 'video/vnd.sealed.swf',
                    ],
                    [
                        'search' => 'st',
                        'replace' => 'application/vnd.sailingtracker.track',
                    ],
                    [
                        'search' => 'stc',
                        'replace' => 'application/vnd.sun.xml.calc.template',
                    ],
                    [
                        'search' => 'std',
                        'replace' => 'application/vnd.sun.xml.draw.template',
                    ],
                    [
                        'search' => 'step',
                        'replace' => 'model/step',
                    ],
                    [
                        'search' => 'stf',
                        'replace' => 'application/vnd.wt.stf',
                    ],
                    [
                        'search' => 'sti',
                        'replace' => 'application/vnd.sun.xml.impress.template',
                    ],
                    [
                        'search' => 'stif',
                        'replace' => 'application/vnd.sealed.tiff',
                    ],
                    [
                        'search' => 'stix',
                        'replace' => 'application/stix+json',
                    ],
                    [
                        'search' => 'stk',
                        'replace' => 'application/hyperstudio',
                    ],
                    [
                        'search' => 'stl',
                        'replace' => 'model/stl',
                    ],
                    [
                        'search' => 'stm',
                        'replace' => 'application/vnd.sealedmedia.softseal.html',
                    ],
                    [
                        'search' => 'stml',
                        'replace' => 'application/vnd.sealedmedia.softseal.html',
                    ],
                    [
                        'search' => 'stp',
                        'replace' => 'model/step',
                    ],
                    [
                        'search' => 'stpnc',
                        'replace' => 'model/step',
                    ],
                    [
                        'search' => 'stpx',
                        'replace' => 'model/step+xml',
                    ],
                    [
                        'search' => 'stpxz',
                        'replace' => 'model/step-xml+zip',
                    ],
                    [
                        'search' => 'stpz',
                        'replace' => 'model/step+zip',
                    ],
                    [
                        'search' => 'str',
                        'replace' => 'application/vnd.pg.format',
                    ],
                    [
                        'search' => 'study-inter',
                        'replace' => 'application/vnd.vd-study',
                    ],
                    [
                        'search' => 'stw',
                        'replace' => 'application/vnd.sun.xml.writer.template',
                    ],
                    [
                        'search' => 'styl',
                        'replace' => 'text/stylus',
                    ],
                    [
                        'search' => 'stylus',
                        'replace' => 'text/stylus',
                    ],
                    [
                        'search' => 'sub',
                        'replace' => 'image/vnd.dvb.subtitle',
                    ],
                    [
                        'search' => 'such',
                        'replace' => 'audio/vnd.dts.uhd',
                    ],
                    [
                        'search' => 'suit',
                        'replace' => 'application/suit-envelope+cose',
                    ],
                    [
                        'search' => 'super',
                        'replace' => 'application/vnd.superfile.super',
                    ],
                    [
                        'search' => 'sus',
                        'replace' => 'application/vnd.sus-calendar',
                    ],
                    [
                        'search' => 'susp',
                        'replace' => 'application/vnd.sus-calendar',
                    ],
                    [
                        'search' => 'sv4cpio',
                        'replace' => 'application/x-sv4cpio',
                    ],
                    [
                        'search' => 'sv4crc',
                        'replace' => 'application/x-sv4crc',
                    ],
                    [
                        'search' => 'svc',
                        'replace' => 'application/vnd.dvb.service',
                    ],
                    [
                        'search' => 'svd',
                        'replace' => 'application/vnd.svd',
                    ],
                    [
                        'search' => 'svg',
                        'replace' => 'image/svg+xml',
                    ],
                    [
                        'search' => 'svgz',
                        'replace' => 'image/svg+xml',
                    ],
                    [
                        'search' => 'swa',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'swf',
                        'replace' => 'application/x-shockwave-flash',
                    ],
                    [
                        'search' => 'swi',
                        'replace' => 'application/vnd.aristanetworks.swi',
                    ],
                    [
                        'search' => 'swidtag',
                        'replace' => 'application/swid+xml',
                    ],
                    [
                        'search' => 'sxc',
                        'replace' => 'application/vnd.sun.xml.calc',
                    ],
                    [
                        'search' => 'sxd',
                        'replace' => 'application/vnd.sun.xml.draw',
                    ],
                    [
                        'search' => 'sxg',
                        'replace' => 'application/vnd.sun.xml.writer.global',
                    ],
                    [
                        'search' => 'sxi',
                        'replace' => 'application/vnd.sun.xml.impress',
                    ],
                    [
                        'search' => 'sxl',
                        'replace' => 'application/vnd.sealed.xls',
                    ],
                    [
                        'search' => 'sxls',
                        'replace' => 'application/vnd.sealed.xls',
                    ],
                    [
                        'search' => 'sxm',
                        'replace' => 'application/vnd.sun.xml.math',
                    ],
                    [
                        'search' => 'sxw',
                        'replace' => 'application/vnd.sun.xml.writer',
                    ],
                    [
                        'search' => 'sy2',
                        'replace' => 'application/vnd.sybyl.mol2',
                    ],
                    [
                        'search' => 'syft.json',
                        'replace' => 'application/vnd.syft+json',
                    ],
                    [
                        'search' => 'systemverify',
                        'replace' => 'application/vnd.pp.systemverify+xml',
                    ],
                    [
                        'search' => 't',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 't3',
                        'replace' => 'application/x-t3vm-image',
                    ],
                    [
                        'search' => 't38',
                        'replace' => 'image/t38',
                    ],
                    [
                        'search' => 'taglet',
                        'replace' => 'application/vnd.mynfc',
                    ],
                    [
                        'search' => 'tak',
                        'replace' => 'application/rpki-signed-tal',
                    ],
                    [
                        'search' => 'tam',
                        'replace' => 'application/vnd.onepager',
                    ],
                    [
                        'search' => 'tamp',
                        'replace' => 'application/vnd.onepagertamp',
                    ],
                    [
                        'search' => 'tamx',
                        'replace' => 'application/vnd.onepagertamx',
                    ],
                    [
                        'search' => 'tao',
                        'replace' => 'application/vnd.tao.intent-module-archive',
                    ],
                    [
                        'search' => 'tap',
                        'replace' => 'image/vnd.tencent.tap',
                    ],
                    [
                        'search' => 'tar',
                        'replace' => 'application/x-tar',
                    ],
                    [
                        'search' => 'tat',
                        'replace' => 'application/vnd.onepagertat',
                    ],
                    [
                        'search' => 'tatp',
                        'replace' => 'application/vnd.onepagertatp',
                    ],
                    [
                        'search' => 'tatx',
                        'replace' => 'application/vnd.onepagertatx',
                    ],
                    [
                        'search' => 'tau',
                        'replace' => 'application/tamp-apex-update',
                    ],
                    [
                        'search' => 'tbz2',
                        'replace' => 'application/vnd.gentoo.xpak',
                    ],
                    [
                        'search' => 'tcap',
                        'replace' => 'application/vnd.3gpp2.tcap',
                    ],
                    [
                        'search' => 'tcl',
                        'replace' => 'application/x-tcl',
                    ],
                    [
                        'search' => 'tcu',
                        'replace' => 'application/tamp-community-update',
                    ],
                    [
                        'search' => 'td',
                        'replace' => 'application/urc-targetdesc+xml',
                    ],
                    [
                        'search' => 'teacher',
                        'replace' => 'application/vnd.smart.teacher',
                    ],
                    [
                        'search' => 'tei',
                        'replace' => 'application/tei+xml',
                    ],
                    [
                        'search' => 'teicorpus',
                        'replace' => 'application/tei+xml',
                    ],
                    [
                        'search' => 'ter',
                        'replace' => 'application/tamp-error',
                    ],
                    [
                        'search' => 'tex',
                        'replace' => 'application/x-tex',
                    ],
                    [
                        'search' => 'texi',
                        'replace' => 'application/x-texinfo',
                    ],
                    [
                        'search' => 'texinfo',
                        'replace' => 'application/x-texinfo',
                    ],
                    [
                        'search' => 'text',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'tfi',
                        'replace' => 'application/thraud+xml',
                    ],
                    [
                        'search' => 'tfm',
                        'replace' => 'application/x-tex-tfm',
                    ],
                    [
                        'search' => 'tfx',
                        'replace' => 'image/tiff-fx',
                    ],
                    [
                        'search' => 'tga',
                        'replace' => 'image/x-tga',
                    ],
                    [
                        'search' => 'thmx',
                        'replace' => 'application/vnd.ms-officetheme',
                    ],
                    [
                        'search' => 'tif',
                        'replace' => 'image/tiff',
                    ],
                    [
                        'search' => 'tiff',
                        'replace' => 'image/tiff',
                    ],
                    [
                        'search' => 'tk',
                        'replace' => 'application/x-tcl',
                    ],
                    [
                        'search' => 'tlclient',
                        'replace' => 'application/vnd.cendio.thinlinc.clientconf',
                    ],
                    [
                        'search' => 'tm.json',
                        'replace' => 'application/tm+json',
                    ],
                    [
                        'search' => 'tm.jsonld',
                        'replace' => 'application/tm+json',
                    ],
                    [
                        'search' => 'tmo',
                        'replace' => 'application/vnd.tmobile-livetv',
                    ],
                    [
                        'search' => 'toml',
                        'replace' => 'application/toml',
                    ],
                    [
                        'search' => 'torrent',
                        'replace' => 'application/x-bittorrent',
                    ],
                    [
                        'search' => 'tpl',
                        'replace' => 'application/vnd.groove-tool-template',
                    ],
                    [
                        'search' => 'tpt',
                        'replace' => 'application/vnd.trid.tpt',
                    ],
                    [
                        'search' => 'tr',
                        'replace' => 'text/troff',
                    ],
                    [
                        'search' => 'tra',
                        'replace' => 'application/vnd.trueapp',
                    ],
                    [
                        'search' => 'tree',
                        'replace' => 'application/vnd.rainstor.data',
                    ],
                    [
                        'search' => 'trig',
                        'replace' => 'application/trig',
                    ],
                    [
                        'search' => 'trm',
                        'replace' => 'application/x-msterminal',
                    ],
                    [
                        'search' => 'ts',
                        'replace' => 'video/mp2t',
                    ],
                    [
                        'search' => 'tsa',
                        'replace' => 'application/tamp-sequence-adjust',
                    ],
                    [
                        'search' => 'tsd',
                        'replace' => 'application/timestamped-data',
                    ],
                    [
                        'search' => 'tsq',
                        'replace' => 'application/timestamp-query',
                    ],
                    [
                        'search' => 'tsr',
                        'replace' => 'application/timestamp-reply',
                    ],
                    [
                        'search' => 'tst',
                        'replace' => 'application/vnd.etsi.timestamp-token',
                    ],
                    [
                        'search' => 'tsv',
                        'replace' => 'text/tab-separated-values',
                    ],
                    [
                        'search' => 'ttc',
                        'replace' => 'font/collection',
                    ],
                    [
                        'search' => 'ttf',
                        'replace' => 'font/ttf',
                    ],
                    [
                        'search' => 'ttl',
                        'replace' => 'text/turtle',
                    ],
                    [
                        'search' => 'ttml',
                        'replace' => 'application/ttml+xml',
                    ],
                    [
                        'search' => 'tuc',
                        'replace' => 'application/tamp-update-confirm',
                    ],
                    [
                        'search' => 'tur',
                        'replace' => 'application/tamp-update',
                    ],
                    [
                        'search' => 'twd',
                        'replace' => 'application/vnd.simtech-mindmapper',
                    ],
                    [
                        'search' => 'twds',
                        'replace' => 'application/vnd.simtech-mindmapper',
                    ],
                    [
                        'search' => 'txd',
                        'replace' => 'application/vnd.genomatix.tuxedo',
                    ],
                    [
                        'search' => 'txf',
                        'replace' => 'application/vnd.mobius.txf',
                    ],
                    [
                        'search' => 'txt',
                        'replace' => 'text/plain',
                    ],
                    [
                        'search' => 'typ',
                        'replace' => 'text/vnd.typst',
                    ],
                    [
                        'search' => 'u32',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'u3d',
                        'replace' => 'model/u3d',
                    ],
                    [
                        'search' => 'u8dsn',
                        'replace' => 'message/global-delivery-status',
                    ],
                    [
                        'search' => 'u8hdr',
                        'replace' => 'message/global-headers',
                    ],
                    [
                        'search' => 'u8mdn',
                        'replace' => 'message/global-disposition-notification',
                    ],
                    [
                        'search' => 'u8msg',
                        'replace' => 'message/global',
                    ],
                    [
                        'search' => 'ubj',
                        'replace' => 'application/ubjson',
                    ],
                    [
                        'search' => 'uccs',
                        'replace' => 'application/uccs+cbor',
                    ],
                    [
                        'search' => 'udeb',
                        'replace' => 'application/x-debian-package',
                    ],
                    [
                        'search' => 'ufd',
                        'replace' => 'application/vnd.ufdl',
                    ],
                    [
                        'search' => 'ufdl',
                        'replace' => 'application/vnd.ufdl',
                    ],
                    [
                        'search' => 'uis',
                        'replace' => 'application/urc-uisocketdesc+xml',
                    ],
                    [
                        'search' => 'ujcs',
                        'replace' => 'application/ujcs+json',
                    ],
                    [
                        'search' => 'ulx',
                        'replace' => 'application/x-glulx',
                    ],
                    [
                        'search' => 'umj',
                        'replace' => 'application/vnd.umajin',
                    ],
                    [
                        'search' => 'unityweb',
                        'replace' => 'application/vnd.unity',
                    ],
                    [
                        'search' => 'uo',
                        'replace' => 'application/vnd.uoml+xml',
                    ],
                    [
                        'search' => 'uoml',
                        'replace' => 'application/vnd.uoml+xml',
                    ],
                    [
                        'search' => 'upa',
                        'replace' => 'application/vnd.hbci',
                    ],
                    [
                        'search' => 'uri',
                        'replace' => 'text/uri-list',
                    ],
                    [
                        'search' => 'uric',
                        'replace' => 'text/vnd.si.uricatalogue',
                    ],
                    [
                        'search' => 'urim',
                        'replace' => 'application/vnd.uri-map',
                    ],
                    [
                        'search' => 'urimap',
                        'replace' => 'application/vnd.uri-map',
                    ],
                    [
                        'search' => 'uris',
                        'replace' => 'text/uri-list',
                    ],
                    [
                        'search' => 'urls',
                        'replace' => 'text/uri-list',
                    ],
                    [
                        'search' => 'usda',
                        'replace' => 'model/vnd.usda',
                    ],
                    [
                        'search' => 'usdz',
                        'replace' => 'model/vnd.usdz+zip',
                    ],
                    [
                        'search' => 'ustar',
                        'replace' => 'application/x-ustar',
                    ],
                    [
                        'search' => 'utz',
                        'replace' => 'application/vnd.uiq.theme',
                    ],
                    [
                        'search' => 'uu',
                        'replace' => 'text/x-uuencode',
                    ],
                    [
                        'search' => 'uva',
                        'replace' => 'audio/vnd.dece.audio',
                    ],
                    [
                        'search' => 'uvd',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvf',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvg',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvh',
                        'replace' => 'video/vnd.dece.hd',
                    ],
                    [
                        'search' => 'uvi',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvm',
                        'replace' => 'video/vnd.dece.mobile',
                    ],
                    [
                        'search' => 'uvp',
                        'replace' => 'video/vnd.dece.pd',
                    ],
                    [
                        'search' => 'uvs',
                        'replace' => 'video/vnd.dece.sd',
                    ],
                    [
                        'search' => 'uvt',
                        'replace' => 'application/vnd.dece.ttml+xml',
                    ],
                    [
                        'search' => 'uvu',
                        'replace' => 'video/vnd.uvvu.mp4',
                    ],
                    [
                        'search' => 'uvv',
                        'replace' => 'video/vnd.dece.video',
                    ],
                    [
                        'search' => 'uvva',
                        'replace' => 'audio/vnd.dece.audio',
                    ],
                    [
                        'search' => 'uvvd',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvvf',
                        'replace' => 'application/vnd.dece.data',
                    ],
                    [
                        'search' => 'uvvg',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvvh',
                        'replace' => 'video/vnd.dece.hd',
                    ],
                    [
                        'search' => 'uvvi',
                        'replace' => 'image/vnd.dece.graphic',
                    ],
                    [
                        'search' => 'uvvm',
                        'replace' => 'video/vnd.dece.mobile',
                    ],
                    [
                        'search' => 'uvvp',
                        'replace' => 'video/vnd.dece.pd',
                    ],
                    [
                        'search' => 'uvvs',
                        'replace' => 'video/vnd.dece.sd',
                    ],
                    [
                        'search' => 'uvvt',
                        'replace' => 'application/vnd.dece.ttml+xml',
                    ],
                    [
                        'search' => 'uvvu',
                        'replace' => 'video/vnd.uvvu.mp4',
                    ],
                    [
                        'search' => 'uvvv',
                        'replace' => 'video/vnd.dece.video',
                    ],
                    [
                        'search' => 'uvvx',
                        'replace' => 'application/vnd.dece.unspecified',
                    ],
                    [
                        'search' => 'uvvz',
                        'replace' => 'application/vnd.dece.zip',
                    ],
                    [
                        'search' => 'uvx',
                        'replace' => 'application/vnd.dece.unspecified',
                    ],
                    [
                        'search' => 'uvz',
                        'replace' => 'application/vnd.dece.zip',
                    ],
                    [
                        'search' => 'various',
                        'replace' => 'audio/vnd.dts.uhd',
                    ],
                    [
                        'search' => 'vbk',
                        'replace' => 'audio/vnd.nortel.vbk',
                    ],
                    [
                        'search' => 'vbox',
                        'replace' => 'application/x-virtualbox-vbox',
                    ],
                    [
                        'search' => 'vbox-extpack',
                        'replace' => 'application/x-virtualbox-vbox-extpack',
                    ],
                    [
                        'search' => 'vc',
                        'replace' => 'application/vc',
                    ],
                    [
                        'search' => 'vcard',
                        'replace' => 'text/vcard',
                    ],
                    [
                        'search' => 'vcd',
                        'replace' => 'application/x-cdlink',
                    ],
                    [
                        'search' => 'vcf',
                        'replace' => 'text/vcard',
                    ],
                    [
                        'search' => 'vcf.bz2',
                        'replace' => 'application/prs.vcfbzip2',
                    ],
                    [
                        'search' => 'vcfbz2',
                        'replace' => 'application/prs.vcfbzip2',
                    ],
                    [
                        'search' => 'vcfbzip2',
                        'replace' => 'application/prs.vcfbzip2',
                    ],
                    [
                        'search' => 'vcg',
                        'replace' => 'application/vnd.groove-vcard',
                    ],
                    [
                        'search' => 'vcj',
                        'replace' => 'application/voucher-cms+json',
                    ],
                    [
                        'search' => 'vcs',
                        'replace' => 'text/x-vcalendar',
                    ],
                    [
                        'search' => 'vcx',
                        'replace' => 'application/vnd.vcx',
                    ],
                    [
                        'search' => 'vd',
                        'replace' => 'application/vividence.scriptfile',
                    ],
                    [
                        'search' => 'vdi',
                        'replace' => 'application/x-virtualbox-vdi',
                    ],
                    [
                        'search' => 'vds',
                        'replace' => 'model/vnd.sap.vds',
                    ],
                    [
                        'search' => 'vdx',
                        'replace' => 'application/vnd.ms-visio.viewer',
                    ],
                    [
                        'search' => 'ves',
                        'replace' => 'application/vnd.ves.encrypted',
                    ],
                    [
                        'search' => 'vfk',
                        'replace' => 'text/vnd.exchangeable',
                    ],
                    [
                        'search' => 'vfr',
                        'replace' => 'application/vnd.tml',
                    ],
                    [
                        'search' => 'vhd',
                        'replace' => 'application/x-virtualbox-vhd',
                    ],
                    [
                        'search' => 'viaframe',
                        'replace' => 'application/vnd.tml',
                    ],
                    [
                        'search' => 'vis',
                        'replace' => 'application/vnd.visionary',
                    ],
                    [
                        'search' => 'viv',
                        'replace' => 'video/vnd.vivo',
                    ],
                    [
                        'search' => 'vjj',
                        'replace' => 'application/voucher-jws+json',
                    ],
                    [
                        'search' => 'vmdk',
                        'replace' => 'application/x-virtualbox-vmdk',
                    ],
                    [
                        'search' => 'vmt',
                        'replace' => 'application/vnd.valve.source.material',
                    ],
                    [
                        'search' => 'vmv0',
                        'replace' => 'application/vnd.sirtx.vmv0',
                    ],
                    [
                        'search' => 'vob',
                        'replace' => 'video/x-ms-vob',
                    ],
                    [
                        'search' => 'vor',
                        'replace' => 'application/vnd.stardivision.writer',
                    ],
                    [
                        'search' => 'vox',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'vp',
                        'replace' => 'application/vp',
                    ],
                    [
                        'search' => 'vpm',
                        'replace' => 'multipart/voice-message',
                    ],
                    [
                        'search' => 'vrml',
                        'replace' => 'model/vrml',
                    ],
                    [
                        'search' => 'vsc',
                        'replace' => 'application/vnd.vidsoft.vidconference',
                    ],
                    [
                        'search' => 'vsd',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vsdx',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vsf',
                        'replace' => 'application/vnd.vsf',
                    ],
                    [
                        'search' => 'vsp4',
                        'replace' => 'application/vnd.vocalshaper.vsp4',
                    ],
                    [
                        'search' => 'vss',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vst',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vsw',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vtd',
                        'replace' => 'application/vividence.scriptfile',
                    ],
                    [
                        'search' => 'vtf',
                        'replace' => 'image/vnd.valve.source.texture',
                    ],
                    [
                        'search' => 'vtnstd',
                        'replace' => 'application/vnd.veritone.aion+json',
                    ],
                    [
                        'search' => 'vtt',
                        'replace' => 'text/vtt',
                    ],
                    [
                        'search' => 'vtu',
                        'replace' => 'model/vnd.vtu',
                    ],
                    [
                        'search' => 'vtx',
                        'replace' => 'application/vnd.visio',
                    ],
                    [
                        'search' => 'vwx',
                        'replace' => 'application/vnd.vectorworks',
                    ],
                    [
                        'search' => 'vxml',
                        'replace' => 'application/voicexml+xml',
                    ],
                    [
                        'search' => 'w3d',
                        'replace' => 'application/x-director',
                    ],
                    [
                        'search' => 'wad',
                        'replace' => 'application/x-doom',
                    ],
                    [
                        'search' => 'wadl',
                        'replace' => 'application/vnd.sun.wadl+xml',
                    ],
                    [
                        'search' => 'wafl',
                        'replace' => 'application/vnd.wasmflow.wafl',
                    ],
                    [
                        'search' => 'war',
                        'replace' => 'application/java-archive',
                    ],
                    [
                        'search' => 'wasm',
                        'replace' => 'application/wasm',
                    ],
                    [
                        'search' => 'wav',
                        'replace' => 'audio/wav',
                    ],
                    [
                        'search' => 'wax',
                        'replace' => 'audio/x-ms-wax',
                    ],
                    [
                        'search' => 'wbmp',
                        'replace' => 'image/vnd.wap.wbmp',
                    ],
                    [
                        'search' => 'wbs',
                        'replace' => 'application/vnd.criticaltools.wbs+xml',
                    ],
                    [
                        'search' => 'wbxml',
                        'replace' => 'application/vnd.wap.wbxml',
                    ],
                    [
                        'search' => 'wcm',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wdb',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wdp',
                        'replace' => 'image/vnd.ms-photo',
                    ],
                    [
                        'search' => 'weba',
                        'replace' => 'audio/webm',
                    ],
                    [
                        'search' => 'webapp',
                        'replace' => 'application/x-web-app-manifest+json',
                    ],
                    [
                        'search' => 'webm',
                        'replace' => 'video/webm',
                    ],
                    [
                        'search' => 'webmanifest',
                        'replace' => 'application/manifest+json',
                    ],
                    [
                        'search' => 'webp',
                        'replace' => 'image/webp',
                    ],
                    [
                        'search' => 'wg',
                        'replace' => 'application/vnd.pmi.widget',
                    ],
                    [
                        'search' => 'wgsl',
                        'replace' => 'text/wgsl',
                    ],
                    [
                        'search' => 'wgt',
                        'replace' => 'application/widget',
                    ],
                    [
                        'search' => 'wif',
                        'replace' => 'application/watcherinfo+xml',
                    ],
                    [
                        'search' => 'win',
                        'replace' => 'model/vnd.gdl',
                    ],
                    [
                        'search' => 'wks',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wlnk',
                        'replace' => 'application/link-format',
                    ],
                    [
                        'search' => 'wm',
                        'replace' => 'video/x-ms-wm',
                    ],
                    [
                        'search' => 'wma',
                        'replace' => 'audio/x-ms-wma',
                    ],
                    [
                        'search' => 'wmc',
                        'replace' => 'application/vnd.wmc',
                    ],
                    [
                        'search' => 'wmd',
                        'replace' => 'application/x-ms-wmd',
                    ],
                    [
                        'search' => 'wmf',
                        'replace' => 'image/wmf',
                    ],
                    [
                        'search' => 'wml',
                        'replace' => 'text/vnd.wap.wml',
                    ],
                    [
                        'search' => 'wmlc',
                        'replace' => 'application/vnd.wap.wmlc',
                    ],
                    [
                        'search' => 'wmls',
                        'replace' => 'text/vnd.wap.wmlscript',
                    ],
                    [
                        'search' => 'wmlsc',
                        'replace' => 'application/vnd.wap.wmlscriptc',
                    ],
                    [
                        'search' => 'wmv',
                        'replace' => 'video/x-ms-wmv',
                    ],
                    [
                        'search' => 'wmx',
                        'replace' => 'video/x-ms-wmx',
                    ],
                    [
                        'search' => 'wmz',
                        'replace' => 'application/x-ms-wmz',
                    ],
                    [
                        'search' => 'woff',
                        'replace' => 'font/woff',
                    ],
                    [
                        'search' => 'woff2',
                        'replace' => 'font/woff2',
                    ],
                    [
                        'search' => 'wpd',
                        'replace' => 'application/vnd.wordperfect',
                    ],
                    [
                        'search' => 'wpl',
                        'replace' => 'application/vnd.ms-wpl',
                    ],
                    [
                        'search' => 'wps',
                        'replace' => 'application/vnd.ms-works',
                    ],
                    [
                        'search' => 'wqd',
                        'replace' => 'application/vnd.wqd',
                    ],
                    [
                        'search' => 'wri',
                        'replace' => 'application/x-mswrite',
                    ],
                    [
                        'search' => 'wrl',
                        'replace' => 'model/vrml',
                    ],
                    [
                        'search' => 'wsc',
                        'replace' => 'application/vnd.wfa.wsc',
                    ],
                    [
                        'search' => 'wsdl',
                        'replace' => 'application/wsdl+xml',
                    ],
                    [
                        'search' => 'wspolicy',
                        'replace' => 'application/wspolicy+xml',
                    ],
                    [
                        'search' => 'wtb',
                        'replace' => 'application/vnd.webturbo',
                    ],
                    [
                        'search' => 'wv',
                        'replace' => 'application/vnd.wv.csp+wbxml',
                    ],
                    [
                        'search' => 'wvx',
                        'replace' => 'video/x-ms-wvx',
                    ],
                    [
                        'search' => 'x32',
                        'replace' => 'application/x-authorware-bin',
                    ],
                    [
                        'search' => 'x3d',
                        'replace' => 'model/x3d+xml',
                    ],
                    [
                        'search' => 'x3db',
                        'replace' => 'model/x3d+binary',
                    ],
                    [
                        'search' => 'x3dbz',
                        'replace' => 'model/x3d+binary',
                    ],
                    [
                        'search' => 'x3dv',
                        'replace' => 'model/x3d+vrml',
                    ],
                    [
                        'search' => 'x3dvz',
                        'replace' => 'model/x3d+vrml',
                    ],
                    [
                        'search' => 'x3dz',
                        'replace' => 'model/x3d+xml',
                    ],
                    [
                        'search' => 'x_b',
                        'replace' => 'model/vnd.parasolid.transmit.binary',
                    ],
                    [
                        'search' => 'x_t',
                        'replace' => 'model/vnd.parasolid.transmit.text',
                    ],
                    [
                        'search' => 'xaml',
                        'replace' => 'application/xaml+xml',
                    ],
                    [
                        'search' => 'xap',
                        'replace' => 'application/x-silverlight-app',
                    ],
                    [
                        'search' => 'xar',
                        'replace' => 'application/vnd.xara',
                    ],
                    [
                        'search' => 'xav',
                        'replace' => 'application/xcap-att+xml',
                    ],
                    [
                        'search' => 'xbap',
                        'replace' => 'application/x-ms-xbap',
                    ],
                    [
                        'search' => 'xbd',
                        'replace' => 'application/vnd.fujixerox.docuworks.binder',
                    ],
                    [
                        'search' => 'xbm',
                        'replace' => 'image/x-xbitmap',
                    ],
                    [
                        'search' => 'xca',
                        'replace' => 'application/xcap-caps+xml',
                    ],
                    [
                        'search' => 'xcs',
                        'replace' => 'application/calendar+xml',
                    ],
                    [
                        'search' => 'xct',
                        'replace' => 'application/vnd.fujixerox.docuworks.container',
                    ],
                    [
                        'search' => 'xdcf',
                        'replace' => 'application/vnd.gov.sk.xmldatacontainer+xml',
                    ],
                    [
                        'search' => 'xdd',
                        'replace' => 'application/bacnet-xdd+zip',
                    ],
                    [
                        'search' => 'xdf',
                        'replace' => 'application/xcap-diff+xml',
                    ],
                    [
                        'search' => 'xdm',
                        'replace' => 'application/vnd.syncml.dm+xml',
                    ],
                    [
                        'search' => 'xdp',
                        'replace' => 'application/vnd.adobe.xdp+xml',
                    ],
                    [
                        'search' => 'xdssc',
                        'replace' => 'application/dssc+xml',
                    ],
                    [
                        'search' => 'xdw',
                        'replace' => 'application/vnd.fujixerox.docuworks',
                    ],
                    [
                        'search' => 'xel',
                        'replace' => 'application/xcap-el+xml',
                    ],
                    [
                        'search' => 'xenc',
                        'replace' => 'application/xenc+xml',
                    ],
                    [
                        'search' => 'xer',
                        'replace' => 'application/patch-ops-error+xml',
                    ],
                    [
                        'search' => 'xfd',
                        'replace' => 'application/vnd.xfdl',
                    ],
                    [
                        'search' => 'xfdf',
                        'replace' => 'application/vnd.adobe.xfdf',
                    ],
                    [
                        'search' => 'xfdl',
                        'replace' => 'application/vnd.xfdl',
                    ],
                    [
                        'search' => 'xhe',
                        'replace' => 'audio/usac',
                    ],
                    [
                        'search' => 'xht',
                        'replace' => 'application/xhtml+xml',
                    ],
                    [
                        'search' => 'xhtm',
                        'replace' => 'application/vnd.pwg-xhtml-print+xml',
                    ],
                    [
                        'search' => 'xhtml',
                        'replace' => 'application/xhtml+xml',
                    ],
                    [
                        'search' => 'xhvml',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'xif',
                        'replace' => 'image/vnd.xiff',
                    ],
                    [
                        'search' => 'xla',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xlam',
                        'replace' => 'application/vnd.ms-excel.addin.macroenabled.12',
                    ],
                    [
                        'search' => 'xlc',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xlf',
                        'replace' => 'application/xliff+xml',
                    ],
                    [
                        'search' => 'xlim',
                        'replace' => 'application/vnd.xmpie.xlim',
                    ],
                    [
                        'search' => 'xlm',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xls',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xlsb',
                        'replace' => 'application/vnd.ms-excel.sheet.binary.macroenabled.12',
                    ],
                    [
                        'search' => 'xlsm',
                        'replace' => 'application/vnd.ms-excel.sheet.macroenabled.12',
                    ],
                    [
                        'search' => 'xlsx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ],
                    [
                        'search' => 'xlt',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xltm',
                        'replace' => 'application/vnd.ms-excel.template.macroenabled.12',
                    ],
                    [
                        'search' => 'xltx',
                        'replace' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
                    ],
                    [
                        'search' => 'xlw',
                        'replace' => 'application/vnd.ms-excel',
                    ],
                    [
                        'search' => 'xm',
                        'replace' => 'audio/xm',
                    ],
                    [
                        'search' => 'xmi',
                        'replace' => 'application/vnd.xmi+xml',
                    ],
                    [
                        'search' => 'xml',
                        'replace' => 'application/xml',
                    ],
                    [
                        'search' => 'xmls',
                        'replace' => 'application/dskpp+xml',
                    ],
                    [
                        'search' => 'xmt_bin',
                        'replace' => 'model/vnd.parasolid.transmit.binary',
                    ],
                    [
                        'search' => 'xmt_txt',
                        'replace' => 'model/vnd.parasolid.transmit.text',
                    ],
                    [
                        'search' => 'xns',
                        'replace' => 'application/xcap-ns+xml',
                    ],
                    [
                        'search' => 'xo',
                        'replace' => 'application/vnd.olpc-sugar',
                    ],
                    [
                        'search' => 'xodp',
                        'replace' => 'application/vnd.collabio.xodocuments.presentation',
                    ],
                    [
                        'search' => 'xods',
                        'replace' => 'application/vnd.collabio.xodocuments.spreadsheet',
                    ],
                    [
                        'search' => 'xodt',
                        'replace' => 'application/vnd.collabio.xodocuments.document',
                    ],
                    [
                        'search' => 'xop',
                        'replace' => 'application/xop+xml',
                    ],
                    [
                        'search' => 'xotp',
                        'replace' => 'application/vnd.collabio.xodocuments.presentation-template',
                    ],
                    [
                        'search' => 'xots',
                        'replace' => 'application/vnd.collabio.xodocuments.spreadsheet-template',
                    ],
                    [
                        'search' => 'xott',
                        'replace' => 'application/vnd.collabio.xodocuments.document-template',
                    ],
                    [
                        'search' => 'xpak',
                        'replace' => 'application/vnd.gentoo.xpak',
                    ],
                    [
                        'search' => 'xpi',
                        'replace' => 'application/x-xpinstall',
                    ],
                    [
                        'search' => 'xpl',
                        'replace' => 'application/xproc+xml',
                    ],
                    [
                        'search' => 'xpm',
                        'replace' => 'image/x-xpixmap',
                    ],
                    [
                        'search' => 'xpr',
                        'replace' => 'application/vnd.is-xpr',
                    ],
                    [
                        'search' => 'xps',
                        'replace' => 'application/vnd.ms-xpsdocument',
                    ],
                    [
                        'search' => 'xpw',
                        'replace' => 'application/vnd.intercon.formnet',
                    ],
                    [
                        'search' => 'xpx',
                        'replace' => 'application/vnd.intercon.formnet',
                    ],
                    [
                        'search' => 'xsd',
                        'replace' => 'application/xml',
                    ],
                    [
                        'search' => 'xsf',
                        'replace' => 'application/prs.xsf+xml',
                    ],
                    [
                        'search' => 'xsl',
                        'replace' => 'application/xslt+xml',
                    ],
                    [
                        'search' => 'xslt',
                        'replace' => 'application/xslt+xml',
                    ],
                    [
                        'search' => 'xsm',
                        'replace' => 'application/vnd.syncml+xml',
                    ],
                    [
                        'search' => 'xspf',
                        'replace' => 'application/xspf+xml',
                    ],
                    [
                        'search' => 'xul',
                        'replace' => 'application/vnd.mozilla.xul+xml',
                    ],
                    [
                        'search' => 'xvm',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'xvml',
                        'replace' => 'application/xv+xml',
                    ],
                    [
                        'search' => 'xwd',
                        'replace' => 'image/x-xwindowdump',
                    ],
                    [
                        'search' => 'xyz',
                        'replace' => 'chemical/x-xyz',
                    ],
                    [
                        'search' => 'xyze',
                        'replace' => 'image/vnd.radiance',
                    ],
                    [
                        'search' => 'xz',
                        'replace' => 'application/x-xz',
                    ],
                    [
                        'search' => 'yaml',
                        'replace' => 'text/yaml',
                    ],
                    [
                        'search' => 'yang',
                        'replace' => 'application/yang',
                    ],
                    [
                        'search' => 'yin',
                        'replace' => 'application/yin+xml',
                    ],
                    [
                        'search' => 'yme',
                        'replace' => 'application/vnd.yaoweme',
                    ],
                    [
                        'search' => 'yml',
                        'replace' => 'text/yaml',
                    ],
                    [
                        'search' => 'ymp',
                        'replace' => 'text/x-suse-ymp',
                    ],
                    [
                        'search' => 'yt',
                        'replace' => 'video/vnd.youtube.yt',
                    ],
                    [
                        'search' => 'yuv',
                        'replace' => 'video/vnd.planar',
                    ],
                    [
                        'search' => 'z1',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z2',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z3',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z4',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z5',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z6',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z7',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'z8',
                        'replace' => 'application/x-zmachine',
                    ],
                    [
                        'search' => 'zaz',
                        'replace' => 'application/vnd.zzazz.deck+xml',
                    ],
                    [
                        'search' => 'zfc',
                        'replace' => 'application/vnd.filmit.zfc',
                    ],
                    [
                        'search' => 'zfo',
                        'replace' => 'application/vnd.software602.filler.form-xml-zip',
                    ],
                    [
                        'search' => 'zip',
                        'replace' => 'application/zip',
                    ],
                    [
                        'search' => 'zir',
                        'replace' => 'application/vnd.zul',
                    ],
                    [
                        'search' => 'zirz',
                        'replace' => 'application/vnd.zul',
                    ],
                    [
                        'search' => 'zmm',
                        'replace' => 'application/vnd.handheld-entertainment+xml',
                    ],
                    [
                        'search' => 'zone',
                        'replace' => 'text/dns',
                    ],
                    [
                        'search' => 'zst',
                        'replace' => 'application/zstd',
                    ],
                ],
            ],
            'deduplicate_values' => true,
        ],

        // Filter the files: Documents
        // --------------------------------------------------------------------------------
        // Enter the file extensions, separated by commas, to enable file uploading.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_filter_docs' => [
            'statamic_attribute' => [
                'default' => null, // combined with rcwdupload_filter_compr elsewhere
            ],
        ],

        // Filter the files: Images
        // --------------------------------------------------------------------------------
        // Enter the file extensions, separated by commas, to enable file uploading.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_filter_images' => [
            'statamic_attribute' => [
                'default' => null, // combined with rcwdupload_filter_compr elsewhere
            ],
        ],

        // Filter the files: Other files
        // --------------------------------------------------------------------------------
        // Enter the file extensions, separated by commas, to enable file uploading.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_filter_others' => [
            'statamic_attribute' => [
                'default' => null, // combined with rcwdupload_filter_compr elsewhere
            ],
        ],

        // Hide the browse button after selection
        // --------------------------------------------------------------------------------
        // Hide the browser button after the file is selected.
        //
        // Fieldtype:
        //   - rcwdupload
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rcwdupload_hidebrowse' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        'N' => null,
                        'n' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => 'Y',
                        'replace' => true,
                    ],
                    [
                        'search' => 'y',
                        'replace' => true,
                    ],
                    [
                        'search' => 'Y2',
                        'replace' => true,
                    ],
                    [
                        'search' => 'y2',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Show uploaded image preview
        // --------------------------------------------------------------------------------
        // Choose YES if you want to show the image preview after upload it.
        //
        // Fieldtype:
        //   - rcwdupload
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rcwdupload_preview' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        'N' => null,
                        'n' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => true,
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => 'Y',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => 'y',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => '1',
                        'replace' => 'mime_types',
                    ],
                    [
                        'search' => 1,
                        'replace' => 'mime_types',
                    ],
                ],
            ],
        ],

        // Activate repeater
        // --------------------------------------------------------------------------------
        // Choose YES if you want to let the user upload more than 1 file.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_repeater' => [
            'statamic_attribute' => [
                'default' => null,
                'specific' => [
                    'rcwdupload' => 'max_files',
                ],
                'based_on_attribute_value' => [
                    'specific' => [
                        'rcwdupload' => [
                            'Y' => null,
                            'y' => null,
                        ],
                    ],
                ],
            ],
            'value_translation' => [
                'specific' => [
                    'rcwdupload' => [
                        [
                            'search' => '#not_set',
                            'replace' => 1,
                        ],
                        [
                            'search' => '#empty',
                            'replace' => 1,
                        ],
                        [
                            'search' => 'N',
                            'replace' => 1,
                        ],
                        [
                            'search' => 'n',
                            'replace' => 1,
                        ],
                    ],
                ],
            ],
        ],

        // Add more text fields: Yes: Custom fields
        // --------------------------------------------------------------------------------
        // Add more text fields where you can add your meta data (e.g., artist, year,
        // description etc.).
        //
        // Fieldtype:
        //   - rcwdupload
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rcwdupload_textfields_choices' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Add more text fields: Yes: Custom fields: Required
        // --------------------------------------------------------------------------------
        // Fieldtype:
        //   - rcwdupload
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rcwdupload_textfields_choices_req' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Filesize limit
        // --------------------------------------------------------------------------------
        // Limit the upload size of the files.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_sizelimit' => [
            'statamic_attribute' => [
                'default' => 'max_file_size',
            ],
            'combine_value_with_other_attribute_value' => [
                'combination_type' => 'string',
                'string_separator' => '',
                'attribute_names' => [
                    'rcwdupload_sizelimit_type',
                ],
            ],
            'value_substitution_regex' => [
                '/^(.+)(kb)$/' => '$1KB',
                '/^(.+)(mb)$/' => '$1MB',
            ],
            'convert_file_size' => 'MB',
        ],

        // Filesize limit: Type
        // --------------------------------------------------------------------------------
        // Unit for `rcwdupload_sizelimit`.
        //
        // Fieldtype:
        //   - rcwdupload
        // --------------------------------------------------------------------------------
        'rcwdupload_sizelimit_type' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Redact
        // --------------------------------------------------------------------------------
        // The Gravity Forms `redact` attribute is a boolean value.
        //
        // Check this box if you do not want the information the user places in this
        // field to be saved to the entry. The information will be unrecoverable, but it
        // will be available to functions that use entry values to perform actions while
        // the form is submitting (like notifications).
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'redact' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // repeaterField_isRequired
        'repeaterField_isRequired' => [
            'statamic_attribute' => [
                'default' => null,
                'based_on_attribute_value' => [
                    'default' => [
                        'false' => null,
                        0 => null,
                    ],
                ],
            ],
            'value_translation' => [
                'default' => [
                    [
                        'search' => 'true',
                        'replace' => true,
                    ],
                    [
                        'search' => '1',
                        'replace' => true,
                    ],
                    [
                        'search' => 1,
                        'replace' => true,
                    ],
                ],
            ],
        ],

        // Reversed
        // --------------------------------------------------------------------------------
        // The Gravity Forms `reversed` attribute is a boolean value. It appears to be
        // restricted to the `survey` fieldtype. This configuration setting does not
        // appear to be exposed in the Gravity Forms user interface. It is not clear
        // what this setting does.
        // --------------------------------------------------------------------------------
        'reversed' => [
            'statamic_attribute' => [
                'default' => null,
            ],
            'as_boolean' => true,
        ],

        // rnm_search (`rcwdupload` fieldtype)
        // --------------------------------------------------------------------------------
        // Deliberately left reporting at every value: classified as unknown (wordpress-importer-archive#1163). The same
        // list of rename placeholders on all 10 `rcwdupload` fields measured, on one site.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'rnm_search' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
        ],

        // Salesforce: map type
        // --------------------------------------------------------------------------------
        // Reported at any non-empty value (wordpress-importer-archive#1163): it belongs to a Salesforce field mapping,
        // an integration with no import target. "live" on all 6 fields measured, on one site.
        // Classified from the fixture corpus alone (wordpress-importer-archive#1163); the plugin is not in the reference WordPress install.
        // --------------------------------------------------------------------------------
        'salesforceMapType' => [
            // Explicitly null rather than ['default' => null]: the alert branch is gated on
            // `statamic_attribute` resolving to null, and an array does not (wordpress-importer-archive#1157).
            'statamic_attribute' => null,
        ],

        // Select All Text (`checkboxes` fieldtype, et al.)
        // --------------------------------------------------------------------------------
        // The Gravity Forms `selectAllText` attribute is a string value. It sets the
        // text of the "Select All" button.
        //
        // There is no analog to this in Statamic, as the "Select All" button is not
        // supported.
        // --------------------------------------------------------------------------------
        'selectAllText' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // storageType
        // --------------------------------------------------------------------------------
        // The Gravity Forms `storageType` attribute is an enumerated string with the
        // following options:
        // - 'json'
        //
        // The attribute is associated with the `multiselect` fieldtype. The
        // configuration option does not appear to be exposed in the Gravity Forms
        // editor UI.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'storageType' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Time Format
        // --------------------------------------------------------------------------------
        // The Gravity Forms `timeFormat` attribute is an enumerated string with the
        // following options:
        // - '12' (12 hour)
        // - '24' (24 hour)
        //
        // There is no analog to this in Statamic, as Statamic's `time` fieldtype doesn't
        // support a time format attribute. It simply defaults to the 24 hour format.
        // --------------------------------------------------------------------------------
        'timeFormat' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // Use the Rich Text Editor
        // --------------------------------------------------------------------------------
        // The Gravity Forms `useRichTextEditor` attribute is a boolean value. When true,
        // it uses the rich text editor for the field.
        //
        // Enables these settings:
        // - Bold
        // - Italic
        // - Bullet List
        // - Numbered List
        // - Quote
        // - Align Left
        // - Align Center
        // - Align Right
        // - Link
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'useRichTextEditor' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // validateState
        // --------------------------------------------------------------------------------
        // The Gravity Forms `validateState` attribute is a boolean value. When true,
        // it enables state validation for the field.
        //
        // Gravity Forms state validation refers to a security feature that prevents
        // users from manipulating the state of a field in the browser and submitting
        // altered data.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'validateState' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],

        // validateTotal
        // --------------------------------------------------------------------------------
        // The Gravity Forms `validateTotal` attribute is a boolean value. The attribute
        // is associated with the `total` fieldtype. The configuration option does not
        // appear to be exposed in the Gravity Forms editor UI.
        //
        // This attribute is not being used in any active UAMS forms (as of 2025-08-01).
        // --------------------------------------------------------------------------------
        'validateTotal' => [
            'statamic_attribute' => [
                'default' => null,
            ],
        ],
    ];

    /**
     * Maps Gravity Forms subfield labels to Statamic subfield handles with
     * hierarchical attribute inheritance.
     *
     * This array defines how Gravity Forms subfield attributes are mapped to Statamic
     * config fields, supporting a three-level inheritance system to eliminate code
     * duplication and provide flexible configuration.
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
     * @var array<int|string, mixed>
     */
    protected static array $subfieldMapping = [
        'fieldtypes' => [
            'address' => [
                'subfields' => [
                    'Street Address' => [
                        'handle' => 'street_address',
                        'attributes' => [
                            // Add attribute mappings specific to this subfield, overriding the more general mappings
                        ],
                    ],
                    'Address Line 2' => [
                        'handle' => 'address_line_2',
                    ],
                    'City' => [
                        'handle' => 'city',
                    ],
                    'State \/ Province' => [
                        'handle' => 'state_province_region',
                        'attributes' => [
                            'customLabel' => [
                                'statamic_attribute' => [
                                    'based_on_other_attribute_value' => [
                                        'default' => [
                                            'addressType' => [
                                                'international' => '[#subfield]_[#preset]_label',
                                                'canadian' => '[#subfield]_[#preset]_label',
                                                'us' => '[#subfield]_[#preset]_label',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'placeholder' => [
                                'statamic_attribute' => [
                                    'based_on_other_attribute_value' => [
                                        'default' => [
                                            'addressType' => [
                                                'international' => '[#subfield]_[#preset]_placeholder',
                                                'canadian' => '[#subfield]_[#preset]_placeholder',
                                                'us' => '[#subfield]_[#preset]_placeholder',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'defaultValue' => [
                                'statamic_attribute' => [
                                    'based_on_other_attribute_value' => [
                                        'default' => [
                                            'addressType' => [
                                                'international' => '[#subfield]_[#preset]_default',
                                                'canadian' => '[#subfield]_[#preset]_default',
                                                'us' => null, // Exclude for US - will use top-level defaultState instead
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'isHidden' => [
                                'statamic_attribute' => [
                                    'default' => '[#subfield]_[#preset]_include',
                                    'based_on_attribute_value' => [
                                        'default' => [
                                            'false' => null,
                                            0 => null,
                                        ],
                                    ],
                                ],
                                'value_translation' => [
                                    'default' => [
                                        [
                                            'search' => 'true',
                                            'replace' => false,
                                        ],
                                        [
                                            'search' => true,
                                            'replace' => false,
                                        ],
                                        [
                                            'search' => '1',
                                            'replace' => false,
                                        ],
                                        [
                                            'search' => 1,
                                            'replace' => false,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'ZIP \/ Postal Code' => [
                        'handle' => 'postal_code',
                    ],
                    'Country' => [
                        'handle' => 'country',
                        'attributes' => [
                            'customLabel' => [
                                'statamic_attribute' => [
                                    'default' => '[#subfield]_label',
                                ],
                            ],
                            'defaultValue' => [
                                'statamic_attribute' => [
                                    'default' => null, // Override general mapping - use top-level defaultCountry instead
                                ],
                            ],
                            'placeholder' => [
                                'statamic_attribute' => [
                                    'default' => null, // Override general mapping - exclude placeholder
                                ],
                            ],
                            'isHidden' => [
                                'statamic_attribute' => [
                                    'based_on_other_attribute_value' => [
                                        'default' => [
                                            'addressType' => [
                                                'international' => '[#subfield]_include',
                                                'canadian' => null,
                                                'us' => null,
                                            ],
                                        ],
                                    ],
                                    'based_on_attribute_value' => [
                                        'default' => [
                                            'false' => null,
                                            0 => null,
                                        ],
                                    ],
                                ],
                                'value_translation' => [
                                    'default' => [
                                        [
                                            'search' => 'true',
                                            'replace' => false,
                                        ],
                                        [
                                            'search' => true,
                                            'replace' => false,
                                        ],
                                        [
                                            'search' => '1',
                                            'replace' => false,
                                        ],
                                        [
                                            'search' => 1,
                                            'replace' => false,
                                        ],
                                    ],
                                ],
                            ],
                            // Exclude defaultValue - it will be mapped from top-level defaultCountry
                        ],
                    ],
                    // Add other subfields as needed
                ],
                'attributes' => [
                    // Add attribute mappings specific to this fieldtype, overriding the more general mappings
                    'defaultCountry' => [
                        'statamic_attribute' => [
                            'default' => null, // Disregarding this subfield attribute in favor of the top-level `defaultCountry` attribute
                        ],
                    ],
                    'defaultState' => [
                        'statamic_attribute' => [
                            'default' => null, // Disregarding this subfield attribute in favor of the top-level `defaultState` attribute
                        ],
                    ],
                ],
            ],
            'name' => [
                'subfields' => [
                    'Prefix' => [
                        'handle' => 'prefix',
                        'attributes' => [
                            'choices' => [
                                'statamic_attribute' => [
                                    'default' => null,
                                ],
                            ],
                            'inputType' => [
                                'statamic_attribute' => [
                                    'default' => null,
                                ],
                            ],
                            'isHidden' => [
                                'statamic_attribute' => [
                                    'default' => '[#subfield]_include',
                                    'based_on_attribute_value' => [
                                        'default' => [
                                            'true' => null,
                                            1 => null,
                                        ],
                                    ],
                                ],
                                'value_translation' => [
                                    'default' => [
                                        [
                                            'search' => 'false',
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => false,
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => '0',
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => 0,
                                            'replace' => true,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'First' => [
                        'handle' => 'first_name',
                    ],
                    'Middle' => [
                        'handle' => 'middle_name',
                        'attributes' => [
                            'isHidden' => [
                                'statamic_attribute' => [
                                    'default' => '[#subfield]_include',
                                    'based_on_attribute_value' => [
                                        'default' => [
                                            'true' => null,
                                            1 => null,
                                        ],
                                    ],
                                ],
                                'value_translation' => [
                                    'default' => [
                                        [
                                            'search' => 'false',
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => false,
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => '0',
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => 0,
                                            'replace' => true,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'Last' => [
                        'handle' => 'last_name',
                    ],
                    'Suffix' => [
                        'handle' => 'suffix',
                        'attributes' => [
                            'isHidden' => [
                                'statamic_attribute' => [
                                    'default' => '[#subfield]_include',
                                    'based_on_attribute_value' => [
                                        'default' => [
                                            'true' => null,
                                            1 => null,
                                        ],
                                    ],
                                ],
                                'value_translation' => [
                                    'default' => [
                                        [
                                            'search' => 'false',
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => false,
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => '0',
                                            'replace' => true,
                                        ],
                                        [
                                            'search' => 0,
                                            'replace' => true,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    // Add other subfields as needed
                ],
                'attributes' => [
                    // Add attribute mappings specific to this fieldtype, overriding the more general mappings
                ],
            ],
            'time' => [
                'subfields' => [
                    'Hour' => [
                        'handle' => null,
                    ],
                    'Minute' => [
                        'handle' => null,
                    ],
                    'AM/PM' => [
                        'handle' => null,
                    ],
                ],
            ],
            'product' => [
                'subfields' => [
                    'Name' => [
                        'handle' => null,
                    ],
                    'Price' => [
                        'handle' => null,
                    ],
                    'Quantity' => [
                        'handle' => null,
                    ],
                ],
            ],
            // Add other fieldtypes as needed
        ],
        'attributes' => [
            'id' => [
                'statamic_attribute' => [
                    'default' => null,
                ],
            ],
            'label' => [
                'statamic_attribute' => [
                    'default' => null,
                ],
            ],
            'name' => [
                'statamic_attribute' => [
                    'default' => null,
                ],
            ],
            'autocompleteAttribute' => [
                'statamic_attribute' => [
                    'default' => null,
                ],
                'alert_unsupported' => false,
            ],
            'isHidden' => [
                'statamic_attribute' => [
                    'default' => '[#subfield]_include',
                    'based_on_attribute_value' => [
                        'default' => [
                            'false' => null,
                            0 => null,
                        ],
                    ],
                ],
                'value_translation' => [
                    'default' => [
                        [
                            'search' => 'true',
                            'replace' => false,
                        ],
                        [
                            'search' => true,
                            'replace' => false,
                        ],
                        [
                            'search' => '1',
                            'replace' => false,
                        ],
                        [
                            'search' => 1,
                            'replace' => false,
                        ],
                    ],
                ],
            ],
            'customLabel' => [
                'statamic_attribute' => [
                    'default' => '[#subfield]_label',
                ],
            ],
            'placeholder' => [
                'statamic_attribute' => [
                    'default' => '[#subfield]_placeholder',
                ],
            ],
            'defaultValue' => [
                'statamic_attribute' => [
                    'default' => '[#subfield]_default',
                ],
            ],
            // Add other attributes as needed
        ],
    ];

    /**
     * Add warnings for unmapped subfields and subfield attributes in a Gravity Forms
     * field.
     *
     * @param  array<int|string, mixed>  $field  The Gravity Forms field data (must include 'inputs' if subfields exist)
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    public static function addUnmappedSubfieldWarnings(
        array $field,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        // Define fieldtypes to exclude from unmapped subfield warnings
        $excludeFieldtypes = [
            'checkbox',
            'consent',
            'image_choice',
            'multi_choice',
            'poll',
            'survey',
        ];

        // Call the trait method to add warnings, passing the exclusion list
        static::addUnmappedSubfieldWarningsToField(
            $field,
            static::$subfieldMapping,
            $excludeFieldtypes,
            $formTitle,
            $fieldLabel,
            $formId
        );
    }

    /**
     * Add a warning for a Gravity Forms product mode Statamic cannot express.
     *
     * `GF_Field_Product` has six input-type variants. `singleproduct` maps to the
     * Statamic fieldtype's `single` selection type (its default) and `select` /
     * `radio` map to `multiple`. The remaining three — `hiddenproduct` (Hidden),
     * `price` (User Defined Price) and `calculation` (Calculation) — have no analog
     * in `App\Fieldtypes\Product`, so `inputType` resolves to a null
     * `selection_type` and the field imports with no selection behavior at all.
     *
     * Extending the fieldtype for them is not proportionate — they are 3 fields out
     * of 309 in the export corpus — and a silent documented fallback would hide
     * them, so each one is reported by name instead (issue wordpress-importer-archive#299).
     *
     * @param  array<int|string, mixed>  $field  The Gravity Forms field data
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    public static function addUnsupportedProductModeWarning(
        array $field,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        // Only product fields carry a product mode
        if (($field['type'] ?? null) !== 'product') {
            return;
        }

        $inputType = $field['inputType'] ?? null;

        // A product field with no inputType is a Single Product, the default
        if (! \is_string($inputType)) {
            return;
        }

        // Anything the fieldtype can express needs no warning
        if (! \in_array($inputType, self::UNSUPPORTED_PRODUCT_INPUT_TYPES, true)) {
            return;
        }

        // Identify the field precisely so the migrating team can find it
        $fieldId = isset($field['id']) && \is_scalar($field['id'])
            ? (string) $field['id']
            : '?';

        ImportWarningManager::addWarning(
            'forms',
            'unsupported_product_modes',
            [
                'plugin' => 'Gravity Forms',
                'form_title' => $formTitle,
                'form_id' => $formId,
                'field_id' => $fieldId,
                'field_label' => $fieldLabel,
                'input_type' => $inputType,
                'reason' => \sprintf(
                    'The Gravity Forms "%s" product mode has no analog in the Statamic '
                        .'product fieldtype, so this field imports without a selection '
                        .'type and needs a manual port.',
                    $inputType
                ),
            ]
        );
    }

    /**
     * Get the Statamic fieldtype for a given Gravity Forms field type or field array.
     *
     * @param  string|array<int|string, mixed>  $gravityField  Either the field type string or the full field array
     * @return string|null|false Statamic fieldtype, null if not mapped, or false if suppressed
     */
    public static function getStatamicFieldtype(
        string|array $gravityField
    ): string|null|false {
        return static::resolveFieldtypeFromMapping(
            static::$mapping,
            $gravityField
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
     * Get the subfield mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The subfield mapping array
     */
    public static function getSubfieldMapping(): array
    {
        return static::$subfieldMapping;
    }

    /**
     * Extracts conditional logic data from Gravity Forms field data.
     *
     * @param  array<int|string, mixed>  $fieldData  The Gravity Forms field data
     * @return array<int|string, mixed>|null The conditional logic data or null if not present
     */
    protected static function extractConditionalLogicData(
        array $fieldData
    ): ?array {
        // Check if conditional logic is present in the field data
        if (isset($fieldData['conditionalLogic'])) {
            // Handle JSON string format
            if (\is_string($fieldData['conditionalLogic'])) {
                $decoded = \json_decode(
                    $fieldData['conditionalLogic'],
                    true
                );
                if (\is_array($decoded)) {
                    return $decoded;
                }
            }

            // Handle array format
            if (\is_array($fieldData['conditionalLogic'])) {
                return $fieldData['conditionalLogic'];
            }
        }

        return null;
    }

    /**
     * Maps Gravity Forms conditional logic to Statamic conditional logic attributes.
     *
     * This method transforms Gravity Forms conditional logic configuration into
     * Statamic's conditional logic format. It handles the conversion of:
     *   - actionType: "show" | "hide" → if/unless attributes
     *   - logicType: "all" | "any" → if/if_any/unless/unless_any attributes
     *   - rules: array of conditions → field handle and condition string
     *
     * @param  array<int|string, mixed>|null  $conditionalLogic  The Gravity Forms conditional logic object
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    public static function mapConditionalLogic(
        $conditionalLogic,
        array $allFields = []
    ): array {
        // If no conditional logic, return empty array
        if (! \is_array($conditionalLogic) || $conditionalLogic === []) {
            return [];
        }

        // Check if conditional logic is enabled (if the property exists)
        if (isset($conditionalLogic['enabled']) && $conditionalLogic['enabled'] === false) {
            return [];
        }

        // Extract action type (show/hide) with fallback to 'show'
        $actionTypeRaw = $conditionalLogic['actionType'] ?? 'show';
        $actionType = \is_string($actionTypeRaw) ? $actionTypeRaw : 'show';

        // Extract logic type (all/any) with fallback to 'all'
        $logicTypeRaw = $conditionalLogic['logicType'] ?? 'all';
        $logicType = \is_string($logicTypeRaw) ? $logicTypeRaw : 'all';

        // Extract rules array with fallback to empty array
        $rules = $conditionalLogic['rules'] ?? [];

        // If no rules, return empty array
        if (! \is_array($rules) || $rules === []) {
            return [];
        }

        // Initialize array to store Statamic conditional logic
        $statamicConditions = [];

        // Process each rule in the conditional logic
        foreach ($rules as $rule) {
            if (! \is_array($rule)) {
                continue;
            }

            // Extract field ID from the rule
            $fieldIdRaw = $rule['fieldId'] ?? '';
            $fieldId = \is_scalar($fieldIdRaw) ? (string) $fieldIdRaw : '';

            // Extract operator from the rule
            $operatorRaw = $rule['operator'] ?? '';
            $operator = \is_string($operatorRaw) ? $operatorRaw : '';

            // Extract value from the rule
            $valueRaw = $rule['value'] ?? '';
            $value = \is_scalar($valueRaw) ? (string) $valueRaw : '';

            // Find the field handle by field ID for cross-reference
            $fieldHandle = static::getFieldHandleById(
                $fieldId,
                $allFields
            );

            // Skip this rule if field handle not found
            if ($fieldHandle === null || $fieldHandle === '') {
                continue;
            }

            // Convert Gravity Forms operator to Statamic condition string
            $conditionString = static::convertOperatorToStatamicCondition(
                $operator,
                $value
            );

            // Skip this rule if operator is not supported
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
     * Gets the field name/label from a Gravity Forms field array.
     *
     * @param  array<int|string, mixed>  $field  The Gravity Forms field array
     * @return ?string The field label or null if not found
     */
    protected static function getFieldNameFromField(
        array $field
    ): ?string {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : null;
    }

    /**
     * Converts Gravity Forms operator and value to Statamic condition string.
     *
     * @param  string  $operator  The Gravity Forms operator
     * @param  string  $value  The condition value
     * @return ?string The Statamic condition string or null if not supported
     */
    protected static function convertOperatorToStatamicCondition(
        string $operator,
        string $value
    ): ?string {
        // Convert Gravity Forms operators to Statamic condition strings
        return match ($operator) {
            'is' => $value === '' ? 'equals empty' : "equals {$value}",
            'isnot' => $value === '' ? 'not empty' : "not {$value}",
            '>' => "> {$value}",
            '<' => "< {$value}",
            'contains' => "contains {$value}",
            default => null,
        };
    }
}
