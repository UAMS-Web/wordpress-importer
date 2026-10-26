<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\UserRegistrationFeed;

/*
 * The feed rows below are the shape the migration API actually serves, taken from that
 * repository's own `GravityFeedsEndpointTest` rather than reconstructed from Gravity Forms
 * internals. `password` is a FIELD ID and not a password — the endpoint serves it precisely
 * because withholding it would break the import this configuration exists for.
 */

/**
 * One served feed row, with overrides merged last.
 *
 * @param  array<string, mixed>  $overrides  Keys to replace
 * @return array<string, mixed> The feed row
 */
function registrationFeed(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'form_id' => 10,
        'addon_slug' => 'gravityformsuserregistration',
        'is_active' => true,
        'feed_order' => 0,
        'event_type' => null,
        'meta' => registrationMeta(),
        'denied' => ['total' => 0, 'by_name' => 0, 'by_shape' => 0],
    ], $overrides);
}

/**
 * The served meta for a registration feed.
 *
 * @param  array<string, mixed>  $overrides  Keys to replace
 * @return array<string, mixed> The meta
 */
function registrationMeta(array $overrides = []): array
{
    return array_merge([
        'feedName' => 'Create an account',
        'feedType' => 'create',
        'username' => '3',
        'email' => '2',
        'password' => '4',
        'firstname' => '5.3',
        'lastname' => '5.6',
        'displayname' => 'nickname',
        'role' => 'subscriber',
        'sendEmail' => '1',
        'userMeta' => [['key' => 'department', 'value' => '6']],
    ], $overrides);
}

it('carries the field mapping, the role and the form id from a live registration feed', function (): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed());

    expect($feed)->not->toBeNull();
    assert($feed instanceof UserRegistrationFeed);

    expect($feed->formId)->toBe(10)
        ->and($feed->feedId)->toBe(7)
        ->and($feed->role)->toBe('subscriber')
        ->and($feed->createsUser())->toBeTrue();

    // Each attribute resolves to the field REFERENCE the feed names, rather than being
    // inferred from a field label — which is the acceptance criterion this test exists for.
    expect($feed->fieldMap)->toBe([
        'username' => '3',
        'email' => '2',
        'password' => '4',
        'first_name' => '5.3',
        'last_name' => '5.6',
        'display_name' => 'nickname',
    ]);
});

it('returns null for a disabled feed rather than carrying it silently enabled', function (): void {
    expect(UserRegistrationFeed::fromPayload(registrationFeed(['is_active' => false])))->toBeNull();
});

it('refuses a string "0" for is_active, which is how the database stores false', function (): void {
    // `tinyint(1)` arrives from `$wpdb` as the string '0'. It is falsy in PHP and truthy in
    // JavaScript; the API converts it to a real boolean, and this is the second guard. A
    // loose check would enable account creation on a feed the site had switched off.
    expect(UserRegistrationFeed::fromPayload(registrationFeed(['is_active' => '0'])))->toBeNull()
        ->and(UserRegistrationFeed::fromPayload(registrationFeed(['is_active' => '1'])))->toBeNull()
        ->and(UserRegistrationFeed::fromPayload(registrationFeed(['is_active' => 1])))->toBeNull();
});

it('returns null for another add-on feed on the same form', function (): void {
    // A form commonly carries several feeds; a payment feed is not a registration path.
    expect(UserRegistrationFeed::fromPayload(registrationFeed([
        'addon_slug' => 'gravityformsstripe',
        'meta' => ['feedName' => 'Charge the card'],
    ])))->toBeNull();
});

it('returns null for another add-on feed even when its meta maps user fields', function (): void {
    // The slug alone decides it: a feed shaped like a registration but owned by another
    // add-on registers nobody.
    expect(UserRegistrationFeed::fromPayload(registrationFeed([
        'addon_slug' => 'gravityformsmailchimp',
    ])))->toBeNull();
});

it('distinguishes an update feed from a registration feed', function (): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed([
        'meta' => registrationMeta(['feedType' => 'update']),
    ]));

    assert($feed instanceof UserRegistrationFeed);

    // A profile-update form carries a live feed with the same mapping. Treating it as a
    // registration path would create accounts from a profile form.
    expect($feed->createsUser())->toBeFalse()
        ->and($feed->feedType)->toBe('update');
});

