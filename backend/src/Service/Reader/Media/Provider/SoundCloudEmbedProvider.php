<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * The SoundCloud widget. The track id is permanent and the src is unsigned, so
 * the player survives a cache with no TTL. Only the track is kept: rebuilding
 * the URL from the id alone is what guarantees `auto_play=true` cannot survive.
 */
final readonly class SoundCloudEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'w.soundcloud.com';
    /** Some widgets store the id as a double-encoded `soundcloud%3Atracks%3A` URN, not a bare id. */
    private const string TRACK_PATTERN = '#^https?://api\.soundcloud\.com/tracks/(?:soundcloud%3Atracks%3A)?(\d+)$#i';

    public function matches(string $url): bool
    {
        return $this->trackId($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $id = $this->trackId($url);
        if ($id === null) {
            return null;
        }

        return 'https://w.soundcloud.com/player/?url='
            . rawurlencode('https://api.soundcloud.com/tracks/' . $id);
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Listen on SoundCloud';
    }

    private function trackId(string $url): ?string
    {
        $parts = parse_url($url);
        if (strtolower($parts['host'] ?? '') !== self::HOST || !isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $params);
        $track = $params['url'] ?? null;

        return \is_string($track) && preg_match(self::TRACK_PATTERN, $track, $m) === 1 ? $m[1] : null;
    }
}
