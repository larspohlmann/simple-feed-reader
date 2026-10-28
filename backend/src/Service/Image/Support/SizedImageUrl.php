<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** The `-640x360` filename suffix CMSes such as WordPress name their stored sizes with. */
final class SizedImageUrl
{
    private const string BEFORE_EXTENSION = '(?=\.[a-z0-9]{2,5}(?:[?#]|$))';
    private const string SUFFIX = '/-\d+x\d+' . self::BEFORE_EXTENSION . '/i';
    private const string UNSCALED_MARKER = '/-(?:scaled|rotated)' . self::BEFORE_EXTENSION . '/i';
    private const string EXTENSION = '/' . self::BEFORE_EXTENSION . '/i';

    public static function hasSizeSuffix(string $url): bool
    {
        return preg_match(self::SUFFIX, $url) === 1;
    }

    public static function withoutSizeSuffix(string $url): string
    {
        return (string) preg_replace(self::SUFFIX, '', $url, 1);
    }

    public static function atSize(string $url, int $width, int $height): string
    {
        $stem = (string) preg_replace(self::UNSCALED_MARKER, '', self::withoutSizeSuffix($url), 1);

        return (string) preg_replace(self::EXTENSION, '-' . $width . 'x' . $height, $stem, 1);
    }

    private function __construct()
    {
    }
}
