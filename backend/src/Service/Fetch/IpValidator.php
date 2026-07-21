<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * Decides whether an IP address is publicly routable. Everything private,
 * loopback, link-local, reserved, or multicast is rejected (SSRF guard).
 */
final class IpValidator
{
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        // ::/96 covers the unspecified address, ::1 loopback, and the
        // deprecated IPv4-compatible format (::127.0.0.1 reaching loopback).
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001:db8::/32',
        // 6to4: 2002:7f00:1:: encapsulates 127.0.0.1.
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        // Deprecated site-local, still routable on some networks.
        'fec0::/10',
        'ff00::/8',
    ];

    private const V4_MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    public function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        if (\strlen($binary) === 16 && str_starts_with($binary, self::V4_MAPPED_PREFIX)) {
            $mapped = inet_ntop(substr($binary, 12));

            return $mapped !== false && $this->isPublic($mapped);
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($binary, $range)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $binaryIp, string $range): bool
    {
        $parts = explode('/', $range);
        $binaryNetwork = inet_pton($parts[0]);
        $bits = (int) ($parts[1] ?? '0');

        if ($binaryNetwork === false || \strlen($binaryNetwork) !== \strlen($binaryIp)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if (substr($binaryIp, 0, $fullBytes) !== substr($binaryNetwork, 0, $fullBytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($binaryIp[$fullBytes]) & $mask) === (\ord($binaryNetwork[$fullBytes]) & $mask);
    }
}
