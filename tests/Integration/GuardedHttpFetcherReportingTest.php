<?php

declare(strict_types=1);

/**
 * Tests the parts of `GuardedHttpFetcher` that decide what a terminal hop means and
 * what an operator reads when a fetch is refused: the status range that counts as
 * success, and the warning each refusal reason logs.
 *
 * `GuardedHttpFetcherTest` covers the egress guard itself. Here the walk is driven
 * with `Http::fake()` against an IP literal, so nothing leaves the process, and each
 * refusal reason's sentence is checked through the private `warn()` directly, since
 * an exhausted time budget cannot be produced quickly end to end.
 *
 * @command  php vendor/bin/pest tests/Integration/GuardedHttpFetcherReportingTest.php
 */

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\GuardedFetchRefusal;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\GuardedHttpFetcher;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

it('counts a terminal status from 200 to 399 as resolved, and nothing outside it', function (int $status, bool $resolves): void {
    // 399 carries no Location, so the walk treats it as the terminal hop
    Http::fake(['*' => Http::response('the body', $status)]);

    expect(GuardedHttpFetcher::resolveFinalUrl('http://93.184.216.34/page/'))
        ->toBe($resolves ? 'http://93.184.216.34/page/' : null)
        ->and(GuardedHttpFetcher::fetchBody('http://93.184.216.34/page/', 'Test Agent'))
        ->toBe($resolves ? 'the body' : null);
})->with([
    'just below success' => [199, false],
    'the first success' => [200, true],
    'the last non-error' => [399, true],
    'the first error' => [400, false],
]);

it('names the reason in the warning it logs for each refusal', function (GuardedFetchRefusal $reason, ?string $detail, string $sentence): void {
    $log = Log::spy();

    (new ReflectionMethod(GuardedHttpFetcher::class, 'warn'))
        ->invoke(null, $reason, 'http://10.0.0.1/', 'http://93.184.216.34/start/', $detail);

    $log->shouldHaveReceived('warning', [
        'GuardedHttpFetcher: '.$sentence,
        ['rejected_url' => 'http://10.0.0.1/', 'original_url' => 'http://93.184.216.34/start/'],
    ]);
})->with([
    'a non-public target' => [GuardedFetchRefusal::NonPublicTarget, null, 'refused a non-public or non-HTTP fetch target'],
    'an exhausted budget' => [GuardedFetchRefusal::BudgetExhausted, null, 'exhausted the 10s fetch budget'],
    'a transport failure' => [GuardedFetchRefusal::TransportFailure, 'connection reset', 'failed to fetch: connection reset'],
    'an unparseable Location' => [GuardedFetchRefusal::UnparseableLocation, 'bad host', 'could not parse the redirect target: bad host'],
    'a redirect loop' => [GuardedFetchRefusal::RedirectLoop, null, 'detected a redirect loop'],
    'too many redirects' => [GuardedFetchRefusal::TooManyRedirects, null, 'exceeded 5 redirects'],
]);

it('logs a refused chain with the entry being imported', function (): void {
    $log = Log::spy();
    Http::fake();
    ProcessingContext::setWordPressPageLink('https://example.edu/a-page/');

    expect(GuardedHttpFetcher::resolveFinalUrl('http://169.254.169.254/latest/'))->toBeNull();

    Http::assertNothingSent();
    $log->shouldHaveReceived('warning', [
        'GuardedHttpFetcher: refused a non-public or non-HTTP fetch target (from entry: https://example.edu/a-page/)',
        [
            'rejected_url' => 'http://169.254.169.254/latest/',
            'original_url' => 'http://169.254.169.254/latest/',
            'entry_url' => 'https://example.edu/a-page/',
        ],
    ]);
});
