<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Support;

final class YouTubeVideoId
{
    public const string PATTERN = '[A-Za-z0-9_-]{11}';

    public const array YOUTUBE_COM_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

    public static function matches(string $candidate): bool
    {
        return 1 === preg_match('#^' . self::PATTERN . '\z#', $candidate);
    }

    public static function isYouTubeComHost(string $host): bool
    {
        return \in_array(strtolower($host), self::YOUTUBE_COM_HOSTS, true);
    }

    private function __construct()
    {
    }
}
