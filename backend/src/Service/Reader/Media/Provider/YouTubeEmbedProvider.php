<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * YouTube in every spelling a publisher uses, reduced to one nocookie embed.
 * The video id is the whole payload, so the query goes: `?si=` is a share
 * token, and `rel`/`autoplay`/`showinfo` are player preferences we override.
 */
final readonly class YouTubeEmbedProvider implements EmbedProviderInterface
{
    private const string ID = '[A-Za-z0-9_-]{11}';

    private const array HOSTS = [
        'youtube.com', 'www.youtube.com',
        'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'youtu.be', 'www.youtu.be',
    ];

    public function matches(string $url): bool
    {
        return $this->videoId($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $id = $this->videoId($url);

        return $id === null ? null : 'https://www.youtube-nocookie.com/embed/' . $id;
    }

    public function poster(string $url): ?string
    {
        $id = $this->videoId($url);

        return $id === null ? null : 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
    }

    public function label(): string
    {
        return 'Watch on YouTube';
    }

    private function videoId(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['host'], $parts['path']) || !\in_array(strtolower($parts['host']), self::HOSTS, true)) {
            return null;
        }

        return $this->idFromPath($parts['path']) ?? $this->idFromQuery($parts['query'] ?? '');
    }

    private function idFromPath(string $path): ?string
    {
        return preg_match('#^/(?:embed/|v/)?(' . self::ID . ')$#', $path, $m) === 1 ? $m[1] : null;
    }

    private function idFromQuery(string $query): ?string
    {
        parse_str($query, $params);
        $id = $params['v'] ?? null;

        return \is_string($id) && preg_match('#^' . self::ID . '$#', $id) === 1 ? $id : null;
    }
}
