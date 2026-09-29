<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

/**
 * Dailymotion's player embed. The video id is the whole payload (a page URL adds a `_title-slug`, the query holds
 * player preferences), so every spelling, `dai.ly` and `/video/` included, folds to `/embed/video/<id>`.
 */
final readonly class DailymotionEmbedProvider implements EmbedProviderInterface
{
    private const array PAGE_HOSTS = ['dailymotion.com', 'www.dailymotion.com'];
    private const string SHORT_HOST = 'dai.ly';
    private const string ID = '[A-Za-z0-9]+';

    public function matches(string $url): bool
    {
        return $this->videoId($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $id = $this->videoId($url);

        return $id === null ? null : 'https://www.dailymotion.com/embed/video/' . $id;
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Watch on Dailymotion';
    }

    public function framePattern(): string
    {
        return '^https://www\.dailymotion\.com/embed/video/' . self::ID . '$';
    }

    public function sourceHosts(): array
    {
        return [...self::PAGE_HOSTS, self::SHORT_HOST];
    }

    private function videoId(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if ($host === self::SHORT_HOST) {
            return preg_match('#^/(' . self::ID . ')$#', $path, $matches) === 1 ? $matches[1] : null;
        }

        return \in_array($host, self::PAGE_HOSTS, true)
            && preg_match('#^/(?:embed/)?video/(' . self::ID . ')#', $path, $matches) === 1
            ? $matches[1]
            : null;
    }
}
