<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;
use Statamic\Facades\Site;
use Statamic\Yaml\Yaml;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;

/**
 * Handles the input and validation of a Statamic site handle for WordPress import.
 *
 * This class is responsible for prompting the user to enter a site handle
 * and validating that the site exists in Statamic's configured sites or
 * the fallback configuration file.
 */
class SiteSelector
{
    /**
     * Include the HasPromptsSupport trait to provide platform detection for Laravel
     * Prompts support.
     */
    use HasPromptsSupport;

    /**
     * Include the HasQuestionHelper trait to provide centralized question handling.
     */
    use HasQuestionHelper;

    /**
     * Prompt the user to enter a Statamic site handle for the import.
     *
     * Displays a formatted list of available sites and asks the user to type in
     * the Statamic site handle, validating that the site exists in Statamic's
     * configured sites or resources/sites.yaml.
     *
     * @param  InputInterface  $input  Console input interface for user interaction
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     * @return string The entered site handle
     */
    public function select(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): string {
        // Display available sites before prompting
        $this->displayAvailableSites(
            $output,
            $command
        );

        // Get available site handles for validation and default suggestion
        $availableSites = $this->getAvailableSiteHandles();
        $defaultSite = $this->getDefaultSiteHandle();

        // Ask for a site handle and fall back to the default site when needed
        $selectedSite = $this->askWithValidation(
            'Which site would you like to import the content into?',
            function ($answer) use ($availableSites): string {
                $normalizedAnswer = self::extractNonEmptyString($answer);

                if ($normalizedAnswer === null) {
                    throw new RuntimeException('A site handle is required.');
                }

                if (! $this->validate($normalizedAnswer)) {
                    $availableList = $availableSites !== [] ? 'Available sites: '.\implode(
                        ', ',
                        $availableSites
                    ) : 'No sites found in configuration.';
                    throw new RuntimeException(
                        "Site handle '{$normalizedAnswer}' does not exist. {$availableList}"
                    );
                }

                return $normalizedAnswer;
            },
            $input,
            $output,
            $command,
            $defaultSite
        );

        // ---
        // Return the handle the answer resolved to. The validator returns a trimmed handle
        // that is not empty, and a run that is not interactive answers with the default,
        // which is null only when no site is configured at all (#100).
        // ---
        if (\is_string($selectedSite)) {
            return $selectedSite;
        }

        // Throw when no site can be resolved from selection or configuration
        throw new RuntimeException('Unable to resolve a Statamic site handle.');
    }

    /**
     * Display a formatted list of available sites to the user.
     *
     * Shows site handles and names in a formatted table with dots for alignment.
     *
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     */
    private function displayAvailableSites(
        OutputInterface $output,
        Command $command
    ): void {
        $sites = $this->getSitesWithNames();

        if ($sites === []) {
            $command->warn('No sites found in configuration.');

            return;
        }

        $output->writeln('');
        $output->writeln('Available sites in resources/sites.yaml:');
        $output->writeln('');

        // Find the longest site handle to determine padding
        $maxHandleLength = max(
            \array_map(
                strlen(...),
                \array_keys($sites)
            )
        );
        // Calculate the starting position for site names (longest handle + 5)
        $siteNameStartPosition = $maxHandleLength + 5;

        // Add header row
        $headerDots = str_repeat(
            '.',
            max(
                3,
                $siteNameStartPosition - \strlen('[SITE HANDLE]') - 1
            )
        );
        $output->writeln(
            \sprintf(
                '  [SITE HANDLE] %s [SITE NAME]',
                $headerDots
            )
        );

        // The column starts 5 past the longest handle, so every handle gets 4 dots or more
        foreach ($sites as $handle => $name) {
            $dots = str_repeat(
                '.',
                $siteNameStartPosition - \strlen($handle) - 1
            );
            $output->writeln(
                \sprintf(
                    '  %s %s %s',
                    $handle,
                    $dots,
                    $name
                )
            );
        }

        $output->writeln('');
    }

    /**
     * Get all available sites with their handles and names.
     *
     * @return array<string, string> Array of site handles mapped to site names
     */
    private function getSitesWithNames(): array
    {
        $sites = [];

        // Get sites from Statamic's configured sites
        $statamicSites = $this->getStatamicSitesWithNames();
        $sites = \array_merge(
            $sites,
            $statamicSites
        );

        // Get sites from sites.yaml if it exists
        $sitesConfigPath = resource_path('sites.yaml');
        if (\file_exists($sitesConfigPath)) {
            $yamlSites = $this->getYamlSitesWithNames($sitesConfigPath);
            $sites = \array_merge(
                $sites,
                $yamlSites
            );
        }

        // Sort by handle. Merging by handle already keeps each handle once; two sites may
        // share a name, so nothing is removed by name (#100)
        ksort($sites);

        return $sites;
    }

    /**
     * Get all available site handles from Statamic and sites.yaml.
     *
     * @return array<int, string> Array of available site handles
     */
    private function getAvailableSiteHandles(): array
    {
        return \array_keys($this->getSitesWithNames());
    }

