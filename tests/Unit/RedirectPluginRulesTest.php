<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\RedirectPluginRules;

/**
 * Tests for converting Redirection and Pretty Links rules into `redirect_rule` items (wordpress-importer-archive#1307).
 *
 * The record shapes are the ones `UAMS-Web/uamswp-migration-api`'s `RedirectsController` serves
 * (wordpress-importer-archive#374): a Redirection rule through `Red_Item`, with its group's state beside it, and a Pretty
 * Links link through the plugin's `Links` repository, with its click counts removed.
 *
 * @command  php vendor/bin/pest tests/Unit/RedirectPluginRulesTest.php
 */

/**
 * A served Redirection rule that redirects one URL, with the given fields replaced.
 *
 * @param  array<array-key, mixed>  $overrides  Fields to replace
 * @return array<array-key, mixed> The rule
 */
function redirectionRule(array $overrides = []): array
{
    return \array_replace([
        'id' => 12,
        'url' => '/old-page',
        'match_url' => '/old-page',
        'regex' => false,
        'match_type' => 'url',
        'match_data' => ['source' => ['flag_query' => 'exact', 'flag_case' => false, 'flag_trailing' => false, 'flag_regex' => false]],
        'action_type' => 'url',
        'action_code' => 301,
        'action_data' => 'https://uams.edu/new-page/',
        'action_data_parsed' => ['url' => 'https://uams.edu/new-page/'],
        'title' => 'Old campaign page',
        'position' => 4,
        'enabled' => true,
        'group_id' => 1,
        'group' => ['id' => 1, 'name' => 'Redirections', 'module_id' => 1, 'enabled' => true],
    ], $overrides);
}

/**
 * A served Pretty Links link, with the given fields replaced.
 *
 * @param  array<array-key, mixed>  $overrides  Fields to replace
 * @return array<array-key, mixed> The link
 */
function prettyLink(array $overrides = []): array
{
    return \array_replace([
        'id' => 7,
        'slug' => 'flu',
        'url' => 'https://uamshealth.com/flu/',
        'name' => 'Flu shots',
        'description' => '',
        'redirect_type' => '301',
        'param_forwarding' => false,
        'track_me' => false,
        'nofollow' => false,
        'sponsored' => false,
        'new_window' => false,
        'prettypay_link' => false,
        'source' => 'admin',
        'created_at' => '2024-01-02 03:04:05',
        'updated_at' => '2024-01-02 03:04:05',
        'deleted_at' => null,
        'pretty_url' => 'https://go.example.test/flu',
    ], $overrides);
}

/**
 * One meta value of a converted post, or null when the post or the key is absent.
 *
 * @param  array<string, mixed>|null  $post  The converted post
 * @param  string  $key  The meta key
 */
function redirectPluginMeta(?array $post, string $key): mixed
{
    $meta = $post['meta'] ?? null;

    return \is_array($meta) ? ($meta[$key] ?? null) : null;
}

it('writes a Redirection rule as a published redirect_rule item', function (): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule());

    expect($converted)->toBe([
        'post' => [
            'id' => 'redirection-12',
            'type' => 'redirect_rule',
            'status' => 'publish',
            'title' => '/old-page',
            'menu_order' => 4,
            'meta' => [
                '_redirect_rule_from' => ['/old-page'],
                '_redirect_rule_to' => ['https://uams.edu/new-page/'],
                '_redirect_rule_status_code' => ['301'],
                '_redirect_rule_from_regex' => ['0'],
                '_redirect_rule_notes' => ['Old campaign page'],
            ],
        ],
        'unsupported' => [],
    ]);
});

it('keeps a Redirection regex rule and its response code', function (): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule([
        'url' => '^/news/(.*)$',
        'regex' => true,
        'action_code' => 302,
        'action_data' => 'https://uams.edu/$1',
        'action_data_parsed' => ['url' => 'https://uams.edu/$1'],
        'title' => '',
    ]))['post'];

    expect($post['meta'] ?? null)->toBe([
        '_redirect_rule_from' => ['^/news/(.*)$'],
        '_redirect_rule_to' => ['https://uams.edu/$1'],
        '_redirect_rule_status_code' => ['302'],
        '_redirect_rule_from_regex' => ['1'],
    ]);
});

