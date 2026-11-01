<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\Creators;

use SimpleXMLElement;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Fieldtypes\RowId;
use Statamic\Globals\Variables;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\BaseGlobalCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Modifies the Statamic 'redirects' global set with WordPress 'redirect_rule'
 * content.
 *
 * Handles the specific mapping and modification logic for redirect rules,
 * appending them to the 'redirects' global set's replicator field for the
 * selected site. Supports overwrite behavior options to manage existing redirect
 * rules based on matching source URLs.
 */
class RedirectRuleGlobalCreator extends BaseGlobalCreator
{
    /**
     * The collection a vanity domain's rules are written to (UAMS-Web/uams-statamic-archive#2594, #1306).
     */
    public const DOMAIN_COLLECTION = 'redirect_domains';

    /**
     * The vanity domains whose redirect rules go to the redirect domains collection.
     *
     * The five domains `UAMS-Web/uams-statamic-archive#2594` counted. The first three run Safe Redirect
     * Manager, whose rules are `redirect_rule` posts. `go.uams.edu` and `gouams.com` run the
     * Redirection and Pretty Links plugins, whose rules a `--source=api` run materializes as
     * `redirect_rule` items (wordpress-importer-archive#1307). Overridden by `wordpress-import.redirect_domains.hosts`.
     *
     * @var list<string>
     */
    // PCOV records no line for a constant; each item, removed by hand, fails a test.
    // @pest-mutate-ignore: RemoveArrayItem
    public const DOMAIN_HOSTS = ['uams.health', 'uams.info', 'uamsbaptist.health', 'go.uams.edu', 'gouams.com'];

    /**
     * The response codes the redirect domains blueprint offers.
     *
     * @var list<string>
     */
    // PCOV records no line for a constant; each item, removed by hand, fails a test.
    // @pest-mutate-ignore: RemoveArrayItem
    private const array DOMAIN_RESPONSES = ['301', '302', '303', '307', '308'];

    /**
     * The order in which WordPress tries each plugin's rules, by the `wp:post_id` prefix (#108).
     *
     * Pretty Links redirects on `init` at priority 1, Redirection on `init` at priority 10, and
     * Safe Redirect Manager on `parse_request` or `template_redirect`, which run after `init`.
     * A rule whose id carries neither prefix is a Safe Redirect Manager post.
     *
     * @var array<string, int>
     */
    // PCOV records no line for a constant. Each item removed, and each rank moved onto its
    // neighbor's, fails a test by hand; lowering the lowest rank keeps the order, and only
    // the order is read.
    // @pest-mutate-ignore: RemoveArrayItem, IncrementInteger, DecrementInteger
    private const array PLUGIN_RANKS = ['pretty-links-' => 0, 'redirection-' => 1];

    /**
     * The rank of a rule whose id carries no plugin prefix.
     */
    // PCOV records no line for a constant. Lowering it onto Redirection's rank fails a test by
    // hand; raising it keeps it last, and only the order of the ranks is read.
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    private const int POST_RANK = 2;

