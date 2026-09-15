<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * The Spotify embed player, for a playlist, track, album, artist or podcast.
 * The content type and its base62 id are the whole payload, so the query goes:
 * `?si=` is a share token and `?utm_source=generator` is the embed builder's
 * tag. Older podcast embeds carry an `embed-podcast` path segment, folded to
 * the current `embed` form.
 */
final readonly class SpotifyEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'open.spotify.com';
    private const string TYPE = 'playlist|track|album|episode|show|artist';
    private const string ID = '[A-Za-z0-9]+';

    public function matches(string $url): bool
    {
        return $this->reference($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $reference = $this->reference($url);

        return $reference === null ? null : 'https://open.spotify.com/embed/' . $reference[0] . '/' . $reference[1];
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Listen on Spotify';
    }

    public function framePattern(): string
    {
        return '^https://' . preg_quote(self::HOST, '#') . '/embed/(?:' . self::TYPE . ')/' . self::ID . '$';
    }

    public function sourceHosts(): array
    {
        return [self::HOST];
    }

    /** @return array{0: string, 1: string}|null the content type and its id */
    private function reference(string $url): ?array
    {
        $parts = parse_url($url);
        if (strtolower($parts['host'] ?? '') !== self::HOST || !isset($parts['path'])) {
            return null;
        }

        $pattern = '#^/(?:embed/|embed-podcast/)?(' . self::TYPE . ')/(' . self::ID . ')/?$#';

        return preg_match($pattern, $parts['path'], $matches) === 1 ? [$matches[1], $matches[2]] : null;
    }
}
