<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Support;

final class LiveAudioStream
{
    private const array STREAM_STEMS = ['stream', 'live', 'livestream'];
    private const array AUDIO_EXTENSIONS = ['', 'mp3', 'aac', 'm4a', 'ogg', 'oga', 'opus'];
    private const array PLAYLIST_EXTENSIONS = ['pls', 'm3u'];

    public static function is(string $url): bool
    {
        $path = parse_url($url, \PHP_URL_PATH);
        if (!\is_string($path) || parse_url($url, \PHP_URL_HOST) === null) {
            return false;
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (\in_array($extension, self::PLAYLIST_EXTENSIONS, true)) {
            return true;
        }

        $stem = strtolower(pathinfo($path, \PATHINFO_FILENAME));

        return \in_array($stem, self::STREAM_STEMS, true) && \in_array($extension, self::AUDIO_EXTENSIONS, true);
    }
}
