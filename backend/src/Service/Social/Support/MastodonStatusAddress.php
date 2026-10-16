<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

/** Where a Mastodon status address's own instance serves the status to anyone: its two address shapes only. */
final class MastodonStatusAddress
{
    private const array STATUS_SHAPES = [
        '~^(https://([a-z0-9.-]+)/users/[^/?#]+)/statuses/(\d{1,32})$~iD',
        '~^(https://([a-z0-9.-]+)/@[^/?#]+)/(\d{1,32})$~iD',
    ];

    private function __construct()
    {
    }

    public static function statusApiUrlOrNull(string $address): ?string
    {
        $parts = self::partsOrNull($address);

        return null === $parts ? null : self::apiUrl($parts);
    }

    public static function contextApiUrlOrNull(string $address): ?string
    {
        $parts = self::partsOrNull($address);

        return null === $parts ? null : self::apiUrl($parts) . '/context';
    }

    public static function authorPrefixOrNull(string $address): ?string
    {
        return self::partsOrNull($address)['prefix'] ?? null;
    }

    /** @param array{host: string, id: string, prefix: string} $parts */
    private static function apiUrl(array $parts): string
    {
        return sprintf('https://%s/api/v1/statuses/%s', $parts['host'], $parts['id']);
    }

    /** @return array{host: string, id: string, prefix: string}|null */
    private static function partsOrNull(string $address): ?array
    {
        foreach (self::STATUS_SHAPES as $shape) {
            if (1 === preg_match($shape, $address, $match)) {
                return ['host' => $match[2], 'id' => $match[3], 'prefix' => $match[1]];
            }
        }

        return null;
    }
}
