<?php

declare(strict_types=1);

namespace App\Service\Url;

final class AbsoluteHttpUrl
{
    public static function matches(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    public static function orNull(?string $url): ?string
    {
        return $url !== null && self::matches($url) ? $url : null;
    }
}
