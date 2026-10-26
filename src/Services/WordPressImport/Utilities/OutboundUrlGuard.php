<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Closure;

/**
 * Egress guard for outbound HTTP fetches the importer makes against URLs taken
 * from the WXR file (SSRF defense).
 *
 * Asset URLs (`<wp:attachment_url>`, image `src`, …) in a WXR are fully
 * attacker-controllable: a malicious or third-party-sourced export can point the
 * importer at internal infrastructure (`http://127.0.0.1:…`,
 * `http://169.254.169.254/…` cloud metadata, RFC1918 hosts) and have the
 * response streamed to disk and stored as a fetchable asset. This guard enforces
 * an http/https allowlist and refuses any host that resolves to a loopback,
 * link-local, or private/reserved address.
 */
class OutboundUrlGuard
{
    /**
     * Maximum number of HTTP redirect hops to follow for a guarded outbound
     * fetch.
     *
     * Guzzle's automatic redirect-following is disabled for these fetches and
     * each hop's target is re-checked with {@see self::isAllowedUrl()}; a chain
     * longer than this (or one that loops) is treated as a failed fetch rather
     * than followed indefinitely. Matches Guzzle's historical default limit.
     */
    public const MAX_REDIRECT_HOPS = 5;

    /**
     * IPv4 ranges `filter_var()` classifies as public that this guard refuses
     * anyway.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` covers RFC1918,
     * loopback, link-local, `0.0.0.0/8` and `240.0.0.0/4` — and stops there. It
     * is a fixed flag pair, not an extension point, so anything it misses needs
     * a hand-maintained table rather than another flag (wordpress-importer-archive#541).
     *
     * @var list<string>
     */
    private const array REFUSED_IPV4_RANGES = [
        // Carrier-grade NAT, and the space Tailscale hands out
        '100.64.0.0/10',
        // Benchmark range, routed on some networks
        '198.18.0.0/15',
        // Multicast — never a legitimate asset host
        '224.0.0.0/4',
    ];

    /**
     * IPv6 ranges `filter_var()` classifies as public that this guard refuses
     * anyway.
     *
     * Three kinds matter because they *embed an IPv4 address*: NAT64 maps
     * the whole IPv4 space into `64:ff9b::/96`, so `64:ff9b::7f00:1` reaches
     * `127.0.0.1`, and RFC 8215's local-use prefix `64:ff9b:1::/48` does the
     * same for a site's own translator (wordpress-importer-archive#1351); a 6to4 address carries its IPv4 the same way; and the
     * deprecated IPv4-compatible form is simply `::` followed by the address.
     * All are refused wholesale rather than unwrapped and re-classified — 6to4
     * and IPv4-compatible are deprecated and NAT64 has no business being an
     * asset host, so there is no legitimate traffic to preserve by being
     * cleverer.
     *
     * `::/96` is the one that makes the guard *self-consistent* rather than
     * merely stricter. `filter_var()` decides on notation, not value: it
     * refuses `::127.0.0.1` and calls the byte-identical `::7f00:1` public.
     * Without this entry an attacker picks the spelling that passes.
     *
     * @var list<string>
     */
    private const array REFUSED_IPV6_RANGES = [
        // NAT64 — embeds an IPv4 address, so it reaches the whole IPv4 space
        '64:ff9b::/96',
        // NAT64 local-use (RFC 8215), the same embedding on a site's own translator
        '64:ff9b:1::/48',
        // 6to4 — likewise embeds an IPv4 address
        '2002::/16',
        // Deprecated IPv4-compatible IPv6 — `::7f00:1` *is* `::127.0.0.1`
        '::/96',
        // Deprecated IPv6 site-local, the IPv6 answer to RFC1918
        'fec0::/10',
        // Multicast, for the same reason `224.0.0.0/4` is refused above
        'ff00::/8',
    ];

