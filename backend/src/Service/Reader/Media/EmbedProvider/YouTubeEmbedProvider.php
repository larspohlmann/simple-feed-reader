<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use App\Service\Reader\Media\Support\YouTubeShortUrl;
use App\Service\Reader\Media\Support\YouTubeVideoId;

/**
 * YouTube in every spelling a publisher uses, reduced to one nocookie embed.
 * The video id is the whole payload, so the query goes: `?si=` is a share
 * token, and `rel`/`autoplay`/`showinfo` are player preferences we override.
 */
final readonly class YouTubeEmbedProvider implements EmbedProviderInterface
{
    private const array HOSTS = [
        ...YouTubeVideoId::YOUTUBE_COM_HOSTS,
        'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'youtu.be', 'www.youtu.be',
    ];

    private const string PATH_PATTERN = '#^/(?:embed/|v/)?(' . YouTubeVideoId::PATTERN . ')$#';

    public function matches(string $url): bool
    {
        return $this->videoId($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $parts = $this->youTubeParts($url);
        $id = $parts === null ? null : $this->idFrom($parts);
        if ($parts === null || $id === null) {
            return null;
        }

        return 'https://www.youtube-nocookie.com/embed/' . $id . $this->shortsFragment($parts);
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

    public function frames(): array
    {
        $video = '^https://www\.youtube-nocookie\.com/embed/' . YouTubeVideoId::PATTERN;

        return [
            new EmbedFrameModel($video . '$', EmbedKind::Video, EmbedShape::Landscape),
            new EmbedFrameModel($video . YouTubeShortUrl::FRAGMENT . '$', EmbedKind::Video, EmbedShape::Portrait),
        ];
    }

    public function sourceHosts(): array
    {
        return self::HOSTS;
    }

    private function videoId(string $url): ?string
    {
        $parts = $this->youTubeParts($url);

        return $parts === null ? null : $this->idFrom($parts);
    }

    /**
     * @return array{host: string, path: string, query: string}|null
     */
    private function youTubeParts(string $url): ?array
    {
        $parts = parse_url($url);
        if (!isset($parts['host'], $parts['path']) || !\in_array(strtolower($parts['host']), self::HOSTS, true)) {
            return null;
        }

        return ['host' => $parts['host'], 'path' => $parts['path'], 'query' => $parts['query'] ?? ''];
    }

    /**
     * @param array{host: string, path: string, query: string} $parts
     */
    private function idFrom(array $parts): ?string
    {
        return YouTubeShortUrl::videoId($parts['host'], $parts['path'])
            ?? $this->idFromPath($parts['path'])
            ?? $this->idFromQuery($parts['query']);
    }

    /**
     * @param array{host: string, path: string, query: string} $parts
     */
    private function shortsFragment(array $parts): string
    {
        return YouTubeShortUrl::videoId($parts['host'], $parts['path']) === null ? '' : YouTubeShortUrl::FRAGMENT;
    }

    private function idFromPath(string $path): ?string
    {
        return preg_match(self::PATH_PATTERN, $path, $matches) === 1 ? $matches[1] : null;
    }

    private function idFromQuery(string $query): ?string
    {
        parse_str($query, $queryParameters);
        $id = $queryParameters['v'] ?? null;

        return \is_string($id) && preg_match('#^' . YouTubeVideoId::PATTERN . '$#', $id) === 1 ? $id : null;
    }
}
