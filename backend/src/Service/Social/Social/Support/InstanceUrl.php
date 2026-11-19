<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Url\Support\UrlOrigin;
use App\Service\Social\Social\Exception\InvalidInstanceUrlException;

final class InstanceUrl
{
    /** @throws InvalidInstanceUrlException */
    public static function requireOrigin(string $instanceUrl): string
    {
        $typed = trim($instanceUrl);
        $parts = parse_url(str_contains($typed, '://') ? $typed : 'https://' . $typed);

        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            throw new InvalidInstanceUrlException(sprintf('"%s" names no instance.', $instanceUrl));
        }
        if ('https' !== strtolower($parts['scheme'] ?? '')) {
            throw new InvalidInstanceUrlException('An instance is reached over https only.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidInstanceUrlException('An instance URL carries no credentials.');
        }

        return UrlOrigin::fromParts('https', strtolower($parts['host']), $parts['port'] ?? null);
    }

    private function __construct()
    {
    }
}
