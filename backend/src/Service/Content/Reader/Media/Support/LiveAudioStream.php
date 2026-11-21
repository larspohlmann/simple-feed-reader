<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Media\Support;

use App\Entity\PlayableMediaFile;

final class LiveAudioStream
{
    private const array STREAM_STEMS = ['stream', 'live', 'livestream'];
    private const array PLAYLIST_EXTENSIONS = ['pls', 'm3u'];

    private function __construct()
    {
    }

    public static function is(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return false;
        }

        $path = $parts['path'];
        if (self::isStationHost($parts['host']) || stripos($path, '/live/') !== false) {
            return true;
        }

        $extension = PlayableMediaFile::extractExtension($url);
        if (\in_array($extension, self::PLAYLIST_EXTENSIONS, true)) {
            return true;
        }

        $stem = strtolower(pathinfo($path, \PATHINFO_FILENAME));

        return \in_array($stem, self::STREAM_STEMS, true)
            && ($extension === '' || PlayableMediaFile::isAudioExtension($extension));
    }

    private static function isStationHost(string $host): bool
    {
        return preg_match('#(^|\.)sslstream\.#i', $host) === 1;
    }
}