    public function createGlobals(
        string $site,
        string $globalHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor
    ): void {
        $redirectRules = $xmlParser->getItems('redirect_rule');

        // A vanity domain is not a Statamic site, so its rules go to the redirect domains
        // collection, one entry per rule, rather than to a site's global set (wordpress-importer-archive#1306)
        // A parser with no channel read yet has no base blog URL, and is not a vanity domain
        try {
            $domainHost = self::redirectDomainHost($xmlParser->getBaseBlogUrl());
        } catch (\RuntimeException) {
            $domainHost = null;
        }

        if ($domainHost !== null) {
            $this->createRedirectDomainEntries($domainHost, $redirectRules, $site, $assetImporter, $output);

            return;
        }

        $global = GlobalSet::findByHandle($globalHandle);

        // Check if the global set does not exist
        if ($global === null) {
            // Output an error message
            $output->writeln("  - <error>Global set '$globalHandle' not found - skipping redirect rules.</error>");

            // Skip the rest of the method
            return;
        }

        // Get the global variables for the specified site
        $variables = $global->in($site);

        // Check if the global variables do not exist for this site
        if (! $variables instanceof Variables) {
            // Output an error message
            $output->writeln("  - <error>Global set '$globalHandle' is not enabled for site '$site' - skipping redirect rules.</error>");

            // Skip the rest of the method
            return;
        }

        $existingRedirects = $variables->get(
            'redirects',
            []
        );

        if (! \is_array($existingRedirects)) {
            $existingRedirects = [];
        }

        $newRedirects = [];

        foreach (ImportProgress::track($output, 'redirect_rule', $redirectRules) as $rule) {
            // Extract and strip HTML tags from the redirect source URL
            $source = HtmlTagStripper::strip(
                XpathHelper::getStringValue(
                    $rule,
                    'wp:postmeta[wp:meta_key="_redirect_rule_from"]/wp:meta_value'
                )
            );

            // Extract the redirect rule status from WordPress XML
            $status = XpathHelper::getStringValue(
                $rule,
                'wp:status'
            );

            // Extract the WordPress post ID for the redirect rule
            $ruleId = XpathHelper::getStringValue(
                $rule,
                'wp:post_id'
            );

            // Extract the regex match flag for the source URL
            $useRegex = XpathHelper::getBoolValue(
                $rule,
                'wp:postmeta[wp:meta_key="_redirect_rule_from_regex"]/wp:meta_value',
                false
            );

            // Extract the raw redirect target URL from WordPress XML
            $rawTarget = XpathHelper::getStringValue(
                $rule,
                'wp:postmeta[wp:meta_key="_redirect_rule_to"]/wp:meta_value'
            );

            // Resolve the raw target into the Statamic redirect target schema
            $targetData = $this->resolveTarget(
                $rawTarget,
                $useRegex,
                $site,
                $assetImporter,
                $output
            );

            $data = $this->buildRedirectRow(
                $source,
                $useRegex,
                $targetData,
                XpathHelper::getStringValue(
                    $rule,
                    'wp:postmeta[wp:meta_key="_redirect_rule_status_code"]/wp:meta_value',
                    '301'
                ),
                XpathHelper::getIntValue(
                    $rule,
                    'wp:menu_order',
                    30
                ),
                $status === 'publish'
            );

            // Extract and strip HTML tags from the redirect message
            $message = HtmlTagStripper::strip(
                XpathHelper::getStringValue(
                    $rule,
                    'wp:postmeta[wp:meta_key="_redirect_rule_message"]/wp:meta_value'
                )
            );

            // Check if a redirect message exists before adding it
            if ($message !== '') {
                // Add the redirect message to the data array
                $data['message'] = $message;
            }

            // Extract and strip HTML tags from the redirect notes
            $notes = HtmlTagStripper::strip(
                XpathHelper::getStringValue(
                    $rule,
                    'wp:postmeta[wp:meta_key="_redirect_rule_notes"]/wp:meta_value'
                )
            );

            // Check if redirect notes exist before adding them
            if ($notes !== '') {
                // Add the redirect notes to the data array
                $data['notes'] = $notes;
            }

            $output->writeln("Processing redirect rule '$source' with ID: $ruleId...");

            // Handle overwrite behavior
            $existingIndex = \array_search(
                $source,
                array_column(
                    $existingRedirects,
                    'source'
                ),
                true
            );
            $isUpdate = false;
            if ($existingIndex !== false) {
                switch ($this->globalOverwriteBehavior) {
                    case 'Skip matching sets':
                        $output->writeln("  - <comment>Skipping redirect rule '$source' (ID: $ruleId) as it already exists.</comment>");

                        continue 2; // Skip to next rule
                    case 'Create an additional set':
                        $output->writeln("  - Creating additional redirect rule '$source' with same source URL.");

                        // Inside a switch, continue acts as break, so the two cannot differ.
                        // @pest-mutate-ignore: BreakToContinue
                        break; // No modification to source, just add it
                    case 'Overwrite matching sets':
                        $output->writeln("  - Overwriting existing redirect rule '$source' (ID: $ruleId).");
                        unset($existingRedirects[$existingIndex]);
                        $existingRedirects = \array_values($existingRedirects); // Reindex
                        $isUpdate = true;

                        // Inside a switch, continue acts as break, so the two cannot differ.
                        // @pest-mutate-ignore: BreakToContinue
                        break;
                }
            }

            $newRedirects[] = $data;

            // Check if the redirect rule was updated
            if ($isUpdate) {
                // Log that an existing redirect rule was overwritten with new data
                $output->writeln("  - <info>Updated redirect rule '$source' (ID: $ruleId).</info>");
            } else { // Otherwise, it was created
                // Log that a new redirect rule was added to the collection
                $output->writeln("  - <info>Created redirect rule '$source' (ID: $ruleId).</info>");
            }
        }

        // Combine existing and new redirects
        $updatedRedirects = \array_merge(
            $existingRedirects,
            $newRedirects
        );
        $variables->set(
            'redirects',
            $updatedRedirects
        );
        $variables->save();

        $output->writeln("<info>Updated global set '$globalHandle' for site '$site'.</info>");
    }