it('reads the stored target when the plugin served no parsed one', function (mixed $parsed): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule([
        'action_data' => 'https://uams.edu/stored/',
        'action_data_parsed' => $parsed,
    ]))['post'];

    expect(redirectPluginMeta($post, '_redirect_rule_to'))->toBe(['https://uams.edu/stored/']);
})->with([
    'null' => [null],
    'no url key' => [['logged_in' => '/a']],
    'a url that is not a string' => [['url' => 5]],
]);

it('writes a Redirection rule that is not live as unpublished', function (array $overrides, array $unsupported): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule($overrides));

    expect($converted['post']['status'] ?? null)->toBe('draft')
        ->and($converted['unsupported'])->toBe($unsupported);
})->with([
    'the rule is disabled' => [['enabled' => false], []],
    'its group is disabled' => [['group' => ['id' => 1, 'name' => 'Old', 'module_id' => 1, 'enabled' => false]], []],
    'its group no longer exists' => [['group' => null], ['not live: its group no longer exists']],
]);

it('refuses a Redirection rule conditioned on the visitor, naming the condition', function (string $matchType): void {
    expect(RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule(['match_type' => $matchType])))
        ->toBe(['post' => null, 'unsupported' => ["not imported: matches on {$matchType}, not on the URL alone"]]);
})->with(['login', 'referrer', 'agent', 'ip', 'cookie', 'header', 'role', 'server', 'language', 'page', 'custom']);

it('refuses a Redirection rule whose action is not a redirect, naming the action', function (string $actionType): void {
    expect(RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule(['action_type' => $actionType])))
        ->toBe(['post' => null, 'unsupported' => ["not imported: its action is {$actionType}, not a redirect"]]);
})->with(['error', 'random', 'pass', 'nothing']);

it('lists the Redirection match options the collection cannot carry', function (array $source, array $unsupported): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule([
        'match_data' => ['source' => $source],
    ]));

    expect($converted['post'])->not->toBeNull()
        ->and($converted['unsupported'])->toBe($unsupported);
})->with([
    'ignore case' => [['flag_case' => true], ['matches the source ignoring case']],
    'ignore trailing slash' => [['flag_trailing' => true], ['matches the source with or without a trailing slash']],
    'ignore the query' => [['flag_query' => 'ignore'], ['ignores the query string when matching']],
    'pass the query' => [['flag_query' => 'pass'], ['ignores the query string when matching and passes it to the target']],
    'every one' => [
        ['flag_case' => true, 'flag_trailing' => true, 'flag_query' => 'pass'],
        [
            'matches the source ignoring case',
            'matches the source with or without a trailing slash',
            'ignores the query string when matching and passes it to the target',
        ],
    ],
    'none set' => [[], []],
    'flags that are not booleans' => [['flag_case' => 1, 'flag_trailing' => '1'], []],
]);

it('lists a Redirection rule the web server serves rather than WordPress', function (): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule([
        'group' => ['id' => 2, 'name' => 'Apache', 'module_id' => 2, 'enabled' => true],
    ]));

    expect($converted['post']['status'] ?? null)->toBe('publish')
        ->and($converted['unsupported'])->toBe(['served by the web server module rather than by WordPress']);
});

it('writes a Pretty Links link as a published redirect_rule item from its slug', function (): void {
    expect(RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink()))->toBe([
        'post' => [
            'id' => 'pretty-links-7',
            'type' => 'redirect_rule',
            'status' => 'publish',
            'title' => '/flu',
            'menu_order' => null,
            'meta' => [
                '_redirect_rule_from' => ['/flu'],
                '_redirect_rule_to' => ['https://uamshealth.com/flu/'],
                '_redirect_rule_status_code' => ['301'],
                '_redirect_rule_from_regex' => ['0'],
                '_redirect_rule_notes' => ['Flu shots'],
            ],
        ],
        'unsupported' => [],
    ]);
});

