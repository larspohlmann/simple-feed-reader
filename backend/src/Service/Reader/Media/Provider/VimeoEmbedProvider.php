<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * Vimeo player embed. Public video is `vimeo.com/<id>`; unlisted carries a
 * privacy hash as a second segment, which the player needs as `?h=<hash>`.
 */
final readonly class VimeoEmbedProvider implements EmbedProviderInterface
{
    private const string PLAYER_HOST = 'player.vimeo.com';
    private const array PAGE_HOSTS = ['vimeo.com', 'www.vimeo.com'];
    private const string HASH = '[A-Za-z0-9]+';

    public function matches(string $url): bool
    {
        return $this->normalize($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $reference = $this->reference($url);
        if ($reference === null) {
            return null;
        }
        [$id, $hash] = $reference;

        return 'https://player.vimeo.com/video/' . $id . ($hash === null ? '' : '?h=' . $hash);
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Watch on Vimeo';
    }

    /** @return array{0: string, 1: ?string}|null the video id and its optional privacy hash */
    private function reference(string $url): ?array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'], $parts['path'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if ($host === self::PLAYER_HOST) {
            return $this->fromPlayer($parts['path'], $parts['query'] ?? '');
        }

        return \in_array($host, self::PAGE_HOSTS, true) ? $this->fromPage($parts['path']) : null;
    }

    /** @return array{0: string, 1: ?string}|null */
    private function fromPlayer(string $path, string $query): ?array
    {
        if (preg_match('#^/video/(\d+)/?$#', $path, $matches) !== 1) {
            return null;
        }
        parse_str($query, $params);
        $hash = $params['h'] ?? null;

        return [$matches[1], \is_string($hash) && preg_match('#^' . self::HASH . '$#', $hash) === 1 ? $hash : null];
    }

    /** @return array{0: string, 1: ?string}|null */
    private function fromPage(string $path): ?array
    {
        return preg_match('#^/(\d+)(?:/(' . self::HASH . '))?/?$#', $path, $matches) === 1
            ? [$matches[1], $matches[2] ?? null]
            : null;
    }
}
