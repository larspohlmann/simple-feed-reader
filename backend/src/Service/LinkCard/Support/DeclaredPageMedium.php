<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Entity\EntryMedium;
use App\Service\Parser\Support\VisualMedium;

/** The picture or video file a page declares itself to be. A player page names an iframe, which is no file: none. */
final class DeclaredPageMedium
{
    private const array VIDEO_KEYS = ['og:video:secure_url', 'og:video:url', 'og:video'];

    /** @param array<string, string> $meta */
    public static function of(array $meta): ?EntryMedium
    {
        $poster = $meta['og:image'] ?? null;
        foreach (self::VIDEO_KEYS as $key) {
            $video = isset($meta[$key]) ? VisualMedium::at($meta[$key], $poster) : null;
            if ($video?->kind === 'video') {
                return $video;
            }
        }

        return self::isPhoto($meta) && $poster !== null ? VisualMedium::at($poster) : null;
    }

    /** @param array<string, string> $meta */
    private static function isPhoto(array $meta): bool
    {
        return strtolower($meta['twitter:card'] ?? '') === 'photo'
            || str_ends_with(strtolower($meta['og:type'] ?? ''), 'photo');
    }

    private function __construct()
    {
    }
}