it('keeps a Pretty Links slug that contains a slash, with one leading slash', function (string $slug, string $source): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink(['slug' => $slug]))['post'];

    expect(redirectPluginMeta($post, '_redirect_rule_from'))->toBe([$source]);
})->with([
    'nested' => ['go/abc', '/go/abc'],
    'surrounding slashes' => ['/abc/', '/abc'],
]);

it('keeps a Pretty Links 301, 302 or 307 as its response code', function (string $type): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink(['redirect_type' => $type]));

    expect(redirectPluginMeta($converted['post'], '_redirect_rule_status_code'))->toBe([$type])
        ->and($converted['unsupported'])->toBe([]);
})->with(['301', '302', '307']);

it('writes any other Pretty Links redirect type as a 302, and lists it', function (string $type): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink(['redirect_type' => $type]));

    expect(redirectPluginMeta($converted['post'], '_redirect_rule_status_code'))->toBe(['302'])
        ->and($converted['unsupported'])->toBe(["{$type} redirect, imported as a 302"]);
})->with(['cloak', 'prettybar', 'metarefresh', 'javascript']);

it('refuses a Pretty Links link with nowhere to send the visitor', function (array $overrides, string $reason): void {
    expect(RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink($overrides)))
        ->toBe(['post' => null, 'unsupported' => [$reason]]);
})->with([
    'a pixel' => [['redirect_type' => 'pixel', 'url' => ''], 'not imported: a pixel link with no target URL'],
    'a blank target' => [['url' => '   '], 'not imported: a 301 link with no target URL'],
    'a payment link' => [['prettypay_link' => true], 'not imported: a payment link'],
]);

it('lists the Pretty Links behaviors the collection cannot carry', function (array $overrides, array $unsupported): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink($overrides));

    expect($converted['post'])->not->toBeNull()
        ->and($converted['unsupported'])->toBe($unsupported);
})->with([
    'click tracking' => [['track_me' => true], ['click tracking']],
    'parameter forwarding' => [['param_forwarding' => true], ['passes the query string to the target']],
    'nofollow' => [['nofollow' => true], ['sends an X-Robots-Tag header']],
    'sponsored' => [['sponsored' => true], ['sends an X-Robots-Tag header']],
    'a new window, which only affects rendered links' => [['new_window' => true], []],
    'a cloaked, tracked link' => [
        ['redirect_type' => 'cloak', 'track_me' => true, 'param_forwarding' => true, 'nofollow' => true, 'sponsored' => true],
        ['cloak redirect, imported as a 302', 'click tracking', 'passes the query string to the target', 'sends an X-Robots-Tag header'],
    ],
]);

it('writes a Pretty Links name and description as the note', function (string $name, string $description, ?array $notes): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink(['name' => $name, 'description' => $description]))['post'];

    expect(redirectPluginMeta($post, '_redirect_rule_notes'))->toBe($notes);
})->with([
    'both' => ['Flu shots', 'Fall campaign', ['Flu shots - Fall campaign']],
    'description only' => ['', 'Fall campaign', ['Fall campaign']],
    'blank' => [' ', '', null],
]);

it('reads served values that are missing or of another type as their defaults', function (): void {
    $redirection = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, [
        'id' => '9',
        'position' => 'first',
        'action_code' => null,
        'group' => ['module_id' => '1', 'enabled' => true],
        'enabled' => true,
    ])['post'];
    $pretty = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, ['url' => 'https://uams.edu/', 'slug' => 3])['post'];

    expect($redirection['id'] ?? null)->toBe('redirection-9')
        ->and($redirection['menu_order'] ?? null)->toBe(0)
        ->and(redirectPluginMeta($redirection, '_redirect_rule_status_code'))->toBe(['301'])
        ->and(redirectPluginMeta($redirection, '_redirect_rule_from'))->toBe([''])
        ->and(redirectPluginMeta($pretty, '_redirect_rule_from'))->toBe(['/3'])
        ->and(redirectPluginMeta($pretty, '_redirect_rule_status_code'))->toBe(['302']);
});

