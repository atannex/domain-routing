<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

/** @internal */
final class HostNormalizer
{
    /** "HTTPS://Acme.Example.com:8443/" → "acme.example.com"; IDN → punycode. */
    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = explode('/', $host, 2)[0];
        $host = explode('?', $host, 2)[0];
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $host = rtrim($host, '.');

        if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            $host = $ascii === false ? $host : strtolower($ascii);
        }

        return $host;
    }

    /** The port written on a host ("127.0.0.1:8001" → 8001), or null when it names none. */
    public static function port(string $host): ?int
    {
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($host));
        $host = explode('/', $host, 2)[0];

        return preg_match('/:(\d{1,5})$/', $host, $m) === 1 ? (int) $m[1] : null;
    }

    /** One or more DNS labels (a-z, 0-9, inner hyphens), 253 chars at most. IPv4 passes; IPv6 literals do not. */
    public static function isValid(string $host): bool
    {
        return $host !== '' && strlen($host) <= 253
            && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;
    }
}
