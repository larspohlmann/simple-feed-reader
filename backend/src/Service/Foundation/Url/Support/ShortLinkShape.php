<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

/** A URL whose path is one opaque token, with no word separator and no file extension: how short links look. */
final class ShortLinkShape
{
    private function __construct()
    {
    }

    public static function fits(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return false;
        }
        $segment = trim($parts['path'], '/');

        return $segment !== '' && strpbrk($segment, '/.-') === false;
    }
}
