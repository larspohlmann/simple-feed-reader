<?php

declare(strict_types=1);

namespace App\Entity;

final class PlayableMediaFile
{
    /** @var list<string> */
    private const array AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac'];

    /** @var list<string> */
    private const array VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov'];

    public static function isAudioExtension(string $extension): bool
    {
        return \in_array($extension, self::AUDIO_EXTENSIONS, true);
    }

    public static function isVideoExtension(string $extension): bool
    {
        return \in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    public static function extractExtension(string $url): string
    {
        $path = strtolower((string) parse_url(trim($url), \PHP_URL_PATH));

        return pathinfo($path, \PATHINFO_EXTENSION);
    }

    private function __construct()
    {
    }
}
