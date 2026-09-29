<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

/**
 * The Spotify embed player. The content type and its base62 id are the whole payload, so the query (a `?si=` share
 * token, the embed builder's `utm_source`) goes, and an older `embed-podcast` path segment folds to `embed`.
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
