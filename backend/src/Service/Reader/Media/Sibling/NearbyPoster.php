<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

/** The largest still declared within reach of a position — a player config lists its poster's renditions beside its id. */
final readonly class NearbyPoster
{
    private const int WINDOW = 2000;
    private const string HTTPS_URL = '#https://[^\s"\'<>\\\\]+#';
    private const string IMAGE_LIKE = '#\.(jpe?g|png|webp|gif|avif)(\?|$)|\d+x\d+#i';
    private const string NEVER_AN_IMAGE = '#\.(m3u8|mp4|mp3|js|css|json)(\?|$)#i';
    private const string DIMENSIONS = '/(\d+)x(\d+)/';

    public static function after(string $html, int $position): ?string
    {
        preg_match_all(self::HTTPS_URL, substr($html, $position, self::WINDOW), $matches);
        $best = null;
        $bestArea = -1;
        foreach ($matches[0] as $url) {
            $area = self::area($url);
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $url;
            }
        }

        return $best;
    }

    /** Pixels the URL declares, 0 for an image without dimensions, -1 for anything that is not an image. */
    private static function area(string $url): int
    {
        if (preg_match(self::NEVER_AN_IMAGE, $url) === 1 || preg_match(self::IMAGE_LIKE, $url) !== 1) {
            return -1;
        }

        return preg_match(self::DIMENSIONS, $url, $dimensions) === 1
            ? (int) $dimensions[1] * (int) $dimensions[2]
            : 0;
    }
}