    /**
     * The vanity domain an export belongs to, or null when its rules go to a global set.
     *
     * Matched on the host of the export's `wp:base_blog_url`, exactly and case-insensitively,
     * against `wordpress-import.redirect_domains.hosts`. A host that only ends with a listed
     * domain does not match.
     *
     * @param  string  $baseBlogUrl  The export's base blog URL
     * @return ?string The lower-cased host, or null when it is not a listed vanity domain
     */
    public static function redirectDomainHost(string $baseBlogUrl): ?string
    {
        $host = \parse_url($baseBlogUrl, \PHP_URL_HOST);

        // `parse_url()` answers false, never '', for a URL with no host
        if (! \is_string($host)) {
            return null;
        }

        $host = \strtolower($host);
        $hosts = config('wordpress-import.redirect_domains.hosts', self::DOMAIN_HOSTS);

        if (! \is_array($hosts)) {
            return null;
        }

        foreach ($hosts as $listed) {
            if (\is_string($listed) && \strtolower($listed) === $host) {
                return $host;
            }
        }

        return null;
    }

    /**
     * Writes a vanity domain's published rules to the redirect domains collection (wordpress-importer-archive#1306).
     *
     * One entry per published `redirect_rule`, carrying the domain as its `host`. An unpublished
     * rule is skipped, because the collection holds what the domain serves. A re-run updates the
     * entry with the same host and source rather than adding another, so it never duplicates.
     * An imported entry whose rule is no longer published at the source is disabled, not deleted
     * (wordpress-importer-archive#1311).
     *
     * Published rules that share a source are one entry, holding the rule WordPress serves for
     * that path (#108). Every other rule with that source is shadowed: it is not written, and it
     * is reported with the rule that serves the path, in the output and in the warnings summary.
     *
     * @param  string  $host  The vanity domain
     * @param  list<SimpleXMLElement>  $redirectRules  The export's `redirect_rule` items
     * @param  string  $site  The Statamic site handle being imported into, for target resolution
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     */
    private function createRedirectDomainEntries(
        string $host,
        array $redirectRules,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): void {
        $collection = Collection::findByHandle(self::DOMAIN_COLLECTION);

        // The collection is the host application's, so its absence is reported, not created
        if ($collection === null) {
            $output->writeln("  - <error>Collection '".self::DOMAIN_COLLECTION."' not found - skipping redirect rules for {$host}.</error>");

            return;
        }

        $sites = $collection->sites();

        // `sites()` always answers a non-empty collection, the default site when none is set,
        // so the fallback to the import's site only types the value.
        // @pest-mutate-ignore: InstanceOfToTrue, BooleanAndToBooleanOr
        $entrySite = $sites instanceof \Illuminate\Support\Collection && \is_string($sites->first()) ? $sites->first() : $site;

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $disabled = 0;
        $shadowed = 0;

        // The ids of the entries this run wrote, so every other imported entry for the host is stale
        $written = [];
        $unsupportedResponses = 0;

        // The rule WordPress serves for each source, so a rule another one shadows is never written
        $served = self::servedRules($redirectRules);

        foreach (ImportProgress::track($output, "redirect_rule ({$host})", $redirectRules) as $index => $rule) {
            // Only published rules are live on the domain
            if (XpathHelper::getStringValue($rule, 'wp:status') !== 'publish') {
                $skipped++;

                continue;
            }

            // A rule sharing its source with the one WordPress serves never answers a request
            $source = self::ruleSource($rule);
            $ruleId = XpathHelper::getStringValue($rule, 'wp:post_id');

            $winner = $served[\strtolower($source)] ?? null;

            if ($winner !== null && $winner['index'] !== $index) {
                $shadowed++;

                $output->writeln("  - <comment>{$host}{$source}: {$ruleId} shares its source with {$winner['id']} ({$winner['source']}), which WordPress serves; only {$winner['id']} was imported.</comment>");

                ImportWarningManager::addWarning('redirects', 'shadowed_rules', [
                    'host' => $host,
                    'source' => $source,
                    'served' => $winner['id'],
                    'served_source' => $winner['source'],
                    'shadowed' => $ruleId,
                ]);

                continue;
            }

            $fields = $this->domainEntryFields($host, $rule, $site, $assetImporter, $output);

            // A response the blueprint does not offer is kept as WordPress served it, and counted
            if (! \in_array($fields['response'], self::DOMAIN_RESPONSES, true)) {
                $unsupportedResponses++;
            }

            $entry = $this->findDomainEntry($host, $fields['source']);

            if (! $entry instanceof StatamicEntry) {
                $entry = Entry::make();

                if (! $entry instanceof StatamicEntry) {
                    continue;
                }

                $entry->collection(self::DOMAIN_COLLECTION);
                $entry->locale($entrySite);
                $created++;
            } else {
                $updated++;
            }

            $entry->data(\array_merge(['title' => $host.$fields['source']], $fields));

            // A new entry is already published (`Publishable::$published` is true), and an
            // existing one was published when it was written, so this cannot be observed.
            // @pest-mutate-ignore: RemoveMethodCall
            $entry->published(true);
            $entry->save();

            // Only the key is read, and isset() holds for false as for true.
            // @pest-mutate-ignore: TrueToFalse
            $written[self::entryId($entry)] = true;
        }

        // A rule unpublished or deleted at the source since an earlier run leaves its entry behind.
        // Disable it rather than delete it, so the redirect stops and the change stays reversible (wordpress-importer-archive#1311).
        foreach (ImportProgress::track($output, "stale redirect_rule entries ({$host})", $this->importedDomainEntries($host)) as $entry) {
            if (isset($written[self::entryId($entry)]) || $entry->get('enabled') === false) {
                continue;
            }

            $entry->set('enabled', false);
            $entry->save();
            $disabled++;
        }

        $output->writeln("<info>Redirect domain {$host}: {$created} created, {$updated} updated, {$disabled} disabled, {$skipped} unpublished skipped, {$shadowed} shadowed.</info>");

        // What the domain holds after the run, so a check needs no look at the content tree (#254)
        $stored = \count($this->importedDomainEntries($host));

        $output->writeln("<info>Redirect domain {$host}: {$stored} stored with a wordpress_post_id.</info>");

        if ($unsupportedResponses > 0) {
            $output->writeln("  - <comment>{$unsupportedResponses} rule(s) for {$host} use a response code the redirect domains blueprint does not offer; it was kept as WordPress served it.</comment>");
        }
    }

