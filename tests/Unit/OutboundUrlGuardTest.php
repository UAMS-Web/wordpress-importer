<?php

declare(strict_types=1);

/**
 * Unit tests for the importer's SSRF egress guard.
 *
 * Covers the deterministic paths: scheme allowlist and IP-literal host
 * classification (loopback, link-local, RFC1918, and the IPv6 equivalents),
 * plus public addresses. Uses IP literals so the suite stays offline.
 *
 * @command  php vendor/bin/pest tests/Unit/OutboundUrlGuardTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\OutboundUrlGuard;

afterEach(function (): void {
    // Restore the system resolver, or an injected fake leaks into every later
    // test. Declared here rather than mid-file so it is visibly file-wide.
    OutboundUrlGuard::resolveUsing(null);
});

it('blocks internal, reserved, and non-http(s) URLs', function (string $url): void {
    expect(OutboundUrlGuard::isAllowedUrl($url))->toBeFalse();
})->with([
    'loopback IPv4' => ['http://127.0.0.1/wp-content/uploads/x.jpg'],
    'loopback IPv4 with port' => ['http://127.0.0.1:6379/'],
    'cloud metadata link-local' => ['http://169.254.169.254/latest/meta-data/iam/security-credentials/role'],
    'RFC1918 10.0.0.0/8' => ['http://10.0.0.5/internal.pdf'],
    'RFC1918 172.16.0.0/12 over https' => ['https://172.16.4.2/admin'],
    'RFC1918 192.168.0.0/16' => ['http://192.168.1.1/'],
    'IPv6 loopback literal' => ['http://[::1]/'],
    'unspecified 0.0.0.0' => ['http://0.0.0.0/'],
    'file scheme' => ['file:///etc/passwd'],
    'gopher scheme to loopback' => ['gopher://127.0.0.1:6379/'],
    'relative path (no scheme)' => ['/relative/path.jpg'],
    'empty string' => [''],
]);

it('blocks alternative numeric encodings of an internal address', function (string $url): void {
    // `inet_aton()` — which cURL and the C resolver both reach — accepts far more
    // than dotted-quad, so every one of these connects to a private address
    // while `filter_var(FILTER_VALIDATE_IP)` calls it malformed and the DNS
    // path finds nothing to resolve (wordpress-importer-archive#534).
    expect(OutboundUrlGuard::isAllowedUrl($url))->toBeFalse();
})->with([
    'decimal loopback' => ['http://2130706433/'],
    'decimal loopback with port' => ['http://2130706433:6379/'],
    'hex loopback' => ['http://0x7f000001/'],
    'octal loopback' => ['http://017700000001/'],
    'mixed octal dotted' => ['http://0177.0.0.1/'],
    'two-part short form' => ['http://127.1/'],
    'three-part short form' => ['http://127.0.1/'],
    'decimal cloud metadata' => ['http://2852039166/latest/meta-data/'],
    'hex cloud metadata' => ['http://0xa9fea9fe/'],
    'decimal RFC1918 192.168.1.1' => ['http://3232235777/admin'],
    'decimal RFC1918 10.0.0.1' => ['http://167772161/'],
]);

it('still allows a numeric encoding of a public address', function (): void {
    // The guard canonicalizes numeric hosts rather than rejecting them wholesale,
    // so a public address stays reachable however it is spelled
    expect(OutboundUrlGuard::isAllowedUrl('http://1568963618/'))->toBeTrue();
});

it('leaves genuine DNS names to the resolver', function (string $host): void {
    // A registrable domain cannot be all digits and dots, so numeric
    // canonicalization must not capture these. Asserting the resolver was
    // *asked* is both stronger than asserting the result and offline: the
    // earlier version called the live system resolver for every dataset.
    $asked = [];

    OutboundUrlGuard::resolveUsing(static function (string $queried) use (&$asked): array {
        $asked[] = $queried;

        return [];
    });

    expect(OutboundUrlGuard::isAllowedHost($host))->toBeTrue();

    // Once per address family, with the host passed through untouched
    expect($asked)->toBe([$host, $host]);
})->with([
    'ordinary domain' => ['example.com'],
    'subdomain' => ['cdn.example.org'],
    'hex-letter labels' => ['abc.def'],
    'digit-leading label' => ['123.example.com'],
]);

it('allows public http(s) URLs', function (string $url): void {
    expect(OutboundUrlGuard::isAllowedUrl($url))->toBeTrue();
})->with([
    'public IPv4' => ['http://93.184.216.34/wp-content/uploads/photo.jpg'],
    'public IPv4 over https' => ['https://8.8.8.8/file.pdf'],
    'public IPv6 literal' => ['http://[2606:2800:220:1:248:1893:25c8:1946]/img.png'],
]);

it('allows an unresolvable host since it cannot connect', function (): void {
    // Answering nothing from both families is what an NXDOMAIN looks like to
    // the guard; the earlier version reached the live resolver to produce it
    OutboundUrlGuard::resolveUsing(static fn (): array => []);

    expect(OutboundUrlGuard::isAllowedUrl('http://nonexistent.invalid./image.jpg'))->toBeTrue();
});

//
// Resolution — exercised through the injected record lookup (wordpress-importer-archive#547), so the
// suite asserts what the guard does with A and AAAA answers without ever
// performing a DNS query.
//

/**
 * Installs a record lookup answering from a fixed table.
 *
 * @param  array<string, array<int, list<string>>>  $answers  Host => DNS type => addresses
 */
