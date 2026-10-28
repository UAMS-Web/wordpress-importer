<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi;

/**
 * Turns the Redirection and Pretty Links rules the migration API serves into `redirect_rule` items (wordpress-importer-archive#1307).
 *
 * Neither plugin stores its rules as posts, so a vanity domain running either has no
 * `redirect_rule` item in its export. `UAMS-Web/uamswp-migration-api-archive#374` serves each plugin's
 * rules from `redirects/redirection` and `redirects/pretty-links`, and this class writes each
 * rule the redirect domains collection can express as the post payload a Safe Redirect Manager
 * rule arrives as. The materialized export then carries them, and the import reaches them
 * through the same `redirect_rule` path and the same entry writer as every other redirect
 * domain, so a re-run updates them and disables the ones gone from the source (wordpress-importer-archive#1311).
 *
 * **What a rule carries that the collection cannot is listed, never dropped silently.** A rule
 * that cannot be written at all, such as one conditioned on the visitor's login state, is
 * counted under the reason; a rule that is written but loses a behavior, such as click tracking,
 * is counted under that behavior.
 *
 * An item's `wp:post_id` is the plugin's name and the rule's id, such as `redirection-12`, so it
 * can never equal a real post's id, and the redirect domains entry records where it came from.
 */
final class RedirectPluginRules
{
    /**
     * Redirection's rules, keyed by the endpoint that serves them.
     */
    public const string REDIRECTION = 'redirects/redirection';

    /**
     * Pretty Links' links, keyed by the endpoint that serves them.
     */
    public const string PRETTY_LINKS = 'redirects/pretty-links';

    /**
     * The plugin each endpoint serves, by name.
     *
     * @var array<string, string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails tests in the Unit or Integration suite (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    public const array PLUGINS = [
        self::REDIRECTION => 'Redirection',
        self::PRETTY_LINKS => 'Pretty Links',
    ];

    /**
     * The API error codes that mean the plugin is not on the site being read.
     *
     * Each is the ordinary answer for a site that does not run the plugin, so it is recorded
     * as the plugin being absent rather than raised. Every other failure, including the
     * plugin's tables being missing or unreadable, still raises.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails tests in the Unit or Integration suite (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    public const array ABSENT_CODES = [
        'uamswp_migration_api_redirect_plugin_not_loaded',
        'uamswp_migration_api_redirect_plugin_not_active',
    ];

    /**
     * The `wp:post_id` prefix for each endpoint's items.
     *
     * @var array<string, string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails tests in the Unit or Integration suite (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array ID_PREFIXES = [
        self::REDIRECTION => 'redirection-',
        self::PRETTY_LINKS => 'pretty-links-',
    ];

    /**
     * The Pretty Links redirect types the plugin answers with their own status code.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails tests in the Unit or Integration suite (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array PRETTY_LINKS_STATUSES = ['301', '302', '307'];

    /**
     * Redirection's module for rules WordPress itself serves.
     *
     * The plugin's other modules write rules to the web server's own configuration.
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // this value; changing it to 0 or 2 by hand fails tests in the Unit or Integration suite (#102)
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    private const int REDIRECTION_WORDPRESS_MODULE = 1;

    /**
     * Convert one served record into a `redirect_rule` post payload.
     *
     * @param  string  $endpoint  The endpoint that served the record, one of the keys of PLUGINS
     * @param  array<array-key, mixed>  $record  The record as served
     * @return array{post: array<string, mixed>|null, unsupported: list<string>} The post payload,
     *                                                                           or null when the collection cannot express the rule, and what the rule carries that
     *                                                                           the collection cannot
     */
    public static function convert(
        string $endpoint,
        array $record
    ): array {
        // Pretty Links is the only other endpoint; an unknown one never reaches here
        return $endpoint === self::PRETTY_LINKS
            ? self::fromPrettyLink($record)
            : self::fromRedirection($record);
    }