    /**
     * The redirect domains entry fields for one published rule.
     *
     * @param  string  $host  The vanity domain
     * @param  SimpleXMLElement  $rule  The `redirect_rule` item
     * @param  string  $site  The Statamic site handle being imported into, for target resolution
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @return array{host: string, wordpress_post_id: string, enabled: bool, whole_domain: bool, source: string, use_regex: bool, response: string, order: int, notes?: string, target_type: string, target_url?: string, target_entry?: list<string>}
     */
    private function domainEntryFields(
        string $host,
        SimpleXMLElement $rule,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): array {
        // Read the rule as the global-set path reads it
        $source = self::ruleSource($rule);
        $useRegex = XpathHelper::getBoolValue(
            $rule,
            'wp:postmeta[wp:meta_key="_redirect_rule_from_regex"]/wp:meta_value',
            false
        );
        $targetData = $this->resolveTarget(
            XpathHelper::getStringValue($rule, 'wp:postmeta[wp:meta_key="_redirect_rule_to"]/wp:meta_value'),
            $useRegex,
            $site,
            $assetImporter,
            $output
        );

        $fields = [
            'host' => $host,
            'wordpress_post_id' => XpathHelper::getStringValue($rule, 'wp:post_id'),
            'enabled' => true,
            'whole_domain' => false,
            'source' => $source,
            'use_regex' => $useRegex,
            'response' => XpathHelper::getStringValue(
                $rule,
                'wp:postmeta[wp:meta_key="_redirect_rule_status_code"]/wp:meta_value',
                '301'
            ),
            'order' => XpathHelper::getIntValue($rule, 'wp:menu_order', 30),
            'target_type' => $targetData['target_type'],
        ];

        // The collection's entries field stores a list of ids
        if (isset($targetData['target_entry'])) {
            $fields['target_entry'] = [$targetData['target_entry']];
        } else {
            // Every url-type target carries its URL, so the default only types the value.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $fields['target_url'] = $targetData['target_url'] ?? '';
        }

        $notes = HtmlTagStripper::strip(
            XpathHelper::getStringValue($rule, 'wp:postmeta[wp:meta_key="_redirect_rule_notes"]/wp:meta_value')
        );

        if ($notes !== '') {
            $fields['notes'] = $notes;
        }

        return $fields;
    }

