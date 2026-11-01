<?php

declare(strict_types=1);

/**
 * Tests that `SiteResolver` names the site an import writes to: the site chosen
 * for the run first, then the entry's own site, then Statamic's current site
 * (#1314). Routed to the light Statamic band for the `Site` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/SiteResolverTest.php
 */

use Statamic\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Sites\Site as StatamicSite;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    SiteResolver::clearSelectedSite();
});

afterEach(function (): void {
    SiteResolver::clearSelectedSite();
});

/**
 * An entry carrying the given `site` value, or none.
 *
 * @param  mixed  $site  The value, or 'absent' for no `site` key
 * @return Entry The entry
 */
function siteResolverEntry(mixed $site): Entry
{
    return new class($site) extends Entry
    {
        /**
         * @param  mixed  $site  The value, or 'absent' for no `site` key
         */
        public function __construct(private readonly mixed $site)
        {
            parent::__construct();
        }

        /**
         * Whether the entry carries the key.
         *
         * @param  mixed  $key  The field handle
         * @return bool Whether it is present
         */
        public function has($key)
        {
            return $key === 'site' && $this->site !== 'absent';
        }

        /**
         * The configured `site` value.
         *
         * @param  mixed  $key  The field handle
         * @param  mixed  $fallback  Unused
         * @return mixed The value, or null for any other key
         */
        public function get($key, $fallback = null)
        {
            return $key === 'site' ? $this->site : null;
        }
    };
}

it('returns the site chosen for the run before anything else', function (): void {
    SiteResolver::setSelectedSite('uamshealth');

    expect(SiteResolver::getCurrentSite(siteResolverEntry('other')))->toBe('uamshealth')
        ->and(SiteResolver::getCurrentSite())->toBe('uamshealth');
});

it('ignores an empty chosen site', function (): void {
    SiteResolver::setSelectedSite('');

    expect(SiteResolver::getCurrentSite(siteResolverEntry('news')))->toBe('news');
});

it('returns the entry site, or default when the entry site is not a string', function (mixed $site, string $expected): void {
    expect(SiteResolver::getCurrentSite(siteResolverEntry($site)))->toBe($expected);
})->with([
    'a site handle' => ['news', 'news'],
    'a non-string value' => [['news'], 'default'],
    'null' => [null, 'default'],
]);

it('returns default for a non-string entry site rather than the current site', function (): void {
    Site::shouldReceive('current')->andReturn(new StatamicSite('current_site', ['url' => '/', 'locale' => 'en_US']));

    expect(SiteResolver::getCurrentSite(siteResolverEntry(['news'])))->toBe('default');
});

it('falls back to the current site when no site is chosen and the entry has none', function (): void {
    Site::shouldReceive('current')->andReturn(new StatamicSite('current_site', ['url' => '/', 'locale' => 'en_US']));

    expect(SiteResolver::getCurrentSite(siteResolverEntry('absent')))->toBe('current_site')
        ->and(SiteResolver::getCurrentSite())->toBe('current_site');
});

it('returns default when there is no current site either', function (): void {
    Site::shouldReceive('current')->andReturn(null);

    expect(SiteResolver::getCurrentSite())->toBe('default');
});

it('stops returning the chosen site once it is cleared', function (): void {
    Site::shouldReceive('current')->andReturn(null);
    SiteResolver::setSelectedSite('uamshealth');

    SiteResolver::clearSelectedSite();

    expect(SiteResolver::getCurrentSite())->toBe('default');
});
