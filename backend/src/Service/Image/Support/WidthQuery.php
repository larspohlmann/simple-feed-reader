<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** On-demand resizers (WordPress VIP, Jetpack Photon, Sanity, imgix) read the target width from `w=`. */
final class WidthQuery
{
    private const string WIDTH_PARAMETER = '/([?&])w=\d*/';

    public static function withWidth(string $url, int $width): string
    {
        if (preg_match(self::WIDTH_PARAMETER, $url) === 1) {
            return (string) preg_replace(self::WIDTH_PARAMETER, '${1}w=' . $width, $url, 1);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'w=' . $width;
    }

    private function __construct()
    {
    }
}