    /**
     * Convert one Redirection rule.
     *
     * Only a rule that matches on the URL alone and redirects to a URL can be written. Every
     * other match type is conditioned on the visitor (login state, referrer, user agent, IP,
     * cookie, header, role, language), and every other action serves something other than a
     * redirect (an error, a random post, another URL's content, or nothing).
     *
     * A rule is live only when it and its group are both enabled, so either one disabled, or
     * a group that no longer exists, writes the item unpublished.
     *
     * @param  array<array-key, mixed>  $rule  A served Redirection rule
     * @return array{post: array<string, mixed>|null, unsupported: list<string>}
     */
    private static function fromRedirection(
        array $rule
    ): array {
        // Refuse what the collection has no way to serve, naming why
        $matchType = self::string($rule['match_type'] ?? null, 'url');

        if ($matchType !== 'url') {
            return ['post' => null, 'unsupported' => ["not imported: matches on {$matchType}, not on the URL alone"]];
        }

        $actionType = self::string($rule['action_type'] ?? null, 'url');

        if ($actionType !== 'url') {
            return ['post' => null, 'unsupported' => ["not imported: its action is {$actionType}, not a redirect"]];
        }

        // The plugin's own reading of the target, then the stored column for a plain rule
        $parsed = $rule['action_data_parsed'] ?? null;
        $target = \is_array($parsed) && \is_string($parsed['url'] ?? null)
            ? $parsed['url']
            : self::string($rule['action_data'] ?? null, '');

        $unsupported = [];
        $group = $rule['group'] ?? null;

        // A deleted group serves nothing, and a disabled one stops every rule in it
        $groupLive = \is_array($group) && ($group['enabled'] ?? false) === true;

        if (! \is_array($group)) {
            $unsupported[] = 'not live: its group no longer exists';
        } elseif (self::int($group['module_id'] ?? null, self::REDIRECTION_WORDPRESS_MODULE) !== self::REDIRECTION_WORDPRESS_MODULE) {
            $unsupported[] = 'served by the web server module rather than by WordPress';
        }

        // The match flags as the plugin applies them, the rule's own or the site's defaults
        $matchData = $rule['match_data'] ?? null;

        // A `??` read of a missing offset of a value that is not an array is null as well, and
        // a flag read from text is null too, so either check alone gives the same flags
        // @pest-mutate-ignore: BooleanAndToBooleanOr
        $flags = \is_array($matchData) && \is_array($matchData['source'] ?? null) ? $matchData['source'] : [];

        if (($flags['flag_case'] ?? false) === true) {
            $unsupported[] = 'matches the source ignoring case';
        }

        if (($flags['flag_trailing'] ?? false) === true) {
            $unsupported[] = 'matches the source with or without a trailing slash';
        }

        $query = self::string($flags['flag_query'] ?? null, 'exact');

        if ($query === 'ignore') {
            $unsupported[] = 'ignores the query string when matching';
        } elseif ($query === 'pass') {
            $unsupported[] = 'ignores the query string when matching and passes it to the target';
        }

        $title = self::string($rule['title'] ?? null, '');

        return [
            'post' => self::post(
                self::ID_PREFIXES[self::REDIRECTION].self::string($rule['id'] ?? null, ''),
                ($rule['enabled'] ?? false) === true && $groupLive,
                self::string($rule['url'] ?? null, ''),
                $target,
                self::string($rule['action_code'] ?? null, '301'),
                ($rule['regex'] ?? false) === true,
                $title,
                self::int($rule['position'] ?? null, 0)
            ),
            'unsupported' => $unsupported,
        ];
    }

