<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;

/**
 * Loads and parses MinervaKB settings, from the migration API or from a file.
 *
 * This class is responsible for:
 *   - Reading the `minerva-kb-options` row from the migration API's options endpoint
 *   - Reading file content from the filesystem
 *   - Parsing JSON content into PHP arrays
 *   - Handling read errors
 *   - Managing JSON parsing errors
 *
 * **Why only this class changed (wordpress-importer-archive#586).** `MKB_Options` persists the entire MinervaKB
 * settings set as a single `wp_options` row — `update_option('minerva-kb-options',
 * json_encode($options))` — and the stored JSON is the same shape the hand-made export file
 * carries. So the value arrives already in the form `Validator`, `Extractor`, `Selector` and
 * `Prompter` consume, and none of them is touched.
 *
 * **The API source raises where the file source returns null, and that asymmetry is
 * deliberate.** A file the operator chose and that then fails to parse is recoverable — they
 * are standing at the prompt and can pick another. A row absent from the options payload is
 * not: it means either MinervaKB is not installed on that site, or the endpoint **withheld**
 * the row because its secret rule matched something inside it. Both produce the same silent
 * outcome if swallowed — an import that proceeds with no knowledge-base configuration and
 * reports success. Whether this network's blob actually survives that filter is wordpress-importer-archive#665.
 *
 * Follows the Single Responsibility Principle by focusing solely on read and parse
 * operations.
 */
class Loader
{
    /**
     * The `wp_options` rows MinervaKB stores its entire settings set in, newest generation
     * first.
     *
     * Two generations are in production and they use different row names (wordpress-importer-archive#1107). A site
     * running the v2 UAMS fork writes `uamswp-minerva-kb-options`; the v1 fork and the
     * original plugin write `minerva-kb-options`. **A v2 site usually carries BOTH**, because
     * the bare row is the same site's configuration frozen when v2 took over — recognisably
     * the right site, and since drifted. Hardcoding the bare name therefore did not fail on
     * such a site; it imported a stale configuration and reported success.
     *
     * **Order no longer chooses between them (wordpress-importer-archive#1134).** A site that serves both rows is refused
     * — see `refuseWhereBothRowsAreServed()` — so at most one row is ever read, and the order
     * only decides which name a message lists first.
     *
     * **The basis this preference was first recorded on has since been disproved, and two
     * candidate replacements with it (wordpress-importer-archive#1124).** The original claim rested on the prefixed row
     * appearing alongside a `2.0.x` migrations record; measured, orphaned rows left behind by
     * a *removed* v2 plugin carry that same record, identical to a site where v2 runs, so it
     * does not discriminate at all. Nor does the plugin's directory slug: both generations
     * install from one repository differing only by branch, so both occupy `UAMSWP-minervakb`
     * and emit the same slug.
     *
     * **So a row's NAME does not establish that the plugin which wrote it is still installed**,
     * and a `wp_options` row outlives the plugin that wrote it. What the payload can settle is
     * narrower — see `noKnowledgeBasePluginIsActive()` — and wordpress-importer-archive#1124 carries the rest.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails a test in MinervaKbOptionsSourceTest (#100)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array OPTION_KEYS = [
        'uamswp-minerva-kb-options',
        'minerva-kb-options',
    ];

    /**
     * The generations an operator may declare, keyed by the name the command accepts, each
     * mapped to the settings row that generation writes (wordpress-importer-archive#1235).
     *
     * @var array<string, string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails a test in MinervaKbOptionsSourceTest (#100)
    // @pest-mutate-ignore: RemoveArrayItem
    public const DECLARABLE_GENERATIONS = [
        'v1' => 'minerva-kb-options',
        'v2' => 'uamswp-minerva-kb-options',
    ];

    /**
     * The generations, keyed by the `Text Domain` each declares in its plugin header (wordpress-importer-archive#1262).
     *
     * The header is what the network plugins page shows, and the migration API serves it read
     * from disk for every active plugin (its D-087). It is the one signal that names the
     * generation that is installed rather than one that was activated or once configured: a
     * v1 plugin has run from v2's entry file (wordpress-importer-archive#1134), and every other `/options` signal
     * describes the database (wordpress-importer-archive#1125).
     *
     * @var array<string, string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails a test in MinervaKbOptionsSourceTest (#100)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array TEXT_DOMAIN_GENERATIONS = [
        'minerva-kb' => 'v1',
        'uamswp-minerva-kb' => 'v2',
    ];

    /**
     * The migration API client, when this run reads from the API rather than a file.
     */
    private ?Client $client = null;

    /**
     * The network site id to read, when this run reads from the API.
     */
    private ?int $site = null;

    /**
     * The settings row of the generation the operator declared for this run, if any (wordpress-importer-archive#1235).
     */
    private ?string $declaredRow = null;