function fakeResolver(array $answers): void
{
    OutboundUrlGuard::resolveUsing(
        static fn (string $host, int $type): array => $answers[$host][$type] ?? []
    );
}

it('refuses a host that publishes only a private AAAA record', function (string $address): void {
    // gethostbynamel() is IPv4-only, so before the AAAA lookup this host
    // resolved to nothing — and an unresolvable host is allowed, which let it
    // through the guard entirely (wordpress-importer-archive#538).
    fakeResolver([
        'ipv6-only.example' => [
            DNS_A => [],
            DNS_AAAA => [$address],
        ],
    ]);

    expect(OutboundUrlGuard::isAllowedUrl('http://ipv6-only.example/photo.jpg'))->toBeFalse();
})->with([
    'IPv6 loopback' => ['::1'],
    'unique-local fd00::/8' => ['fd00::1'],
    'unique-local fc00::/7' => ['fc00::abcd'],
    'IPv6 link-local' => ['fe80::1'],
]);

it('allows a host that publishes only a public AAAA record', function (): void {
    fakeResolver([
        'ipv6-only.example' => [
            DNS_A => [],
            DNS_AAAA => ['2606:2800:220:1:248:1893:25c8:1946'],
        ],
    ]);

    expect(OutboundUrlGuard::isAllowedUrl('http://ipv6-only.example/photo.jpg'))->toBeTrue();
});

it('unions A and AAAA answers rather than letting either shadow the other', function (
    string $a,
    string $aaaa,
    bool $allowed
): void {
    // A dual-stack host is only safe when *every* address it publishes is
    // public: one private record in either family is enough to refuse it.
    // Records arrive comma-separated so each family stays a list of strings.
    fakeResolver([
        'dual-stack.example' => [
            DNS_A => $a === '' ? [] : explode(',', $a),
            DNS_AAAA => $aaaa === '' ? [] : explode(',', $aaaa),
        ],
    ]);

    expect(OutboundUrlGuard::isAllowedUrl('http://dual-stack.example/x.jpg'))->toBe($allowed);
})->with([
    'both public' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946', true],
    'public A, private AAAA' => ['93.184.216.34', '::1', false],
    'private A, public AAAA' => ['127.0.0.1', '2606:2800:220:1:248:1893:25c8:1946', false],
    'both private' => ['10.0.0.1', 'fd00::1', false],
    'second A record private' => ['93.184.216.34,169.254.169.254', '', false],
    'second AAAA record private' => ['', '2606:2800:220:1:248:1893:25c8:1946,fe80::1', false],
]);

it('allows a host neither family resolves, preserving the deliberate open default', function (): void {
    // Recorded rather than assumed (wordpress-importer-archive#547): an unresolvable name is no SSRF
    // vector, and refusing would turn a transient DNS failure mid-import into a
    // permanently skipped asset.
    fakeResolver([
        'nowhere.example' => [
            DNS_A => [],
            DNS_AAAA => [],
        ],
    ]);

    expect(OutboundUrlGuard::isAllowedUrl('http://nowhere.example/x.jpg'))->toBeTrue();
});

//
// Ranges filter_var() calls public (wordpress-importer-archive#541) — refused from an explicit table on
// top of the FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE pair.
//

