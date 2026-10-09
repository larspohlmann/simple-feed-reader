<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Support;

/** The shape of the picture an entry's cover shows, when the image file letterboxes it. */
final class CoverAspectRatio
{
    private const float PORTRAIT_VIDEO = 9 / 16;

    public static function of(?string $entryUrl): ?float
    {
        return YouTubeShortUrl::is($entryUrl) ? self::PORTRAIT_VIDEO : null;
    }

    private function __construct()
    {
    }
}