    /**
     * Read settings from the migration API instead of a file for this run.
     *
     * Mirrors the `setXmlParser()` idiom the entry creators and coordinators already use:
     * the collaborator is supplied after construction, because the source is only known
     * once the command has parsed its options.
     *
     * **The declared generation is set here rather than by its own setter**, so every call
     * that configures a source also states whether a generation was declared. A second run in
     * the same process cannot inherit the first one's declaration, for the same reason
     * `clearMigrationApiSource()` exists.
     *
     * @param  Client  $client  The migration API client
     * @param  int|null  $site  The network site id to read, when scoping is needed
     * @param  string|null  $generation  The generation the operator declared, a key of
     *                                   `DECLARABLE_GENERATIONS`, or null for none (wordpress-importer-archive#1235)
     *
     * @throws \InvalidArgumentException When the generation is not one this loader knows
     */
    public function setMigrationApiSource(
        Client $client,
        ?int $site = null,
        ?string $generation = null
    ): void {
        // Refuse an unknown generation here as well as in the command, so no caller can set one
        // that silently matches nothing
        if ($generation !== null && ! isset(self::DECLARABLE_GENERATIONS[$generation])) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown MinervaKB generation "%s"; expected one of: %s.',
                $generation,
                \implode(', ', \array_keys(self::DECLARABLE_GENERATIONS))
            ));
        }

        $this->client = $client;
        $this->site = $site;
        $this->declaredRow = $generation === null ? null : self::DECLARABLE_GENERATIONS[$generation];
    }

    /**
     * Whether this run reads settings from the migration API.
     *
     * `Selector` asks so it can skip the file prompt — on an API-sourced run there is no
     * local file to prompt for, and prompting produced wordpress-importer-archive#679: the operator pressed Enter,
     * the selector returned before touching this class, and the raise below was unreachable
     * in production.
     *
     * @return bool True when a migration API source has been configured
     */
    public function hasMigrationApiSource(): bool
    {
        return $this->client instanceof Client;
    }

    /**
     * The generation the operator declared for this run, or null when none was (wordpress-importer-archive#1235).
     *
     * @return string|null A key of `DECLARABLE_GENERATIONS`, or null
     */
    public function declaredGeneration(): ?string
    {
        // The rows are distinct non-empty strings, so a null row matches none of them, and a
        // loose comparison finds the same key as a strict one.
        // @pest-mutate-ignore: TrueToFalse
        $generation = \array_search($this->declaredRow, self::DECLARABLE_GENERATIONS, true);

        return \is_string($generation) ? $generation : null;
    }

    /**
     * Return this loader to reading from a file.
     *
     * The loader is a container singleton, so the source it was last given outlives the run
     * that set it. In production that is harmless — one command per process — but a second
     * import in the same process would otherwise inherit the first one's source and read the
     * API while its operator was answering a file prompt. The command sets the source
     * explicitly on **both** branches rather than only on the API one, so the state is a
     * property of the current run instead of of whatever ran before it.
     */
    public function clearMigrationApiSource(): void
    {
        $this->client = null;
        $this->site = null;
        $this->declaredRow = null;
    }

    /**
     * Load and parse the MinervaKB settings JSON file.
     *
     * @param  string  $filePath  The path to the JSON file
     * @param  OutputInterface  $output  Console output interface
     * @return array<int|string, mixed>|null The parsed settings array or null if loading fails
     */
    public function loadSettings(
        string $filePath,
        OutputInterface $output
    ): ?array {
        try {
            // Read the settings from whichever source this run was configured with. The
            // file path is ignored entirely on the API path — the row is the source of
            // truth there, and there is no file to fall back to.
            // The label a parse failure is reported against: the row that was actually
            // selected on the API path, rather than a fixed name that may not be the row
            // this site's settings came from (wordpress-importer-archive#1107).
            $sourceLabel = $filePath;

            if ($this->client instanceof Client) {
                $selected = $this->readOptionValue($this->client, $output);
                $sourceLabel = $selected['key'];
                $jsonContent = $selected['value'];
            } else {
                $jsonContent = $this->readFileContent($filePath, $output);
            }

            if ($jsonContent === null) {
                return null;
            }

            // Parse JSON content
            $settings = $this->parseJsonContent(
                $jsonContent,
                $sourceLabel,
                $output
            );

            return $settings;
        } catch (MigrationApiException $e) {
            // Deliberately re-thrown ahead of the generic handler below. Reporting an
            // absent or withheld row as "could not load settings" and returning null is
            // exactly the silent outcome this path exists to prevent.
            throw $e;
        } catch (\Exception $e) {
            $output->writeln("<error>Error loading settings file: {$e->getMessage()}</error>");
            Log::error("Loader::loadSettings: Error loading settings file {$filePath}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * The directory a MinervaKB plugin installs into, matched case-insensitively.
     *
     * **One directory covers both generations.** They install from a single WP Pusher
     * repository differing only by branch, so the slug cannot say which generation is
     * running — only whether *some* MinervaKB is (wordpress-importer-archive#1124).
     *
     * Case-insensitive because the same plugin has been observed under two casings on one
     * network — `UAMSWP-minervakb` and `uamswp-minervakb` — and the endpoint's own diffing
     * is `array_diff`, which is case-sensitive, so one site reports the slug in both the
     * added and the removed list at once.
     */
    private const string PLUGIN_DIRECTORY = 'uamswp-minervakb';

    /**
     * The entry files the two generations install under, keyed by the row each one writes.
     *
     * Both install from one repository into one directory, so the slug cannot tell them apart:
     * what differs is the root PHP file — `minervakb.php` at tag `1.6.6.3`,
     * `uamswp-minervakb.php` on branch `v2.0.8`.
     *
     * **It no longer chooses a row (wordpress-importer-archive#1134).** A file's name is not its generation: `hr` ran v1
     * `1.6.6` from `uamswp-minervakb.php`, and `cancer` carries that one filename under both
     * generations at once. Every `/options` signal describes the database, and which
     * generation runs is a fact about the disk (wordpress-importer-archive#1125), so no payload signal can settle it. The
     * map now reaches only two places, and neither selects: the v1 name can stop an import
     * (`refuseOrphanedRowUnderV1()`), and the v2 name can print a warning. Choosing between two
     * served rows is refused instead — see `refuseWhereBothRowsAreServed()`.
     *
     * Matched on the basename of an `active_plugins` entry, because the directory casing
     * varies between sites and a disabled-plugin convention prefixes it on some.
     *
     * @var array<string, string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails a test in MinervaKbOptionsSourceTest (#100)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array ENTRY_FILE_ROWS = [
        'minervakb.php' => 'minerva-kb-options',
        'uamswp-minervakb.php' => 'uamswp-minerva-kb-options',
    ];

    /**
     * Refuse a site whose active plugin is v1 and whose v1 row was not served (wordpress-importer-archive#1133).
     *
     * **Decided for the one direction the entry file can settle.** `minervakb.php` is the root
     * file of every v1 release and of no v2 one, read across every branch and tag of
     * `UAMS-Web/UAMSWP-minervakb`, so an active entry under that name means v1 is what runs.
     * `uamswp-minervakb.php` is not symmetric: it is v2's name, and a v1 `1.6.6` has run from
     * a file of that name (wordpress-importer-archive#1134). So this acts on the v1 name only and never on the other.
     *
     * With v1 running and its row absent, whatever row was served was written by a generation
     * this site does not run: `inside.uams.edu/tri` serves v2's `uamswp-minerva-kb-options`,
     * left behind by a plugin its network does not have, while v1 runs on its defaults having
     * never written a row. Importing that configuration was the defect; stopping is the
     * recoverable outcome, the same trade `noKnowledgeBasePluginIsActive()` makes.
     *
     * **It is still a database signal, so it is not free of the database-versus-disk wall**
     * wordpress-importer-archive#1125 describes. It could refuse a correct site only where v2 runs while `active_plugins`
     * still names the v1 file, and activating v2 writes its own entry. The failure it risks is
     * a stopped import with this message, never a plausible wrong one.
     *
     * The v1 row may exist and have been withheld by the endpoint rather than be absent, so
     * the message quotes the withheld counts rather than asserting the row is gone.
     *
     * @param  string|null  $installed  The row the installed generation writes, if determined
     * @param  array<string, string>  $servedValues  The settings rows served, keyed by name
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     *
     * @throws MigrationApiException When v1 is active and its row was not served
     */
    private function refuseOrphanedRowUnderV1(
        ?string $installed,
        array $servedValues,
        array $payload
    ): void {
        // The row v1 writes, taken from the entry-file map rather than restated
        $v1Row = self::ENTRY_FILE_ROWS['minervakb.php'];

        // Only the v1 entry file is trusted to name its generation, and only its absent row
        // is refused on
        if ($installed !== $v1Row || isset($servedValues[$v1Row])) {
            return;
        }

        $withheld = $this->deniedCounts($payload);

        throw new MigrationApiException(\sprintf(
            'The MinervaKB plugin active on this site is v1 (its entry file is minervakb.php), '
            .'and v1 writes "%s", which the migration API did not serve. The row it did serve '
            .'("%s") is written by v2, which is not what runs here, so it is a leftover this '
            .'site does not use. The endpoint reports %s rows withheld by shape and %s by name '
            .'and never says which, so "%s" may exist and have been withheld rather than be '
            .'absent. Refusing to import knowledge base content with a configuration this site '
            .'does not use.',
            $v1Row,
            \implode('", "', \array_keys($servedValues)),
            $withheld['by_shape'],
            $withheld['by_name'],
            $v1Row
        ));
    }

    /**
     * Refuse a site that serves both generations' settings rows (wordpress-importer-archive#1134).
     *
     * **Decided by the operator on 2026-09-28**, over keeping the entry-file map as a warned
     * heuristic. With both rows served, one of them is a leftover of a generation the site no
     * longer runs, and nothing `/options` serves can say which: every signal it carries
     * describes the database, and which plugin runs is a fact about the disk (wordpress-importer-archive#1125). The
     * entry-file name was the last candidate, and it is not sound — `hr` ran v1 from v2's
     * filename, and `cancer` carries one filename under both generations.
     *
     * **The cost is deliberate.** Four measured sites serve both rows and imported correctly
     * by the map — `cancer/root`, `communications/creative-services`, `communications/web` and
     * `inside/clinicaleducation` — and each now stops here. A stopped import names both rows
     * and is recoverable; a plausible import of the wrong generation's settings is not, and
     * the map would have produced one silently on any site whose filename and generation
     * disagreed.
     *
     * @param  list<string>  $servedKeys  The settings rows served, in `OPTION_KEYS` order
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     *
     * @throws MigrationApiException When more than one settings row was served
     */
    private function refuseWhereBothRowsAreServed(
        array $servedKeys,
        array $payload
    ): void {
        if (\count($servedKeys) < 2) {
            return;
        }

        $withheld = $this->deniedCounts($payload);

        throw new MigrationApiException(\sprintf(
            'The migration API served both MinervaKB settings rows ("%s"). Each generation of the '
            .'plugin writes its own, so one of them is a leftover of a generation this site no '
            ."longer runs, and nothing the endpoint serves can say which: a plugin's entry-file "
            .'name is not its generation. Read which generation is active from the network '
            .'plugins page (wp-admin/network/plugins.php), then re-run with '
            .'--minerva-generation=v1 or --minerva-generation=v2. (The endpoint also '
            .'reports %s rows withheld by shape and %s by name.) Refusing to import knowledge '
            .'base content from a guess.',
            \implode('", "', $servedKeys),
            $withheld['by_shape'],
            $withheld['by_name']
        ));
    }

    /**
     * The settings row written by whichever generation is installed, or null if undetermined.
     *
     * Reads `active_plugins`, which the options endpoint serves as an ordinary `wp_options`
     * row — it matches no denylist pattern. The endpoint's own `denylist` provenance reduces
     * the same data to directory slugs and so cannot be used for this; the raw row keeps the
     * entry file.
     *
     * **It never selects a row (wordpress-importer-archive#1134).** Its answer feeds the v1 refusal and the v2 warning
     * only. One measured site carries a v2 entry file while serving only the v1 row, so letting
     * this decide there would refuse an import that works; where it names a row that was not
     * served, the caller says so and still reads the row it has (wordpress-importer-archive#1125).
     *
     * **The signal records what was activated, not what is installed.** The plugin's own
     * history rules out the obvious reading of that measured site: `uamswp-minervakb.php`
     * has two commits ever, the older of them `v2.0.8.1 Initial Commit`, so no state of that
     * repository pairs the v2 filename with the 1.6.6 the site reports. Which of the two
     * generations actually runs there is what wordpress-importer-archive#1125 is open on.
     *
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     * @return string|null The row name the installed generation writes, or null
     */
    private function rowOfInstalledGeneration(
        array $payload
    ): ?string {
        $options = $payload['options'] ?? null;
        $active = \is_array($options) ? ($options['active_plugins'] ?? null) : null;

        // Served as the stored value, which decodes to a list on some sites and to a map with
        // numeric keys on others. Both are iterated the same way; reading keys rather than
        // values is what made an earlier reading of this data report no entry at all.
        if (\is_string($active)) {
            $decoded = \json_decode($active, true);
            $active = \is_array($decoded) ? $decoded : null;
        }

        if (! \is_array($active)) {
            return null;
        }

        $found = [];

        foreach ($active as $path) {
            if (! \is_string($path)) {
                continue;
            }

            $basename = \strtolower(\basename($path));

            if (isset(self::ENTRY_FILE_ROWS[$basename])) {
                // Only the key is read, so the value is never seen
                // @pest-mutate-ignore: TrueToFalse
                $found[self::ENTRY_FILE_ROWS[$basename]] = true;
            }
        }

        // Exactly one generation, or nothing to say. Both installed at once is not a state any
        // site has shown, and guessing between them would be the thing this method exists to
        // avoid. With one key, the first is the last.
        // @pest-mutate-ignore: ArrayKeyFirstToArrayKeyLast
        return \count($found) === 1 ? \array_key_first($found) : null;
    }

    /**
     * The MinervaKB generation whose plugin header the endpoint read from disk, if it says.
     *
     * Reads `plugins`, which the options endpoint serves beside the database fields: one entry
     * per active plugin path, each with the `Name`, `Version` and `TextDomain` its file declares,
     * or a `null` header where no installed plugin sits at that path (its D-087). The endpoint
     * reads the header without the fallback WordPress applies, so an empty `TextDomain` means
     * the file declares none rather than one inferred from the directory.
     *
     * Returns null, leaving wordpress-importer-archive#1235's behavior unchanged, wherever the answer is not one
     * generation: an endpoint that serves no `plugins` field, no header naming either
     * generation, or headers naming both at once.
     *
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     * @return array{generation: string, textDomain: string}|null The generation and the Text
     *                                                            Domain that named it, or null
     */
    private function generationOnDisk(
        array $payload
    ): ?array {
        $plugins = $payload['plugins'] ?? null;

        if (! \is_array($plugins)) {
            return null;
        }

        $found = [];

        foreach ($plugins as $plugin) {
            $header = \is_array($plugin) ? ($plugin['header'] ?? null) : null;
            $domain = \is_array($header) ? ($header['TextDomain'] ?? null) : null;

            if (\is_string($domain) && isset(self::TEXT_DOMAIN_GENERATIONS[$domain])) {
                $found[self::TEXT_DOMAIN_GENERATIONS[$domain]] = $domain;
            }
        }

        // Both generations installed at once decides nothing, so it is left to wordpress-importer-archive#1235's rules
        if (\count($found) !== 1) {
            return null;
        }

        // Exactly one key remains, so its first and last are the same key.
        // @pest-mutate-ignore: ArrayKeyFirstToArrayKeyLast
        $generation = \array_key_first($found);

        return [
            'generation' => $generation,
            'textDomain' => $found[$generation],
        ];
    }

    /**
     * Read the row of the generation the plugin header on disk names (wordpress-importer-archive#1262).
     *
     * **The header outranks every database signal, as a declaration does.** It is the field
     * the network plugins page shows, read from the plugin file itself, so it answers what the
     * entry-file rules (wordpress-importer-archive#1133, wordpress-importer-archive#1129) and the both-rows refusal (wordpress-importer-archive#1134) exist to approximate,
     * and none of them is consulted. That makes `--minerva-generation` optional wherever the
     * endpoint serves the header.
     *
     * A header whose generation's row was not served is refused, for the reason a declaration
     * is: the other row was written by a generation this site does not run, and the missing
     * one may have been withheld.
     *
     * @param  array{generation: string, textDomain: string}  $onDisk  The generation the header
     *                                                                 names
     * @param  list<string>  $servedKeys  The settings rows served, in `OPTION_KEYS` order
     * @param  array<string, string>  $servedValues  The settings rows served, keyed by name
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     * @param  OutputInterface  $output  Console output interface
     * @return array{key: string, value: string} The row's name and its raw stored JSON
     *
     * @throws MigrationApiException When the installed generation's row was not served
     */
    private function readRowTheDiskNames(
        array $onDisk,
        array $servedKeys,
        array $servedValues,
        array $payload,
        OutputInterface $output
    ): array {
        $row = self::DECLARABLE_GENERATIONS[$onDisk['generation']];

        // The other generation's row is not a substitute for the installed one's
        if (! isset($servedValues[$row])) {
            $withheld = $this->deniedCounts($payload);

            throw new MigrationApiException(\sprintf(
                'The MinervaKB plugin installed on this site declares Text Domain "%s", read from '
                .'disk by the migration API, so it runs generation %s, which writes "%s". The '
                .'migration API did not serve that row. It served "%s". The endpoint reports %s '
                .'rows withheld by shape and %s by name and never says which, so "%s" may exist '
                ."and have been withheld. Refusing to read another generation's row in its place.",
                $onDisk['textDomain'],
                $onDisk['generation'],
                $row,
                \implode('", "', $servedKeys),
                $withheld['by_shape'],
                $withheld['by_name'],
                $row
            ));
        }

        // Say where the choice came from, since the operator did not make it
        $output->writeln(\sprintf(
            '<info>Fetched the MinervaKB settings row "%s" from the migration API, chosen by the '
            .'installed plugin\'s Text Domain "%s" (generation %s), read from disk.</info>',
            $row,
            $onDisk['textDomain'],
            $onDisk['generation']
        ));

        return [
            'key' => $row,
            'value' => $servedValues[$row],
        ];
    }

    /**
     * Refuse a declared generation that the plugin header on disk contradicts (wordpress-importer-archive#1262).
     *
     * The declaration is the operator's read of the network plugins page, and the header the
     * endpoint serves is the same field read from the same file. Where they disagree, the
     * declaration is the one that can be mistaken, and reading its row would import the other
     * generation's settings under the operator's name.
     *
     * @param  array{generation: string, textDomain: string}|null  $onDisk  The generation the
     *                                                                      header names, if any
     *
     * @throws MigrationApiException When the header names the other generation
     */
    private function refuseDeclarationTheDiskContradicts(
        ?array $onDisk
    ): void {
        $declared = $this->declaredGeneration();

        if ($onDisk === null || $onDisk['generation'] === $declared) {
            return;
        }

        throw new MigrationApiException(\sprintf(
            'The run declares MinervaKB generation %s (--minerva-generation=%s), but the MinervaKB '
            .'plugin installed on this site declares Text Domain "%s", which is generation %s, '
            ."read from disk by the migration API. Refusing to import generation %s's settings "
            .'for a site that runs generation %s. Check the network plugins page, and re-run with '
            .'--minerva-generation=%s or without the option.',
            $declared,
            $declared,
            $onDisk['textDomain'],
            $onDisk['generation'],
            $declared,
            $onDisk['generation'],
            $onDisk['generation']
        ));
    }

    /**
     * Whether the payload establishes that NO MinervaKB plugin is installed on this site.
     *
     * The options endpoint serves a `denylist` provenance block built from the site's live
     * plugin set — `active_plugins` plus the network's `active_sitewide_plugins` — reduced to
     * directory slugs and diffed against a reviewed baseline. `added` is what runs here and
     * was never reviewed; `removed` is what was reviewed and does not run here.
     *
     * **Only one direction of that is safe to act on.** A slug in `added` proves the plugin
     * runs; a slug in neither list is ambiguous, because the baseline is a union across the
     * network and is reported stale on every site measured. What this method asserts is the
     * narrow, sound case: the directory appears in `removed` and **nowhere** in `added`, so
     * nothing of either generation is installed and every settings row the site serves is a
     * leftover.
     *
     * Returning false therefore means *not established*, never *a plugin is present*.
     *
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload, typed as
     *                                             the client returns it rather than narrowed
     *                                             at the call site
     * @return bool True only when the payload positively establishes that none is installed
     */
    private function noKnowledgeBasePluginIsActive(
        array $payload
    ): bool {
        $denylist = $payload['denylist'] ?? null;

        // A missing offset read with `??` from a value that is not an array is null as well, so
        // this only narrows the type for phpstan
        if (! \is_array($denylist)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return false;
        }

        $mentions = static function (mixed $slugs): bool {
            if (! \is_array($slugs)) {
                return false;
            }

            return array_any($slugs, fn ($slug): bool => \is_string($slug)
            && \str_contains(\strtolower($slug), self::PLUGIN_DIRECTORY));
        };

        // `added` first: it is the half that proves presence, and a site running the plugin
        // under a second casing reports it in both lists at once.
        if ($mentions($denylist['added'] ?? null)) {
            return false;
        }

        return $mentions($denylist['removed'] ?? null);
    }

    /**
     * Read the MinervaKB settings row from the migration API's options endpoint.
     *
     * Looks across `OPTION_KEYS` rather than reading a fixed name, because two plugin
     * generations use different rows (wordpress-importer-archive#1107). A site serving exactly one is read; a site
     * serving both is refused, because the payload cannot say which is live (wordpress-importer-archive#1134). The read
     * is per call, so it follows the site this run is scoped to — generation is per site, not
     * per network, and one network in the corpus runs different plugins on different sites.
     *
     * A row present but empty is treated as **not served**, so selection continues to the
     * next name rather than stopping on a key that exists and carries nothing.
     *
     * @param  Client  $client  The migration API client, passed in rather than re-read from
     *                          the property so this method needs no unreachable guard
     * @param  OutputInterface  $output  Console output interface
     * @return array{key: string, value: string} The selected row name and its raw stored JSON
     *
     * @throws MigrationApiException When no candidate row is served, when both are, or when
     *                               the row served is a leftover the site does not use
     */
    protected function readOptionValue(
        Client $client,
        OutputInterface $output
    ): array {
        $payload = $client->get('options', $this->site);

        $options = $payload['options'] ?? null;

        /** @var array{key: string, value: string}|null $selected */
        $selected = null;

        /** @var list<string> $servedKeys */
        $servedKeys = [];

        /** @var array<string, string> $servedValues */
        $servedValues = [];

        foreach (self::OPTION_KEYS as $candidate) {
            $served = \is_array($options) ? ($options[$candidate] ?? null) : null;

            if (! \is_string($served) || $served === '') {
                continue;
            }

            $servedKeys[] = $candidate;
            $servedValues[$candidate] = $served;

            // The first served row is the one read; a second one is refused below rather than
            // passed over. With at most one row reaching a read, first and last are the same.
            // @pest-mutate-ignore: CoalesceEqualToEqual
            $selected ??= [
                'key' => $candidate,
                'value' => $served,
            ];
        }

        if ($selected === null) {
            // Name both causes, because they have entirely different fixes and the payload
            // cannot distinguish them: the endpoint reports *how many* rows it withheld,
            // never which. See wordpress-importer-archive#665.
            $withheld = $this->deniedCounts($payload);

            throw new MigrationApiException(\sprintf(
                'The migration API served no MinervaKB settings row (tried "%s"). Either '
                .'MinervaKB is not installed on this site, it is installed but was never '
                .'configured, or the options endpoint withheld the row (it reports %s withheld '
                .'by shape and %s by name). Refusing to import knowledge base content with no '
                .'configuration.',
                \implode('", "', self::OPTION_KEYS),
                $withheld['by_shape'],
                $withheld['by_name']
            ));
        }

        // Which generation the plugin file on disk declares, where the endpoint serves headers
        $onDisk = $this->generationOnDisk($payload);

        // An operator who read the network plugins page has said which generation runs, so
        // their declaration decides where the payload cannot (wordpress-importer-archive#1235) - unless the endpoint
        // read the same header from disk and it names the other generation (wordpress-importer-archive#1262)
        $declaredRow = $this->declaredRow;

        if ($declaredRow !== null) {
            $this->refuseDeclarationTheDiskContradicts($onDisk);

            return $this->readDeclaredRow($declaredRow, $servedKeys, $servedValues, $payload, $output);
        }

        // The header on disk names the installed generation, which is the answer every rule
        // below approximates from the database, so it decides in their place (wordpress-importer-archive#1262)
        if ($onDisk !== null) {
            return $this->readRowTheDiskNames($onDisk, $servedKeys, $servedValues, $payload, $output);
        }

        // The value is stored as a JSON string rather than PHP-serialized, and the endpoint
        // serves it as the string it is (its D-009) — so it is decoded here, not there.
        //
        // The active entry file is read for the two single-row shapes wordpress-importer-archive#1125 measured, which
        // are mirrors wanting OPPOSITE rules. One carries a v2 entry file while serving only
        // the v1 row, and imports correctly; refusing there would break it. The other carries
        // a v1 entry file while serving only a v2 row left behind by a plugin its network does
        // not have, and importing it is wrong. The v1 name decides the second shape
        // (`refuseOrphanedRowUnderV1()`, wordpress-importer-archive#1133); the first reads the row it has, with the
        // warning below. The entry file never selects a row (wordpress-importer-archive#1134).
        $installed = $this->rowOfInstalledGeneration($payload);

        $this->refuseOrphanedRowUnderV1($installed, $servedValues, $payload);

        // A row was found — but a `wp_options` row outlives the plugin that wrote it, and on a
        // site where no MinervaKB is installed every row served is a leftover (wordpress-importer-archive#1124). Refuse
        // rather than import a configuration the site stopped using: the failure this replaces
        // is silent, and a stopped import is recoverable where a plausible wrong one is not.
        // Checked before the both-rows refusal, because where it applies it is the more
        // specific diagnosis.
        $this->refuseWhereNoPluginIsActive($servedKeys, $payload);

        $this->refuseWhereBothRowsAreServed($servedKeys, $payload);

        // The installed generation is known and its row is NOT among those served. With the
        // v1 case refused above, only the v2 entry file reaches this, and that name is the
        // unreliable one: a v1 plugin has run from it and imported correctly from the v1
        // row (wordpress-importer-archive#1134). So this reads the row it has and says so, rather than deciding. The
        // row read may still be a leftover, and an operator cannot recover that from the
        // output afterwards.
        if ($installed !== null && ! isset($servedValues[$installed])) {
            $withheld = $this->deniedCounts($payload);

            $output->writeln(\sprintf(
                '<comment>Warning: the MinervaKB generation installed on this site writes '
                .'"%s", and that row is not among those the migration API served. The row '
                .'read instead ("%s") is written by the other generation. The endpoint '
                .'reports %s rows withheld by shape and %s by name and never reports which, '
                .'so "%s" may exist here and have been withheld rather than be absent. If '
                .'this site runs the generation that writes "%s", these are not the settings '
                .'it uses.</comment>',
                $installed,
                $selected['key'],
                $withheld['by_shape'],
                $withheld['by_name'],
                $installed,
                $installed
            ));
        }

        // Name the row, because two are possible and which one answered is provenance the
        // operator cannot recover afterwards.
        $output->writeln(\sprintf(
            '<info>Fetched the MinervaKB settings row "%s" from the migration API.</info>',
            $selected['key']
        ));

        return $selected;
    }

    /**
     * Read the row of the generation the operator declared (wordpress-importer-archive#1235).
     *
     * **The declaration outranks every payload signal, and only the payload can refuse it.**
     * It comes from the network plugins page, which reads plugin headers from disk, so it
     * answers the question no `/options` signal can (wordpress-importer-archive#1125). The entry-file refusal and warning
     * (wordpress-importer-archive#1133, wordpress-importer-archive#1129) and the both-rows refusal (wordpress-importer-archive#1134) exist because that answer was missing,
     * and they are not consulted here.
     *
     * Two things still stop the run. A declared generation whose row was not served has nothing
     * to read, and reading the other row instead would import the wrong generation's settings
     * under the operator's name. And where the endpoint reports no MinervaKB installed at all,
     * the declaration and the site disagree, so the run stops rather than choose between them.
     *
     * @param  string  $declared  The settings row of the declared generation
     * @param  list<string>  $servedKeys  The settings rows served, in `OPTION_KEYS` order
     * @param  array<string, string>  $servedValues  The settings rows served, keyed by name
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     * @param  OutputInterface  $output  Console output interface
     * @return array{key: string, value: string} The declared row's name and its raw stored JSON
     *
     * @throws MigrationApiException When the declared row was not served, or no plugin is
     *                               installed
     */
    private function readDeclaredRow(
        string $declared,
        array $servedKeys,
        array $servedValues,
        array $payload,
        OutputInterface $output
    ): array {
        // The generation named for the row, for messages
        // Never null here, since a row was declared, so the fallback is unreachable.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $generation = $this->declaredGeneration() ?? '';

        // A declared row that was not served has nothing to read, and the other row is not a
        // substitute for it
        if (! isset($servedValues[$declared])) {
            $withheld = $this->deniedCounts($payload);

            throw new MigrationApiException(\sprintf(
                'The run declares MinervaKB generation %s (--minerva-generation=%s), which writes '
                .'"%s", and the migration API did not serve that row. It served "%s". The endpoint '
                .'reports %s rows withheld by shape and %s by name and never says which, so "%s" '
                ."may exist and have been withheld. Refusing to read another generation's row "
                .'in its place.',
                $generation,
                $generation,
                $declared,
                \implode('", "', $servedKeys),
                $withheld['by_shape'],
                $withheld['by_name'],
                $declared
            ));
        }

        $this->refuseWhereNoPluginIsActive($servedKeys, $payload);

        // Say where the choice came from, since the payload did not make it
        $output->writeln(\sprintf(
            '<info>Fetched the MinervaKB settings row "%s" from the migration API, chosen by the '
            .'declared generation %s (--minerva-generation=%s).</info>',
            $declared,
            $generation,
            $generation
        ));

        return [
            'key' => $declared,
            'value' => $servedValues[$declared],
        ];
    }

    /**
     * Refuse where the endpoint reports that no MinervaKB plugin is installed (wordpress-importer-archive#1124).
     *
     * A `wp_options` row outlives the plugin that wrote it, and on a site where no MinervaKB is
     * installed every row served is a leftover. The failure this replaces is silent, and a
     * stopped import is recoverable where a plausible wrong one is not.
     *
     * @param  list<string>  $servedKeys  The settings rows served, in `OPTION_KEYS` order
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     *
     * @throws MigrationApiException When the payload establishes that no plugin is installed
     */
    private function refuseWhereNoPluginIsActive(
        array $servedKeys,
        array $payload
    ): void {
        if (! $this->noKnowledgeBasePluginIsActive($payload)) {
            return;
        }

        throw new MigrationApiException(\sprintf(
            'The migration API served a MinervaKB settings row ("%s"), but this site has no '
            .'MinervaKB plugin installed — the endpoint reports the plugin directory as not '
            .'running here. A `wp_options` row outlives the plugin that wrote it, so the row '
            .'is a leftover and its configuration is not what this site uses. Refusing to '
            .'import knowledge base content from it.',
            \implode('", "', $servedKeys)
        ));
    }

    /**
     * The endpoint's withheld-row counts, rendered for a message.
     *
     * The options endpoint reports *how many* rows it withheld, `by_shape` and `by_name`,
     * and never which. Both messages that quote those counts need the same defensive read,
     * so it lives here rather than twice: the block is optional, and either count can be
     * absent or a non-scalar, in which case `?` is the honest rendering.
     *
     * @param  array<int|string, mixed>  $payload  The decoded options-endpoint payload
     * @return array{by_shape: string, by_name: string} The two counts, rendered as strings
     */
    private function deniedCounts(
        array $payload
    ): array {
        $denied = $payload['denied'] ?? null;

        $read = static function (string $key) use ($denied): string {
            $value = \is_array($denied) ? ($denied[$key] ?? null) : null;

            return \is_scalar($value) ? (string) $value : '?';
        };

        return [
            'by_shape' => $read('by_shape'),
            'by_name' => $read('by_name'),
        ];
    }

    /**
     * Read file content from the filesystem.
     *
     * @param  string  $filePath  The path to the file
     * @param  OutputInterface  $output  Console output interface
     * @return ?string The file content or null if reading fails
     */
    protected function readFileContent(
        string $filePath,
        OutputInterface $output
    ): ?string {
        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false) {
            $output->writeln("<error>Failed to read file: {$filePath}</error>");
            Log::error("Loader::readFileContent: Failed to read file {$filePath}");

            return null;
        }

        return $jsonContent;
    }

    /**
     * Parse JSON content into a PHP array.
     *
     * @param  string  $jsonContent  The JSON content to parse
     * @param  string  $filePath  The file path for error reporting
     * @param  OutputInterface  $output  Console output interface
     * @return array<int|string, mixed>|null The parsed array or null if parsing fails
     */
    protected function parseJsonContent(
        string $jsonContent,
        string $filePath,
        OutputInterface $output
    ): ?array {
        $settings = \json_decode(
            $jsonContent,
            true
        );
        if (json_last_error() !== JSON_ERROR_NONE) {
            $output->writeln(\sprintf('<error>Invalid JSON format: %s</error>', json_last_error_msg()));
            Log::error("Loader::parseJsonContent: Invalid JSON format in {$filePath}: ".json_last_error_msg());

            return null;
        }

        if (! \is_array($settings)) {
            $output->writeln('<error>Invalid JSON format: expected an object or array.</error>');
            Log::error("Loader::parseJsonContent: JSON root is not an array in {$filePath}");

            return null;
        }

        return $settings;
    }
}
