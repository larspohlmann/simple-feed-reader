<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

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

    public static function orNullWithin(?string $url, int $maxLength): ?string
    {
        $absolute = self::orNull($url);

        return null !== $absolute && mb_strlen($absolute) <= $maxLength ? $absolute : null;
    }

    private function __construct()
    {
    }
}