it('refuses the ranges filter_var classifies as public', function (string $url): void {
    expect(OutboundUrlGuard::isAllowedUrl($url))->toBeFalse();
})->with([
    'CGNAT / Tailscale 100.64.0.0/10' => ['http://100.64.0.1/'],
    'benchmark 198.18.0.0/15' => ['http://198.18.0.1/'],
    'multicast 224.0.0.0/4' => ['http://224.0.0.1/'],
    'NAT64 64:ff9b::/96 mapping 127.0.0.1' => ['http://[64:ff9b::7f00:1]/'],
    'NAT64 local-use 64:ff9b:1::/48 mapping 127.0.0.1' => ['http://[64:ff9b:1::7f00:1]/'],
    'NAT64 local-use 64:ff9b:1::/48 mapping 10.0.0.5' => ['http://[64:ff9b:1::a00:5]/'],
    '6to4 2002::/16 embedding 127.0.0.1' => ['http://[2002:7f00:1::]/'],
    'IPv6 site-local fec0::/10' => ['http://[fec0::1]/'],
    'IPv4-compatible ::/96 mapping 127.0.0.1' => ['http://[::7f00:1]/'],
    'IPv4-compatible ::/96 mapping cloud metadata' => ['http://[::a9fe:a9fe]/'],
    'IPv6 multicast ff00::/8' => ['http://[ff02::1]/'],
]);

it('refuses an address the same whichever notation spells it', function (
    string $hex,
    string $dotted
): void {
    // `filter_var()` decides on notation rather than value: it refuses
    // `::127.0.0.1` and calls the byte-identical `::7f00:1` public. Without an
    // explicit `::/96` entry an attacker simply picks the spelling that passes.
    expect(inet_pton($hex))->toBe(inet_pton($dotted));

    expect(OutboundUrlGuard::isAllowedHost($hex))->toBeFalse();
    expect(OutboundUrlGuard::isAllowedHost($dotted))->toBeFalse();
})->with([
    'loopback' => ['::7f00:1', '::127.0.0.1'],
    'cloud metadata' => ['::a9fe:a9fe', '::169.254.169.254'],
]);

