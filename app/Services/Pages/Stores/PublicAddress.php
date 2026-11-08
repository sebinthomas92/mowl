<?php

namespace App\Services\Pages\Stores;

use App\Services\Pages\StoreException;

/**
 * Store URLs are typed by workspace members, so every request the app makes to one is checked first:
 * public HTTP(S) only, no private or reserved networks, and the connection is pinned to the checked IP.
 */
class PublicAddress
{
    /** @return string|null a CURLOPT_RESOLVE entry, or null when the host is a literal IP or checks are off */
    public static function binding(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new StoreException('Store addresses must be plain http:// or https:// URLs.');
        }
        if (config('pages.allow_private_hosts')) {
            return null;
        }
        if (! in_array($port, [80, 443], true)) {
            throw new StoreException('Store addresses must use the standard web ports.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            throw new StoreException('Local and internal store addresses are not allowed.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if (! $addresses) {
            throw new StoreException("The store address {$host} could not be found.");
        }
        foreach ($addresses as $address) {
            // An IPv4-mapped IPv6 address (::ffff:10.0.0.1) reaches the IPv4 network it names.
            $mapped = preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $match) ? $match[1] : null;
            foreach (array_filter([$address, $mapped]) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    throw new StoreException('Store addresses on private or reserved networks are not allowed.');
                }
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        // Prefer IPv4; curl wants an IPv6 address in brackets.
        $pinned = collect($addresses)->first(fn ($address) => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? '['.$addresses[0].']';

        return "{$host}:{$port}:{$pinned}";
    }

    /**
     * Every A and AAAA address for the host, so a store with only an IPv6 address is reachable and
     * every address it could connect to has been checked.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = array_merge(
            array_column($records, 'ip'),
            array_column($records, 'ipv6'),
            gethostbynamel($host) ?: [],
        );

        return array_values(array_unique(array_filter($addresses, fn ($address) => filter_var($address, FILTER_VALIDATE_IP) !== false)));
    }
}