    /**
     * Get the default site handle from Statamic or sites.yaml.
     *
     * @return ?string The default site handle, or null if none found
     */
    private function getDefaultSiteHandle(): ?string
    {
        // Try to get default from Statamic
        $defaultSite = Site::default();
        $defaultSiteHandle = self::extractMethodString(
            $defaultSite,
            'handle'
        );
        if ($defaultSiteHandle !== null) {
            return $defaultSiteHandle;
        }

        // Fall back to first available site handle
        $availableSites = $this->getAvailableSiteHandles();

        return $availableSites !== [] ? $availableSites[0] : null;
    }

    /**
     * Validate if the selected site handle exists in Statamic or sites.yaml.
     *
     * Checks against Statamic's configured sites and the sites.yaml configuration
     * file.
     *
     * @param  string  $siteHandle  The site handle to validate
     * @return bool True if the site exists, false otherwise
     */
    public function validate(
        string $siteHandle
    ): bool {
        return \in_array(
            $siteHandle,
            $this->getAvailableSiteHandles(),
            true
        );
    }

    /**
     * Get the configured Statamic sites with their handles and names.
     *
     * @return array<string, string> Statamic sites keyed by handle
     */
    private function getStatamicSitesWithNames(): array
    {
        $sites = [];
        $statamicSites = Site::all();

        // The facade types all() as mixed and it returns the sites as a collection, so this
        // only narrows the type for phpstan
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $statamicSites instanceof Collection) {
            return $sites;
        }

        foreach ($statamicSites as $rawSite) {
            $handle = self::extractMethodString(
                $rawSite,
                'handle'
            );
            $name = self::extractMethodString(
                $rawSite,
                'name'
            );

            if ($handle === null || $name === null) {
                continue;
            }

            $sites[$handle] = $name;
        }

        return $sites;
    }

    /**
     * Get the configured YAML sites with handles and display names.
     *
     * @param  string  $sitesConfigPath  Absolute path to resources/sites.yaml
     * @return array<string, string> YAML sites keyed by handle
     */
    private function getYamlSitesWithNames(
        string $sitesConfigPath
    ): array {
        $sitesConfig = $this->parseYamlFile($sitesConfigPath);

        // The parse returns an array for every file it can read and throws for one it
        // cannot, so this only narrows the mixed it is typed as, for phpstan
        // @pest-mutate-ignore: RemoveEarlyReturn
        if (! \is_array($sitesConfig)) {
            return [];
        }

        $yamlSites = [];

        foreach ($sitesConfig as $handle => $config) {
            if (! \is_string($handle) || $handle === '') {
                continue;
            }

            $yamlSites[$handle] = self::extractYamlSiteName(
                $config,
                $handle
            );
        }

        return $yamlSites;
    }

    /**
     * Parse a YAML file through Statamic's YAML service.
     *
     * @param  string  $sitesConfigPath  Absolute path to a YAML configuration file
     * @return mixed Parsed YAML value
     */
    private function parseYamlFile(
        string $sitesConfigPath
    ): mixed {
        $yamlService = app(Yaml::class);

        $yamlFile = $yamlService->file($sitesConfigPath);

        // file() returns the Yaml service itself, which parses, so this only narrows the
        // mixed it is typed as, for phpstan
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
        if (! \is_object($yamlFile) || ! \is_callable([$yamlFile, 'parse'])) {
            return null;
        }

        return \call_user_func([$yamlFile, 'parse']);
    }

    /**
     * Extract the configured site name from YAML entry data.
     *
     * @param  mixed  $config  YAML site configuration entry
     * @param  string  $fallbackHandle  Site handle used when no name is configured
     * @return string Site display name
     */
    private static function extractYamlSiteName(
        mixed $config,
        string $fallbackHandle
    ): string {
        // A config that is not a map has no name to read, and reading one from a string
        // gives null and so the fallback too; the check narrows the type for phpstan
        // @pest-mutate-ignore: RemoveEarlyReturn
        if (! \is_array($config)) {
            return $fallbackHandle;
        }

        $configuredName = self::extractNonEmptyString($config['name'] ?? null);

        return $configuredName ?? $fallbackHandle;
    }

    /**
     * Extract a non-empty string value from a callable object method.
     *
     * @param  mixed  $candidate  Potential object containing the method
     * @param  string  $methodName  Method name expected to return a scalar value
     * @return string|null Extracted non-empty string or null when unavailable
     */
    private static function extractMethodString(
        mixed $candidate,
        string $methodName
    ): ?string {
        // Every candidate is a Statamic site, which has both methods, or null when no site
        // is configured, which either form of the check answers with null
        // @pest-mutate-ignore: BooleanOrToBooleanAnd
        if (! \is_object($candidate) || ! \is_callable([$candidate, $methodName])) {
            return null;
        }

        return self::extractNonEmptyString(
            \call_user_func([$candidate, $methodName])
        );
    }

    /**
     * Extract a non-empty trimmed string from a mixed value.
     *
     * @param  mixed  $value  Value to normalize
     * @return string|null Non-empty trimmed string, or null when not available
     */
    private static function extractNonEmptyString(
        mixed $value
    ): ?string {
        if (! \is_scalar($value)) {
            return null;
        }

        $stringValue = \trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }
}
