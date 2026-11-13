<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single resolver for the client IP and the TRUSTED_PROXIES policy, shared by
 * the rate limiters and TrustedProxyMiddleware.
 *
 * - TRUSTED_PROXIES entries may be exact IPs or IPv4/IPv6 CIDR ranges (the
 *   format the Docker docs prescribe); '*' is honoured only in development.
 * - The value is read from the real process env first, then from $_ENV /
 *   $_SERVER, because the .env loader (Dotenv::createImmutable) does not
 *   putenv() — a value set only in .env was previously invisible.
 * - Behind a trusted proxy the client is the RIGHTMOST X-Forwarded-For hop
 *   that is not itself a trusted proxy. The leftmost hop and provider headers
 *   such as CF-Connecting-IP are client-controlled whenever the proxy appends
 *   or passes them through, so they are never trusted.
 */
final class ClientIp
{
    public static function env(string $name): string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    /** @return list<string> */
    public static function trustedEntries(): array
    {
        $raw = self::env('TRUSTED_PROXIES');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $e): bool => $e !== ''));
    }

    public static function isTrustedProxy(string $ip): bool
    {
        $entries = self::trustedEntries();
        if ($entries === []) {
            return false;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '*') {
                if (self::env('APP_ENV') === 'development') {
                    return true;
                }
                continue;
            }
            if (self::matches($bin, $entry)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $serverParams */
    public static function resolve(array $serverParams): string
    {
        $remote = (string) ($serverParams['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return 'unknown';
        }
        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = (string) ($serverParams['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            // X-Real-IP is opt-in (TRUST_X_REAL_IP=1): a proxy that neither
            // sends X-Forwarded-For nor OVERWRITES X-Real-IP would pass a
            // client-chosen value through. Only for proxies configured with
            // e.g. nginx `proxy_set_header X-Real-IP $remote_addr`.
            $realIp = trim((string) ($serverParams['HTTP_X_REAL_IP'] ?? ''));
            if (self::env('TRUST_X_REAL_IP') === '1'
                && $realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false && !self::isTrustedProxy($realIp)) {
                return $realIp;
            }
            return $remote;
        }
        $hops = array_map(trim(...), explode(',', $forwarded));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = $hops[$i];
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break; // malformed chain: stop at the last trustworthy point
            }
            if (!self::isTrustedProxy($hop)) {
                return $hop;
            }
        }
        return $remote;
    }

    private static function matches(string $bin, string $entry): bool
    {
        $parts = explode('/', $entry, 2);
        $net = @inet_pton($parts[0]);
        if ($net === false || strlen($net) !== strlen($bin)) {
            return false;
        }
        $max = strlen($net) * 8;
        if (!isset($parts[1])) {
            return $net === $bin;
        }
        if (!ctype_digit($parts[1]) || (int) $parts[1] > $max) {
            return false; // malformed prefix never widens trust
        }
        $bits = (int) $parts[1];
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return ((ord($bin[$bytes]) ^ ord($net[$bytes])) & $mask) === 0;
    }
}
