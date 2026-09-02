<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * Brightcove's hosted player page, declared as a VideoObject's embedUrl
 * (Al Jazeera, #782). The video id lives in the query, so the query is
 * reduced to it rather than dropped; the player id is kept verbatim.
 */
final readonly class BrightcoveEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'players.brightcove.net';
    private const string PATH_PATTERN = '#^/(\d+)/([A-Za-z0-9_-]+)/index\.html$#';
    private const string VIDEO_ID_PATTERN = '#^\d+$#';

    public function matches(string $url): bool
    {
        return $this->normalize($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== self::HOST) {
            return null;
        }
        if (preg_match(self::PATH_PATTERN, $parts['path'] ?? '', $path) !== 1) {
            return null;
        }
        $videoId = $this->videoId($parts['query'] ?? '');

        return $videoId === null
            ? null
            : \sprintf('https://%s/%s/%s/index.html?videoId=%s', self::HOST, $path[1], $path[2], $videoId);
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Watch the video';
    }

    private function videoId(string $query): ?string
    {
        parse_str($query, $params);
        $videoId = $params['videoId'] ?? null;

        return \is_string($videoId) && preg_match(self::VIDEO_ID_PATTERN, $videoId) === 1 ? $videoId : null;
    }
}
