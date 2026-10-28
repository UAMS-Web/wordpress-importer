<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

/**
 * The user-registration configuration carried from a Gravity Forms add-on feed.
 *
 * A Gravity Forms export carries a form's *definition*; the add-on feed that makes a form
 * create a WordPress account lives in `gf_addon_feed` and is not exported at all — a Gravity
 * Forms export of the largest affected site contains zero occurrences of `userregistration`.
 * So this is built from the migration API's `GET /forms/gravity/<id>/feeds` payload rather
 * than from the corpus, which is why its input is an API feed row and not a WXR node.
 *
 * The value object exists so the decision "does this form create a user, and how" is made
 * once, in a pure function that tests can drive, rather than inside the form-creation path.
 */
final readonly class UserRegistrationFeed
{
    /**
     * The add-on that registers users. Any other feed on the same form is not ours.
     */
    public const string ADDON_SLUG = 'gravityformsuserregistration';

    /**
     * The user attributes carried, and the feed-meta key each is read from.
     *
     * An allowlist rather than a copy of `meta`, and that is the load-bearing choice. Feed
     * meta is an open map: an add-on release, or an add-on nobody enumerated, can introduce a
     * key holding something that is not a field reference. Copying the map wholesale would
     * carry that into a Statamic form and into version control; naming the six attributes the
     * host app can act on means an unknown key is dropped rather than trusted.
     *
     * The API withholds credential-shaped values before this sees them, so this is the second
     * of two independent guards rather than the only one.
     *
     * @var array<string, string>
     */
    private const array ATTRIBUTE_KEYS = [
        'username' => 'username',
        'email' => 'email',
        'password' => 'password',
        'first_name' => 'firstname',
        'last_name' => 'lastname',
        'display_name' => 'displayname',
    ];

    /**
     * @param  int  $feedId  The feed's own id, for tracing a carried configuration back
     * @param  int  $formId  The Gravity Forms form id the feed is attached to
     * @param  string  $feedType  `create` or `update` — a feed that only updates an existing
     *                            user is not a registration path, and the host app needs the
     *                            distinction to avoid creating accounts from a profile form
     * @param  ?string  $role  The role the feed assigns, or null when it names none
     * @param  array<string, string>  $fieldMap  User attribute to the *field reference* that
     *                                           supplies it. Values are Gravity Forms field
     *                                           ids such as `3` or `5.3`, never submitted data
     */
    private function __construct(
        public int $feedId,
        public int $formId,
        public string $feedType,
        public ?string $role,
        public array $fieldMap,
    ) {}

    /**
     * Build from one feed row as the migration API serves it, or null when the feed is not a
     * live user-registration feed.
     *
     * Null is returned rather than an empty object for three distinct cases, deliberately
     * collapsed because the caller does the same thing in each: the feed belongs to a
     * different add-on, the feed is disabled, or its shape is unusable. A form with no
     * registration configuration must import as an ordinary form, which is exactly what a
     * null gives the caller.
     *
     * @param  array<string, mixed>  $feed  One element of the API's feed payload
     * @return ?self The configuration, or null when this feed registers nobody
     */
    public static function fromPayload(array $feed): ?self
    {
        if (($feed['addon_slug'] ?? null) !== self::ADDON_SLUG) {
            return null;
        }

        /*
         * Strict `!== true`, not a falsy test.
         *
         * `is_active` is a `tinyint(1)` that arrives from the database as the string `'0'`.
         * The API converts it to a real boolean precisely so this check is safe — but a
         * loose test here would also accept the string `'0'` from any other source, and the
         * thing being enabled is a feed that creates accounts. Fail closed.
         */
        if (($feed['is_active'] ?? null) !== true) {
            return null;
        }

        $meta = $feed['meta'] ?? null;

        if (! \is_array($meta)) {
            return null;
        }

        $fieldMap = [];

        foreach (self::ATTRIBUTE_KEYS as $attribute => $metaKey) {
            $reference = $meta[$metaKey] ?? null;

            // A reference is a scalar field id. Anything else — an array, an object, null —
            // is not something the host app can resolve to a submitted value, so it is left
            // out rather than carried as a shape the consumer must defend against.
            if (\is_string($reference) && $reference !== '') {
                $fieldMap[$attribute] = $reference;
            }
        }

        if ($fieldMap === []) {
            return null;
        }

        $role = $meta['role'] ?? null;
        $feedType = $meta['feedType'] ?? null;

        return new self(
            feedId: self::toInt($feed['id'] ?? null),
            formId: self::toInt($feed['form_id'] ?? null),
            feedType: \is_string($feedType) && $feedType !== '' ? $feedType : 'create',
            role: \is_string($role) && $role !== '' ? $role : null,
            fieldMap: $fieldMap,
        );
    }

    /**
     * Whether this feed creates an account, as opposed to updating an existing one.
     *
     * A profile-update form carries a live feed with the same field mapping and must not be
     * treated as a registration path — `uamscaregiving.org` and `patientslearn.uams.edu` both
     * carry update feeds alongside their registration ones.
     *
     * @return bool True when submitting the form should create a user
     */
    public function createsUser(): bool
    {
        return $this->feedType === 'create';
    }

    /**
     * The configuration as it is written onto the Statamic form.
     *
     * Written under a single `user_registration` key on the form's own data bag. Statamic's
     * `Form` uses `ContainsData`, `save()` persists arbitrary keys and `hydrate()` reads them
     * back, so this round-trips with no host-app change — which is what lets the consumer
     * (`UAMS-Web/uams-statamic#208`) choose a schema rather than negotiate a mechanism.
     *
     * @return array<string, mixed> The form-data value
     */
    public function toFormData(): array
    {
        return [
            'creates_user' => $this->createsUser(),
            'feed_id' => $this->feedId,
            'source_form_id' => $this->formId,
            'feed_type' => $this->feedType,
            'role' => $this->role,
            'field_map' => $this->fieldMap,
        ];
    }

    /**
     * Coerce an API-supplied id to an int without trusting its transport type.
     *
     * @param  mixed  $value  The raw value
     * @return int The id, or 0 when it is not one
     */
    private static function toInt(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : 0);
    }
}