    /**
     * Convert one Pretty Links link.
     *
     * The endpoint serves every link not in the trash, and the plugin serves every one of
     * those, so each is written published. A link's slug is matched against the request
     * path without its surrounding slashes, so the source is the slug with one leading slash.
     *
     * A link with no target URL, a pixel or a payment link, cannot be written. A redirect type
     * other than 301, 302 or 307 is written as a 302, the status the plugin itself falls back
     * to when no add-on handles that type, and listed.
     *
     * @param  array<array-key, mixed>  $link  A served Pretty Links link
     * @return array{post: array<string, mixed>|null, unsupported: list<string>}
     */
    private static function fromPrettyLink(
        array $link
    ): array {
        $type = self::string($link['redirect_type'] ?? null, '302');
        $target = self::string($link['url'] ?? null, '');

        // A link with nowhere to send the visitor is not a redirect
        if (($link['prettypay_link'] ?? false) === true) {
            return ['post' => null, 'unsupported' => ['not imported: a payment link']];
        }

        if (\trim($target) === '') {
            return ['post' => null, 'unsupported' => ["not imported: a {$type} link with no target URL"]];
        }

        $unsupported = [];
        $status = $type;

        if (! \in_array($type, self::PRETTY_LINKS_STATUSES, true)) {
            $status = '302';
            $unsupported[] = "{$type} redirect, imported as a 302";
        }

        if (($link['track_me'] ?? false) === true) {
            $unsupported[] = 'click tracking';
        }

        if (($link['param_forwarding'] ?? false) === true) {
            $unsupported[] = 'passes the query string to the target';
        }

        if (($link['nofollow'] ?? false) === true || ($link['sponsored'] ?? false) === true) {
            $unsupported[] = 'sends an X-Robots-Tag header';
        }

        // The name and description are what an editor reads to know what the link is for
        $notes = \implode(' - ', \array_filter(
            [self::string($link['name'] ?? null, ''), self::string($link['description'] ?? null, '')],
            static fn (string $part): bool => \trim($part) !== ''
        ));

        return [
            'post' => self::post(
                self::ID_PREFIXES[self::PRETTY_LINKS].self::string($link['id'] ?? null, ''),
                true,
                '/'.\trim(self::string($link['slug'] ?? null, ''), '/'),
                $target,
                $status,
                false,
                $notes,
                null
            ),
            'unsupported' => $unsupported,
        ];
    }

    /**
     * A `redirect_rule` post payload, in the shape the materializer writes as an item.
     *
     * The meta keys are the ones Safe Redirect Manager stores, which is what the redirect
     * entry writer reads.
     *
     * @param  string  $id  The item's `wp:post_id`
     * @param  bool  $live  Whether the plugin serves the rule
     * @param  string  $source  The source path or pattern
     * @param  string  $target  The target URL
     * @param  string  $status  The response code
     * @param  bool  $regex  Whether the source is a regular expression
     * @param  string  $notes  An editor-facing note, or an empty string
     * @param  int|null  $order  The rule's order, or null for the writer's default
     * @return array<string, mixed>
     */
    private static function post(
        string $id,
        bool $live,
        string $source,
        string $target,
        string $status,
        bool $regex,
        string $notes,
        ?int $order
    ): array {
        $meta = [
            '_redirect_rule_from' => [$source],
            '_redirect_rule_to' => [$target],
            '_redirect_rule_status_code' => [$status],
            '_redirect_rule_from_regex' => [$regex ? '1' : '0'],
        ];

        if (\trim($notes) !== '') {
            $meta['_redirect_rule_notes'] = [$notes];
        }

        return [
            'id' => $id,
            'type' => 'redirect_rule',
            'status' => $live ? 'publish' : 'draft',
            'title' => $source,
            'menu_order' => $order,
            'meta' => $meta,
        ];
    }

    /**
     * A served scalar as a string, or the fallback when it is not one.
     *
     * @param  mixed  $value  The served value
     * @param  string  $fallback  The value for anything that is not a string or a number
     */
    private static function string(
        mixed $value,
        string $fallback
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        return \is_int($value) || \is_float($value) ? (string) $value : $fallback;
    }

    /**
     * A served whole number, or the fallback when it is not one.
     *
     * @param  mixed  $value  The served value
     * @param  int  $fallback  The value for anything that is not a whole number
     */
    private static function int(
        mixed $value,
        int $fallback
    ): int {
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && \preg_match('/^-?[0-9]+$/', $value) === 1 ? (int) $value : $fallback;
    }
}