    /**
     * A replacement DNS record lookup, or null to use the system resolver.
     *
     * The guard's classification of an IP literal is easy to test offline; its
     * *resolution* of a hostname is not, because asserting it would need a live
     * DNS lookup and this suite is deliberately network-free. That left the
     * AAAA path — added because `gethostbynamel()` is IPv4-only, so a host
     * publishing only AAAA records resolved to nothing and an unresolvable host
     * is allowed — correct but uncovered: the `dns_get_record()` call could be
     * deleted and every test would still pass (wordpress-importer-archive#547).
     *
     * This seam closes that. Injecting a lookup replaces only the two raw
     * record queries; the union, the IP-literal short-circuit and the numeric
     * canonicalization above them all still run, so a faked lookup exercises
     * the real resolution logic rather than bypassing it.
     *
     * @var ?Closure(string, int): list<string>
     */
    private static ?Closure $recordLookup = null;

    /**
     * Replaces the DNS record lookup, or restores the system resolver when
     * given null.
     *
     * Intended for tests, which must not touch the network. Production code
     * never calls this, so the default path is exactly what it was before the
     * seam existed.
     *
     * @param  ?Closure(string, int): list<string>  $recordLookup  Called with the host and either `DNS_A` or `DNS_AAAA`, returning the addresses found
     */
    public static function resolveUsing(?Closure $recordLookup): void
    {
        self::$recordLookup = $recordLookup;
    }

    /**
     * Whether an outbound fetch to this URL is permitted.
     *
     * Returns false for a non-http(s) scheme, a missing host, or a host that
     * resolves to a non-public address.
     *
     * @param  string  $url  The URL to test
     */
    public static function isAllowedUrl(string $url): bool
    {
        return self::pinnedResolutionFor($url) !== null;
    }

