<?php

declare(strict_types=1);

/**
 * Tests the parts of `OutboundUrlGuard` that `OutboundUrlGuardTest` reaches only
 * through a yes-or-no answer: the exact address a numeric host canonicalizes to, the
 * range matcher on malformed and cross-family input, the reading of a DNS AAAA answer,
 * and the system A-record lookup.
 *
 * The private helpers are called through reflection, because a wrong canonical
 * address or a wrong range match usually still produces the right yes-or-no for the
 * handful of addresses the URL-level tests use. The system lookup is exercised only
 * for `localhost`, which the hosts file answers without the network.
 *
 * @command  php vendor/bin/pest tests/Unit/OutboundUrlGuardInternalsTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\OutboundUrlGuard;

/**
 * Call one of the guard's private static helpers.
 */
function outboundGuardPrivate(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(OutboundUrlGuard::class, $method))->invoke(null, ...$arguments);
}

afterEach(function (): void {
    OutboundUrlGuard::resolveUsing(null);
});

it('keeps the redirect limit at Guzzle\'s historical default', function (): void {
    expect(OutboundUrlGuard::MAX_REDIRECT_HOPS)->toBe(5);
});

it('reads the scheme case-insensitively', function (): void {
    expect(OutboundUrlGuard::isAllowedUrl('HTTP://93.184.216.34/'))->toBeTrue()
        ->and(OutboundUrlGuard::isAllowedUrl('HttpS://93.184.216.34/'))->toBeTrue();
});

it('refuses a URL with a scheme but no host', function (): void {
    expect(OutboundUrlGuard::isAllowedUrl('http:/path'))->toBeFalse();
});

it('unbrackets an IPv6 host before classifying it', function (): void {
    expect(OutboundUrlGuard::isAllowedHost('[::1]'))->toBeFalse()
        ->and(OutboundUrlGuard::isAllowedHost('[2606:2800:220:1:248:1893:25c8:1946]'))->toBeTrue();
});

it('canonicalizes a numeric host to the address it encodes', function (string $host, ?string $address): void {
    expect(outboundGuardPrivate('canonicalizeNumericIpv4', $host))->toBe($address);
})->with([
    'one decimal part' => ['2130706433', '127.0.0.1'],
    'one hex part' => ['0x7f000001', '127.0.0.1'],
    'an uppercase hex prefix' => ['0X7F000001', '127.0.0.1'],
    'one octal part' => ['017700000001', '127.0.0.1'],
    'hex then decimal parts' => ['0x7f.0.0.1', '127.0.0.1'],
    'octal then decimal parts' => ['0177.0.0.1', '127.0.0.1'],
    'two parts' => ['127.1', '127.0.0.1'],
    'three parts' => ['10.1.2', '10.1.0.2'],
    'four parts' => ['1.2.3.4', '1.2.3.4'],
    'a leading part of 255' => ['255.1', '255.0.0.1'],
    'the widest last part for one leading part' => ['1.16777215', '1.255.255.255'],
    'five parts' => ['1.2.3.4.5', null],
    'five parts ending in zero' => ['1.2.3.4.0', null],
    'a leading part over 255' => ['256.1', null],
    'a last part too wide' => ['1.16777216', null],
    'a DNS name' => ['example.com', null],
]);

it('matches an address against a range by its prefix bits', function (string $ip, string $range, bool $inside): void {
    expect(outboundGuardPrivate('isInRange', $ip, $range))->toBe($inside);
})->with([
    'inside a ten-bit prefix' => ['100.127.255.255', '100.64.0.0/10', true],
    'just past a ten-bit prefix' => ['100.128.0.0', '100.64.0.0/10', false],
    'inside a four-bit prefix' => ['239.0.0.1', '224.0.0.0/4', true],
    'just below a ten-bit prefix' => ['100.63.255.255', '100.64.0.0/10', false],
    'past a nine-bit prefix, inside its whole byte' => ['10.128.0.1', '10.0.0.0/9', false],
    'inside a nine-bit prefix' => ['10.127.0.1', '10.0.0.0/9', true],
    'below a whole-byte prefix' => ['9.255.255.255', '10.0.0.0/8', false],
    'above a whole-byte prefix' => ['11.0.0.0', '10.0.0.0/8', false],
    'a malformed address' => ['not-an-ip', '10.0.0.0/8', false],
    'a malformed range' => ['10.0.0.1', 'not-a-range/8', false],
    'an IPv4 address against an IPv6 range' => ['100.64.0.1', '64:ff9b::/96', false],
]);

it('reads the IPv6 addresses out of an AAAA answer and nothing else', function (mixed $records, array $addresses): void {
    expect(outboundGuardPrivate('ipv6AddressesFrom', $records))->toBe($addresses);
})->with([
    'a failed query' => [false, []],
    'an empty answer' => [[], []],
    'two records' => [[['ipv6' => '2001:db8::1'], ['ipv6' => '2001:db8::2']], ['2001:db8::1', '2001:db8::2']],
    'records missing, empty or not a string' => [
        [['host' => 'x'], ['ipv6' => ''], ['ipv6' => 7], 'not a record', ['ipv6' => '2001:db8::3']],
        ['2001:db8::3'],
    ],
]);

it('answers an A lookup from the system resolver, and nothing for a failed one', function (): void {
    // localhost is answered by the hosts file, and a name over 255 characters is
    // refused before any query, so neither needs the network. The refusal raises a
    // warning the class silences with `@`, which the test runner still records, so
    // a handler takes it here.
    set_error_handler(static fn (): bool => true);

    try {
        $refused = outboundGuardPrivate('lookUpRecords', \str_repeat('a', 256), DNS_A);
    } finally {
        restore_error_handler();
    }

    expect(outboundGuardPrivate('lookUpRecords', 'localhost', DNS_A))->toBe(['127.0.0.1'])
        ->and($refused)->toBe([]);
});