it('carries only the named attributes, so an unknown meta key cannot ride along', function (): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed([
        'meta' => registrationMeta([
            'someFutureAddonKey' => 'whatever-this-holds',
            'apiToken' => 'not-a-field-reference',
        ]),
    ]));

    assert($feed instanceof UserRegistrationFeed);

    // The allowlist is the second of two guards: the API withholds credential-shaped values
    // before this sees them, and this carries only the six attributes the host app acts on.
    expect($feed->fieldMap)->not->toHaveKey('someFutureAddonKey')
        ->and($feed->fieldMap)->not->toHaveKey('apiToken')
        ->and($feed->toFormData()['field_map'])->not->toHaveKey('apiToken');

    $serialised = json_encode($feed->toFormData(), JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain('not-a-field-reference')
        ->and($serialised)->not->toContain('whatever-this-holds');
});

it('carries no role when the feed names none', function (): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed([
        'meta' => registrationMeta(['role' => '']),
    ]));

    assert($feed instanceof UserRegistrationFeed);

    expect($feed->role)->toBeNull()
        ->and($feed->toFormData()['role'])->toBeNull();
});

it('returns null when no attribute resolves to a field reference', function (): void {
    // Meta present but naming nothing usable — carrying an empty mapping would produce a
    // form the host app believes registers users and cannot populate one.
    expect(UserRegistrationFeed::fromPayload(registrationFeed([
        'meta' => ['feedName' => 'Broken', 'feedType' => 'create'],
    ])))->toBeNull();
});

it('ignores an attribute whose reference is not a scalar field id', function (): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed([
        'meta' => registrationMeta(['password' => ['nested' => 'shape']]),
    ]));

    assert($feed instanceof UserRegistrationFeed);

    expect($feed->fieldMap)->not->toHaveKey('password')
        ->and($feed->fieldMap)->toHaveKey('username');
});

it('produces a form-data value that names the source feed for tracing', function (): void {
    $data = UserRegistrationFeed::fromPayload(registrationFeed())?->toFormData();

    expect($data)->toBe([
        'creates_user' => true,
        'feed_id' => 7,
        'source_form_id' => 10,
        'feed_type' => 'create',
        'role' => 'subscriber',
        'field_map' => [
            'username' => '3',
            'email' => '2',
            'password' => '4',
            'first_name' => '5.3',
            'last_name' => '5.6',
            'display_name' => 'nickname',
        ],
    ]);
});

it('returns null when the feed carries no usable meta', function (mixed $meta): void {
    expect(UserRegistrationFeed::fromPayload(registrationFeed(['meta' => $meta])))->toBeNull();
})->with([
    'no meta' => [null],
    'meta that is not an array' => ['username=3'],
    // Indexing an object throws, or reads through ArrayAccess, so it is refused first
    'meta that is an object' => [new stdClass],
    'meta that is an array-like object' => [new ArrayObject(registrationMeta())],
]);

it('reads digit-string ids as ints and anything else as 0', function (mixed $id, int $expected): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed(['id' => $id, 'form_id' => $id]));

    assert($feed instanceof UserRegistrationFeed);

    expect($feed->feedId)->toBe($expected)
        ->and($feed->formId)->toBe($expected);
})->with([
    'an int' => [12, 12],
    'a digit string' => ['12', 12],
    'a non-digit string' => ['12a', 0],
    'a float' => [12.0, 0],
    'null' => [null, 0],
]);

it('treats a feed that names no type as a registration feed', function (mixed $feedType): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed([
        'meta' => registrationMeta(['feedType' => $feedType]),
    ]));

    assert($feed instanceof UserRegistrationFeed);

    expect($feed->feedType)->toBe('create')
        ->and($feed->createsUser())->toBeTrue();
})->with([
    'an empty type' => [''],
    'a type that is not a string' => [['create']],
]);

it('ignores an attribute whose reference is an empty string', function (): void {
    $feed = UserRegistrationFeed::fromPayload(registrationFeed([
        'meta' => registrationMeta(['email' => '']),
    ]));

    assert($feed instanceof UserRegistrationFeed);

    expect($feed->fieldMap)->not->toHaveKey('email')
        ->and($feed->fieldMap)->toHaveKey('username');
});
