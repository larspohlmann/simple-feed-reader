<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

final class CookieHeader
{
    /** @param list<string> $setCookies */
    public static function fromSetCookies(array $setCookies): string
    {
        $pairs = [];
        foreach ($setCookies as $setCookie) {
            $pair = trim(explode(';', $setCookie, 2)[0]);
            if (strpos($pair, '=') > 0) {
                $pairs[] = $pair;
            }
        }

        return implode('; ', $pairs);
    }

    private function __construct()
    {
    }
}