    /**
     * The rule WordPress serves for each source, among the published rules (#108).
     *
     * WordPress answers a path with the first rule that matches it, so of the rules sharing a
     * source, the one tried first is the one served. The plugins are tried in the order of
     * PLUGIN_RANKS. Within one plugin, Redirection and Safe Redirect Manager both try their rules
     * by ascending position (`wp:menu_order`) and then by ascending id. Pretty Links slugs are
     * unique, so two of its links never share a source.
     *
     * Sources are compared ignoring case, because `findDomainEntry()` does: the Stache compares
     * a `where()` value in lower case, so `/Flu` and `/flu` reach one entry. The served rule is
     * identified by its position in the export, so two rules with the same `wp:post_id` still
     * resolve to one served rule and one shadowed rule.
     *
     * @param  list<SimpleXMLElement>  $redirectRules  The export's `redirect_rule` items
     * @return array<string, array{index: int, id: string, source: string}> The served rule's
     *                                                                      position in the export, `wp:post_id` and source, keyed by the lower-cased source
     */
    private static function servedRules(array $redirectRules): array
    {
        $served = [];
        $keys = [];

        foreach ($redirectRules as $index => $rule) {
            // An unpublished rule serves nothing, so it never shadows a published one
            if (XpathHelper::getStringValue($rule, 'wp:status') !== 'publish') {
                continue;
            }

            $source = self::ruleSource($rule);
            $match = \strtolower($source);
            $ruleId = XpathHelper::getStringValue($rule, 'wp:post_id');
            $key = self::servingKey($ruleId, XpathHelper::getIntValue($rule, 'wp:menu_order', 0));

            // Keep the rule tried earliest; export order decides only between identical keys
            if (! isset($keys[$match]) || $key < $keys[$match]) {
                $keys[$match] = $key;
                $served[$match] = ['index' => $index, 'id' => $ruleId, 'source' => $source];
            }
        }

        return $served;
    }

    /**
     * Where a rule falls in the order WordPress tries rules, as a comparable list.
     *
     * @param  string  $ruleId  The rule's `wp:post_id`, with its plugin prefix if it has one
     * @param  int  $order  The rule's position within its plugin
     * @return array{int, int, int, string} The plugin's rank, the position, the numeric id, and the whole id
     */
    private static function servingKey(string $ruleId, int $order): array
    {
        $rank = self::POST_RANK;

        foreach (self::PLUGIN_RANKS as $prefix => $pluginRank) {
            if (\str_starts_with($ruleId, $prefix)) {
                $rank = $pluginRank;

                // No id starts with both prefixes, so the later one never matches either way.
                // @pest-mutate-ignore: BreakToContinue
                break;
            }
        }

        // The numeric id breaks a tie in position, as both plugins that allow one do.
        // PHP compares numeric strings as numbers, so the cast only keeps the type.
        // @pest-mutate-ignore: RemoveIntegerCast
        $number = \preg_match('/\d+$/', $ruleId, $matches) === 1 ? (int) $matches[0] : \PHP_INT_MAX;

        return [$rank, $order, $number, $ruleId];
    }

    /**
     * A rule's source, read as the global-set path reads it.
     *
     * @param  SimpleXMLElement  $rule  The `redirect_rule` item
     */
    private static function ruleSource(SimpleXMLElement $rule): string
    {
        return HtmlTagStripper::strip(
            XpathHelper::getStringValue($rule, 'wp:postmeta[wp:meta_key="_redirect_rule_from"]/wp:meta_value')
        );
    }