it('reads a Redirection rule that carries almost nothing with every default, unpublished', function (): void {
    // Each field it reads falls back on its own, and a rule not marked enabled is not live (#102)
    expect(RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, ['group' => ['enabled' => true]]))->toBe([
        'post' => [
            'id' => 'redirection-',
            'type' => 'redirect_rule',
            'status' => 'draft',
            'title' => '',
            'menu_order' => 0,
            'meta' => [
                '_redirect_rule_from' => [''],
                '_redirect_rule_to' => [''],
                '_redirect_rule_status_code' => ['301'],
                '_redirect_rule_from_regex' => ['0'],
            ],
        ],
        'unsupported' => [],
    ]);
});

it('prefers the plugin\'s parsed target to the stored column', function (): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule([
        'action_data' => 'https://uams.edu/stored/',
        'action_data_parsed' => ['url' => 'https://uams.edu/parsed/'],
    ]))['post'];

    expect(redirectPluginMeta($post, '_redirect_rule_to'))->toBe(['https://uams.edu/parsed/']);
});

it('writes a rule unpublished when its group does not say it is enabled', function (): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule([
        'group' => ['id' => 1, 'module_id' => 1],
    ]))['post'];

    expect($post['status'] ?? null)->toBe('draft');
});

it('writes no note for a Redirection title that is only whitespace', function (): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule(['title' => '   ']))['post'];

    expect(redirectPluginMeta($post, '_redirect_rule_notes'))->toBeNull();
});

it('reads a whole number served as text, and nothing else, as a number', function (mixed $position, int $order): void {
    $post = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule(['position' => $position]))['post'];

    expect($post['menu_order'] ?? null)->toBe($order);
})->with([
    'text' => ['7', 7],
    'negative text' => ['-3', -3],
    'text that only starts with a number' => ['7a', 0],
    'text with a number inside it' => ['a7', 0],
]);

it('reads a module id served as text', function (string $module, array $unsupported): void {
    $converted = RedirectPluginRules::convert(RedirectPluginRules::REDIRECTION, redirectionRule([
        'group' => ['id' => 1, 'module_id' => $module, 'enabled' => true],
    ]));

    expect($converted['unsupported'])->toBe($unsupported);
})->with([
    'WordPress' => ['1', []],
    'the web server' => ['2', ['served by the web server module rather than by WordPress']],
]);

it('refuses a Pretty Links link that carries nothing, naming the default type', function (): void {
    expect(RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, []))
        ->toBe(['post' => null, 'unsupported' => ['not imported: a 302 link with no target URL']]);
});

it('reads a Pretty Links link that carries only a target with every default, published', function (): void {
    expect(RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, ['url' => 'https://uams.edu/']))->toBe([
        'post' => [
            'id' => 'pretty-links-',
            'type' => 'redirect_rule',
            'status' => 'publish',
            'title' => '/',
            'menu_order' => null,
            'meta' => [
                '_redirect_rule_from' => ['/'],
                '_redirect_rule_to' => ['https://uams.edu/'],
                '_redirect_rule_status_code' => ['302'],
                '_redirect_rule_from_regex' => ['0'],
            ],
        ],
        'unsupported' => [],
    ]);
});

it('compares a Pretty Links redirect type as the text it is', function (): void {
    // `301.0` loosely equals `301`, and is not a type the plugin answers on its own
    $converted = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, prettyLink(['redirect_type' => '301.0']));

    expect(redirectPluginMeta($converted['post'], '_redirect_rule_status_code'))->toBe(['302'])
        ->and($converted['unsupported'])->toBe(['301.0 redirect, imported as a 302']);
});

it('builds a Pretty Links note from whichever of its name and description it has', function (array $fields, ?array $notes): void {
    $link = prettyLink();
    unset($link['name'], $link['description']);

    $post = RedirectPluginRules::convert(RedirectPluginRules::PRETTY_LINKS, $fields + $link)['post'];

    expect(redirectPluginMeta($post, '_redirect_rule_notes'))->toBe($notes);
})->with([
    'a name only' => [['name' => 'Flu shots'], ['Flu shots']],
    'a description only' => [['description' => 'Fall campaign'], ['Fall campaign']],
    'a blank name and a description' => [['name' => '   ', 'description' => 'Fall campaign'], ['Fall campaign']],
    'neither' => [[], null],
]);
