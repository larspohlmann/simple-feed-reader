<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

final class ProfileIdentity
{
    /** @return array{string, string}|null the name and host of `name@host`; null for any other shape */
    public static function parseNameAndHost(string $identity): ?array
    {
        $nameAndHost = explode('@', $identity);

        return 2 === \count($nameAndHost) ? [$nameAndHost[0], $nameAndHost[1]] : null;
    }

    /** The built feed URL when the identity it reads back as is the one it was built from, case aside; else null. */
    public static function parseRoundTripUrl(string $url, ?string $parsedIdentity, string $identity): ?string
    {
        return null !== $parsedIdentity && 0 === strcasecmp($parsedIdentity, $identity) ? $url : null;
    }

    private function __construct()
    {
    }
}