    /**
     * Validates a URL and returns the `CURLOPT_RESOLVE` entries that pin it to
     * the very addresses just validated, or null when the URL is refused.
     *
     * This is the guard's answer to DNS rebinding. Validating and connecting
     * are two separate name lookups, so a name whose answer changes between
     * them passes the check and connects somewhere else. Handing the caller the
     * resolved addresses — rather than making it ask a second time — is what
     * makes "the address the guard validated" and "the address the request
     * connects to" the same address (wordpress-importer-archive#541).
     *
     * An empty list means there is nothing to pin, which happens in exactly two
     * harmless cases: the host is already an address (a literal, or an
     * inet_aton-style numeric), so no name lookup happens at connect time
     * either; or it resolved to nothing, which {@see self::isAllowedHost()}
     * deliberately allows.
     *
     * @param  string  $url  The URL to validate
     * @return ?list<string> `host:port:address` entries, empty when there is nothing to pin; null when refused
     */
    public static function pinnedResolutionFor(string $url): ?array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! \in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! \is_string($host) || $host === '') {
            return null;
        }

        // An IPv6 literal arrives bracketed from parse_url()
        $bareHost = trim($host, '[]');

        $addresses = self::resolveHostToIps($bareHost);

        // One non-public address anywhere in the answer refuses the whole host
        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return null;
            }
        }

        // Nothing resolved, so there is no address to pin to
        if ($addresses === []) {
            return [];
        }

        // A host that is already an address performs no name lookup at connect
        // time, so there is no second resolution to disagree with the first
        if (
            filter_var($bareHost, FILTER_VALIDATE_IP) !== false
            || self::canonicalizeNumericIpv4($bareHost) !== null
        ) {
            return [];
        }

        $port = parse_url($url, PHP_URL_PORT);

        // curl matches the entry on the port it is about to connect to, so an
        // absent port has to become the scheme's default rather than be omitted
        $port = \is_int($port)
            ? $port
            : ($scheme === 'https' ? 443 : 80);

        // curl wants an IPv6 address bracketed inside the entry
        $formatted = array_map(
            static fn (string $address): string => str_contains($address, ':')
                ? '['.$address.']'
                : $address,
            $addresses
        );

        return [$bareHost.':'.$port.':'.implode(',', $formatted)];
    }

    /**
     * Whether a host (IP literal or DNS name) is safe to fetch: it must not
     * resolve to any loopback, link-local, or private/reserved address.
     *
     * A host that does not resolve is allowed. That is a deliberate choice,
     * re-affirmed rather than assumed when the resolver seam went in (wordpress-importer-archive#547):
     * an unresolvable name is no SSRF vector, because the request that follows
     * cannot reach an address the guard did not get to see either. Refusing
     * instead would turn every transient DNS failure during a multi-thousand
     * item import into a permanently skipped asset, which is a real cost paid
     * against no attacker.
     *
     * A name that resolves to a public address here and a private one when the
     * client looks it up again is a separate problem, closed by pinning the
     * validated addresses for the connection — see
     * {@see self::pinnedResolutionFor()}. Pinning does not reach *this* branch,
     * though: a host that resolved to nothing offers no address to pin, so it
     * is allowed and left unpinned. That residual is accepted knowingly (wordpress-importer-archive#541)
     * — the alternative refuses every asset whose host is momentarily
     * unresolvable, which is a certain cost against a narrow attack.
     *
     * @param  string  $host  The host to test
     */
    public static function isAllowedHost(string $host): bool
    {
        $host = trim($host, '[]');

        return array_all(self::resolveHostToIps($host), fn (string $ip): bool => self::isPublicIp($ip));
    }

    /**
     * Whether an IP literal is a public, routable address (not private and not
     * reserved — together covering loopback, link-local, RFC1918, and the IPv6
     * equivalents).
     *
     * @param  string  $ip  The IP literal to test
     */
    private static function isPublicIp(string $ip): bool
    {
        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false
        ) {
            return false;
        }

        // The flags above are a fixed pair, so the ranges they miss are refused
        // from an explicit table instead
        $ranges = str_contains($ip, ':')
            ? self::REFUSED_IPV6_RANGES
            : self::REFUSED_IPV4_RANGES;

        return array_all($ranges, fn (string $range): bool => ! self::isInRange($ip, $range));
    }

    /**
     * Whether an IP literal falls inside a CIDR range.
     *
     * Compares packed binary forms, so one implementation covers IPv4 and IPv6
     * without any 32-bit arithmetic that would misbehave on the wider family.
     * A mismatch in address family is not a match — `inet_pton()` returns 4
     * bytes for IPv4 and 16 for IPv6.
     *
     * @param  string  $ip  The IP literal to test
     * @param  string  $range  The range in CIDR notation
     */
    private static function isInRange(string $ip, string $range): bool
    {
        [$subnet, $prefix] = explode('/', $range);

        $packedIp = @inet_pton($ip);
        $packedSubnet = @inet_pton($subnet);

        // A malformed literal or a cross-family comparison never matches
        if (
            $packedIp === false
            || $packedSubnet === false
            || \strlen($packedIp) !== \strlen($packedSubnet)
        ) {
            return false;
        }

        $prefixBits = (int) $prefix;
        $wholeBytes = intdiv($prefixBits, 8);

        // Every whole byte of the prefix must match exactly
        if (
            $wholeBytes > 0
            && strncmp($packedIp, $packedSubnet, $wholeBytes) !== 0
        ) {
            return false;
        }

        $remainingBits = $prefixBits % 8;

        if ($remainingBits === 0) {
            return true;
        }

        // Compare only the leading bits of the byte the prefix stops inside.
        // A non-zero remainder guarantees this byte exists.
        $mask = \chr((0xFF << (8 - $remainingBits)) & 0xFF);

        return ($packedIp[$wholeBytes] & $mask) === ($packedSubnet[$wholeBytes] & $mask);
    }

    /**
     * Resolve a host to its IP addresses. An IP literal resolves to itself; a
     * DNS name is resolved via both an A-record and an AAAA-record lookup.
     * Returns an empty array when the host does not resolve.
     *
     * Both families are looked up because either alone leaves a bypass: a name
     * publishing only AAAA records pointing at `::1` or an `fd00::/8` address
     * resolves to nothing under an A-only lookup, and an unresolved host is
     * allowed.
     *
     * @param  string  $host  The host to resolve
     * @return list<string>
     */
    private static function resolveHostToIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // A numeric host such as `2130706433`, `0x7f000001` or `127.1` is not a
        // DNS name: the HTTP client parses it with inet_aton semantics and
        // connects to the address it encodes. Canonicalize it here, or the
        // classification below never runs — the name fails to resolve, and an
        // unresolvable host is allowed.
        $numeric = self::canonicalizeNumericIpv4($host);

        if ($numeric !== null) {
            return [$numeric];
        }

        // Union both families rather than letting either shadow the other: a
        // dual-stack name whose A record is public and whose AAAA record is
        // private must be refused on the strength of the AAAA record alone
        return array_merge(
            self::lookUpRecords($host, DNS_A),
            self::lookUpRecords($host, DNS_AAAA)
        );
    }

    /**
     * Looks up one address family for a host, through the injected resolver
     * when a test supplied one and the system resolver otherwise.
     *
     * The two families need different system calls, not one call with a
     * different argument. `gethostbynamel()` goes through the platform's name
     * service, so a hosts-file entry pointing at a loopback address is honored
     * — but it is IPv4-only. `dns_get_record()` reaches AAAA records but
     * queries DNS directly and does not consult the hosts file, so it is the
     * wrong tool for the A lookup and cannot replace it.
     *
     * @param  string  $host  The host to resolve
     * @param  int  $type  Either `DNS_A` or `DNS_AAAA`
     * @return list<string>
     */
    private static function lookUpRecords(string $host, int $type): array
    {
        if (self::$recordLookup instanceof Closure) {
            return (self::$recordLookup)($host, $type);
        }

        // A records go through the system resolver so /etc/hosts is honored
        if ($type === DNS_A) {
            $ips = @gethostbynamel($host);

            return $ips === false ? [] : $ips;
        }

        // AAAA records need a direct DNS query — gethostbynamel() is IPv4-only
        $records = @dns_get_record($host, DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            $ipv6 = $record['ipv6'] ?? null;

            if (\is_string($ipv6) && $ipv6 !== '') {
                $ips[] = $ipv6;
            }
        }

        return $ips;
    }

    /**
     * Canonicalize an inet_aton-style numeric IPv4 host to dotted-quad form.
     *
     * `inet_aton()` — which cURL, Guzzle, and the C resolver all reach — accepts
     * far more than dotted-quad: one to four dot-separated parts, each decimal,
     * `0`-prefixed octal, or `0x`-prefixed hexadecimal, with the final part
     * absorbing every byte the leading parts did not. So `http://2130706433/`,
     * `http://0x7f000001/`, `http://017700000001/` and `http://127.1/` all
     * connect to `127.0.0.1`, while `filter_var(FILTER_VALIDATE_IP)` rejects
     * every one of them as malformed.
     *
     * Returning null leaves a genuine DNS name to the resolver untouched: a
     * registrable domain cannot consist only of digits and dots, so there is no
     * real host this misclassifies.
     *
     * @param  string  $host  The host to canonicalize
     * @return ?string The dotted-quad equivalent, or null if the host is not numeric
     */
    private static function canonicalizeNumericIpv4(string $host): ?string
    {
        // Every part must be a decimal, octal, or hexadecimal number; anything
        // else is a DNS name and falls through
        if (
            \preg_match(
                '/^(?:0[xX][0-9a-fA-F]+|[0-9]+)(?:\.(?:0[xX][0-9a-fA-F]+|[0-9]+))*$/',
                $host
            ) !== 1
        ) {
            return null;
        }

        $parts = \explode('.', $host);

        if (\count($parts) > 4) {
            return null;
        }

        $values = [];

        // Read each part in its own base, widest-first so `0x…` wins over `0…`
        foreach ($parts as $part) {
            if (\preg_match('/^0[xX][0-9a-fA-F]+$/', $part) === 1) {
                $values[] = (float) \hexdec(\substr($part, 2));

                continue;
            }

            if (\preg_match('/^0[0-7]+$/', $part) === 1) {
                $values[] = (float) \octdec(\substr($part, 1));

                continue;
            }

            $values[] = (float) $part;
        }

        // The final part absorbs every byte the leading parts did not. The
        // pattern above guarantees at least one part, so this is never null.
        $last = \array_pop($values);

        $address = 0.0;

        // Each leading part contributes exactly one byte
        foreach ($values as $index => $value) {
            if ($value > 255.0) {
                return null;
            }

            $address += $value * (256.0 ** (3 - $index));
        }

        // Reject a final part too wide for the bytes left to it
        if ($last > (256.0 ** (4 - \count($values))) - 1.0) {
            return null;
        }

        return long2ip((int) ($address + $last));
    }
}