it('refuses every address inside a listed range, up to its edges', function (string $ip): void {
    expect(OutboundUrlGuard::isAllowedHost($ip))->toBeFalse();
})->with([
    '100.64.0.0 — first' => ['100.64.0.0'],
    '100.127.255.255 — last' => ['100.127.255.255'],
    '198.18.0.0 — first' => ['198.18.0.0'],
    '198.19.255.255 — last' => ['198.19.255.255'],
    '224.0.0.0 — first' => ['224.0.0.0'],
    '239.255.255.255 — last' => ['239.255.255.255'],
    '64:ff9b:: — first' => ['64:ff9b::'],
    '64:ff9b::ffff:ffff — last' => ['64:ff9b::ffff:ffff'],
    '64:ff9b:1:: is the first of the local-use /48' => ['64:ff9b:1::'],
    '64:ff9b:1:ffff:… is the last of the local-use /48' => ['64:ff9b:1:ffff:ffff:ffff:ffff:ffff'],
    '2002:: — first' => ['2002::'],
    '2002:ffff:ffff:ffff:ffff:ffff:ffff:ffff — last' => ['2002:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    'fec0:: — first' => ['fec0::'],
    'feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff — last' => ['feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    ':: — first of the IPv4-compatible /96' => ['::'],
    '::ffff:ffff — last of the IPv4-compatible /96' => ['::ffff:ffff'],
    'ff00:: — first multicast' => ['ff00::'],
    'ffff:ffff:… — last multicast' => ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
]);

it('still allows the addresses immediately outside each listed range', function (string $ip): void {
    // The neighbours are where an off-by-one in the prefix mask shows up: a
    // range refused one bit too wide silently blocks legitimate public hosts.
    expect(OutboundUrlGuard::isAllowedHost($ip))->toBeTrue();
})->with([
    '100.63.255.255 — just below' => ['100.63.255.255'],
    '100.128.0.0 — just above' => ['100.128.0.0'],
    '198.17.255.255 — just below' => ['198.17.255.255'],
    '198.20.0.0 — just above' => ['198.20.0.0'],
    '223.255.255.255 — just below multicast' => ['223.255.255.255'],
    '64:ff9a:ffff:… — just below NAT64' => ['64:ff9a:ffff:ffff:ffff:ffff:ffff:ffff'],
    '64:ff9b:0:1:: — outside the /96' => ['64:ff9b:0:1::'],
    '64:ff9b:0:ffff:… is just below the local-use /48' => ['64:ff9b:0:ffff:ffff:ffff:ffff:ffff'],
    '64:ff9b:2:: is just above the local-use /48' => ['64:ff9b:2::'],
    '2001:ffff:… — just below 6to4' => ['2001:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    '2003:: — just above 6to4' => ['2003::'],
    '::1:0:0 — just above the IPv4-compatible /96' => ['::1:0:0'],
]);

it('refuses a host that resolves into a listed range', function (
    string $address,
    bool $isIpv6
): void {
    // The table has to apply to resolved addresses, not only to literals — a
    // hostname is the shape an attacker-authored WXR actually carries, and the
    // AC covers both families.
    fakeResolver([
        'ranged.example' => [
            DNS_A => $isIpv6 ? [] : [$address],
            DNS_AAAA => $isIpv6 ? [$address] : [],
        ],
    ]);

    expect(OutboundUrlGuard::isAllowedUrl('http://ranged.example/x.jpg'))->toBeFalse();
})->with([
    'CGNAT' => ['100.64.0.1', false],
    'benchmark' => ['198.18.0.1', false],
    'IPv4 multicast' => ['224.0.0.1', false],
    'NAT64' => ['64:ff9b::7f00:1', true],
    'NAT64 local-use' => ['64:ff9b:1::7f00:1', true],
    '6to4' => ['2002:7f00:1::', true],
    'IPv4-compatible' => ['::7f00:1', true],
    'IPv6 site-local' => ['fec0::1', true],
    'IPv6 multicast' => ['ff02::1', true],
]);

//
// Pinning the validated address for the connection (wordpress-importer-archive#541).
//

it('pins a resolved host to the addresses it validated', function (): void {
    fakeResolver([
        'cdn.example' => [
            DNS_A => ['93.184.216.34'],
            DNS_AAAA => ['2606:2800:220:1:248:1893:25c8:1946'],
        ],
    ]);

    expect(OutboundUrlGuard::pinnedResolutionFor('http://cdn.example/photo.jpg'))
        ->toBe(['cdn.example:80:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]']);
});

it('pins against the port curl will actually connect to', function (
    string $url,
    string $expected
): void {
    // curl matches a CURLOPT_RESOLVE entry on host *and* port, so an implicit
    // port has to become the scheme's default or the pin silently does nothing.
    fakeResolver([
        'cdn.example' => [
            DNS_A => ['93.184.216.34'],
            DNS_AAAA => [],
        ],
    ]);

    expect(OutboundUrlGuard::pinnedResolutionFor($url))->toBe([$expected]);
})->with([
    'implicit http' => ['http://cdn.example/x', 'cdn.example:80:93.184.216.34'],
    'implicit https' => ['https://cdn.example/x', 'cdn.example:443:93.184.216.34'],
    'explicit port' => ['http://cdn.example:8080/x', 'cdn.example:8080:93.184.216.34'],
]);

it('has nothing to pin when no name lookup will happen', function (string $url): void {
    // A literal or an inet_aton-style numeric host is resolved by nobody, so
    // there is no second lookup that could disagree with the first
    expect(OutboundUrlGuard::pinnedResolutionFor($url))->toBe([]);
})->with([
    'IPv4 literal' => ['http://93.184.216.34/x.jpg'],
    'IPv6 literal' => ['http://[2606:2800:220:1:248:1893:25c8:1946]/x.jpg'],
    'decimal numeric host' => ['http://1568963618/'],
]);

it('has nothing to pin for a host that resolves to nothing', function (): void {
    fakeResolver([
        'nowhere.example' => [
            DNS_A => [],
            DNS_AAAA => [],
        ],
    ]);

    expect(OutboundUrlGuard::pinnedResolutionFor('http://nowhere.example/x.jpg'))->toBe([]);
});

it('returns no pin at all for a URL it refuses', function (string $url): void {
    fakeResolver([
        'internal.example' => [
            DNS_A => ['127.0.0.1'],
            DNS_AAAA => [],
        ],
    ]);

    expect(OutboundUrlGuard::pinnedResolutionFor($url))->toBeNull();
})->with([
    'resolves to loopback' => ['http://internal.example/x.jpg'],
    'loopback literal' => ['http://127.0.0.1/'],
    'non-http scheme' => ['file:///etc/passwd'],
    'no host' => ['/relative/path'],
]);

it('never consults the resolver for a host that is already an address', function (
    string $url,
    bool $allowed
): void {
    // IP literals and inet_aton-style numeric hosts short-circuit above the
    // lookup, so injecting a resolver must not change how they classify
    $calls = 0;

    OutboundUrlGuard::resolveUsing(static function () use (&$calls): array {
        $calls++;

        return ['93.184.216.34'];
    });

    expect(OutboundUrlGuard::isAllowedUrl($url))->toBe($allowed);
    expect($calls)->toBe(0);
})->with([
    'loopback literal' => ['http://127.0.0.1/', false],
    'public literal' => ['http://93.184.216.34/', true],
    'IPv6 loopback literal' => ['http://[::1]/', false],
    'decimal loopback' => ['http://2130706433/', false],
]);