    /**
     * Every redirect domains entry for a host that an import wrote (wordpress-importer-archive#1311).
     *
     * Only entries carrying a `wordpress_post_id` came from a WordPress rule. An entry an editor
     * authored in the control panel has none, so a re-import never disables it.
     *
     * @param  string  $host  The vanity domain
     * @return list<StatamicEntry>
     */
    private function importedDomainEntries(string $host): array
    {
        $query = EntryQueryHelper::query();
        $query->where('collection', self::DOMAIN_COLLECTION);
        $query->where('host', $host);

        $results = $query->get();

        // A Stache query always answers a collection of concrete entries, so these narrowings
        // only type what follows.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $results instanceof \Illuminate\Support\Collection) {
            return [];
        }

        $entries = [];

        foreach ($results->all() as $entry) {
            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $entry instanceof StatamicEntry) {
                continue;
            }

            $postId = $entry->get('wordpress_post_id');

            if (\is_string($postId) && $postId !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * An entry's id as a string, or an empty string for an entry not yet saved.
     *
     * @param  StatamicEntry  $entry  The entry
     */
    private static function entryId(StatamicEntry $entry): string
    {
        $id = $entry->id();

        // A saved entry always has a string id, so the fallback only types the value.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return \is_string($id) ? $id : '';
    }

    /**
     * The existing redirect domains entry for a host and source, if one was imported before.
     *
     * @param  string  $host  The vanity domain
     * @param  string  $source  The rule's source
     */
    private function findDomainEntry(string $host, string $source): ?StatamicEntry
    {
        $query = EntryQueryHelper::query();
        $query->where('collection', self::DOMAIN_COLLECTION);
        $query->where('host', $host);
        $query->where('source', $source);

        $entry = $query->first();

        // A Stache query answers a concrete entry or null, so the narrowing only types it.
        // @pest-mutate-ignore: InstanceOfToTrue
        return $entry instanceof StatamicEntry ? $entry : null;
    }

    /**
     * Builds a single Statamic redirect row from already-resolved WordPress values.
     *
     * Extracted from `createGlobals()` so the row's shape is verifiable without the
     * host app: the surrounding flow resolves targets through `LinkProcessor`, which
     * needs a booted container, while the row envelope itself is pure.
     *
     * Every imported row is stamped `status: approved`. `UAMS-Web/uams-statamic-archive#1357`
     * gates serving on `status === approved`, and a WordPress `redirect_rule` post is
     * an already-live, trusted redirect rather than a request awaiting review. The app
     * currently grandfathers a missing `status` as approved, so this keeps the mapping
     * explicit rather than fixing a live break — it survives any later tightening that
     * stops grandfathering (wordpress-importer-archive#440).
     *
     * `status` and `enabled` are orthogonal: a row imported from an unpublished
     * WordPress rule is approved but not enabled.
     *
     * @param  string  $source  The source path to match
     * @param  bool  $useRegex  Whether the source is a regular expression
     * @param  array<string, mixed>  $targetData  Resolved target fields for the row
     * @param  string  $response  The HTTP response code to serve
     * @param  int  $order  The row's sort order
     * @param  bool  $enabled  Whether the row is active, from the WordPress publish state
     * @return array<string, mixed> The redirect row
     */
    public function buildRedirectRow(
        string $source,
        bool $useRegex,
        array $targetData,
        string $response,
        int $order,
        bool $enabled
    ): array {
        // ---
        // The resolved target data is spread first so the envelope below always wins:
        // a resolver returning an `id`, `status`, or `type` key must not be able to
        // replace the row's identity or downgrade its approval.
        // ---
        return [
            ...$targetData,
            'id' => (new RowId)->generate(),
            'source' => $source,
            'use_regex' => $useRegex,
            'response' => $response,
            'order' => $order,
            'type' => 'redirect',
            'status' => 'approved',
            'enabled' => $enabled,
        ];
    }

    /**
     * Resolves a WordPress redirect target into the Statamic redirect target
     * schema.
     *
     * Returns either ['target_type' => 'entry', 'target_entry' => $id] when
     * the raw target resolves to an imported Statamic entry, or
     * ['target_type' => 'url', 'target_url' => $value] otherwise. The
     * url branch may contain the original value verbatim, or a rewritten
     * file URL produced by {@see LinkProcessor::processUrlString()} when
     * the target pointed at an imported asset.
     *
     * When $useRegex is true and the raw target itself looks like a pattern
     * (contains backreferences or wildcard tokens), resolution is skipped so
     * the redirect engine can substitute captures at request time.
     *
     * @param  string  $rawTarget  The raw `_redirect_rule_to` value from WordPress
     * @param  bool  $useRegex  Whether the source URL is a regex pattern
     * @param  string  $site  The Statamic site handle being imported into
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @return array{target_type: string, target_url?: string, target_entry?: string}
     *                                                                                The resolved target slice ready to be merged into the redirect row
     */
    private function resolveTarget(
        string $rawTarget,
        bool $useRegex,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): array {
        // Check if the source is a regex AND the target reads as a pattern
        if (
            $useRegex
            && self::looksLikeTargetPattern($rawTarget)
        ) {
            // Skip resolution so capture groups in the target stay intact
            return [
                'target_type' => 'url',
                'target_url' => $rawTarget,
            ];
        }

        // Process the target through LinkProcessor to resolve internal links
        $processed = $this->processTargetUrl(
            $rawTarget,
            $site,
            $assetImporter,
            $output
        );

        // Extract the entry ID if the processed URL is a Statamic entry reference
        $entryId = self::parseEntryReferenceId($processed);

        // Check if the target resolved to an imported entry
        if ($entryId !== null) {
            // Free locals defined in this code path before returning
            unset(
                $processed
            );

            // Return the entry reference shape so the redirect survives renames
            return [
                'target_type' => 'entry',
                'target_entry' => $entryId,
            ];
        }

        // Free locals defined in this code path before returning the URL shape
        unset(
            $entryId
        );

        // Fall back to a plain URL target with the (possibly rewritten) value
        return [
            'target_type' => 'url',
            'target_url' => $processed,
        ];
    }

    /**
     * Forwards a raw redirect target to {@see LinkProcessor::processUrlString()}.
     *
     * Carved out as an instance method so test subclasses can stub the
     * LinkProcessor side-effect (which depends on a fully-booted Statamic
     * application) and exercise {@see resolveTarget()} against real fixture
     * data without going through the static call.
     *
     * @param  string  $rawTarget  The raw `_redirect_rule_to` value
     * @param  string  $site  The Statamic site handle being imported into
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @return string The processed URL — pass-through, file-rewritten URL,
     *                or `statamic://entry::{id}` reference
     */
    protected function processTargetUrl(
        string $rawTarget,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): string {
        // Delegate to LinkProcessor so internal links resolve to entry refs
        return LinkProcessor::processUrlString(
            $rawTarget,
            $site,
            $assetImporter,
            $output
        );
    }

    /**
     * Detects regex/wildcard tokens that mean the target is a pattern, not a
     * resolvable URL.
     *
     * Recognised tokens: backreferences (`$1`), wildcards (`*`), anchors
     * (`^`), character groups (`(`, `[`), escape sequences (`\`), and
     * alternation (`|`). When any of these appear, the target is treated as a
     * substitution template rather than a fixed URL that could map to an
     * imported entry.
     *
     * @param  string  $target  The raw target string to inspect
     * @return bool True when the target contains regex or wildcard tokens
     */
    private static function looksLikeTargetPattern(
        string $target
    ): bool {
        // Match any single pattern token in one pass and return the boolean
        return (bool) \preg_match(
            '/(\$\d|\*|\^|\(|\[|\\\\|\|)/',
            $target
        );
    }

    /**
     * Extracts the entry ID from a Statamic entry reference URL.
     *
     * Accepts the shape `statamic://entry::{id}[?query][#fragment]` produced
     * by {@see LinkProcessor::generateEntryReference()} and returns just the
     * `{id}` segment. The split on the first `?` or `#` makes this independent
     * of the suffix order. Any trailing query or fragment is discarded because
     * `target_entry` stores only the bare entry ID.
     *
     * @param  string  $url  The URL to inspect (entry reference or otherwise)
     * @return ?string The entry ID, or null when the URL is not an entry
     *                 reference or carries an empty ID
     */
    private static function parseEntryReferenceId(
        string $url
    ): ?string {
        // Check if the URL uses the Statamic entry reference scheme
        if (
            ! \str_starts_with(
                $url,
                'statamic://entry::'
            )
        ) {
            return null;
        }

        // Strip the scheme prefix so only the ID (+ optional suffix) remains
        $remainder = \substr(
            $url,
            \strlen('statamic://entry::')
        );

        // Take the ID portion, before any trailing fragment or query
        $id = \substr(
            $remainder,
            0,
            \strcspn($remainder, '#?')
        );

        // Treat an empty ID as a non-reference so callers fall through cleanly
        return $id === '' ? null : $id;
    }
}
