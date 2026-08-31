<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * What a URL is, if it is playable media at all.
 *
 * Every layer asks this instead of carrying its own idea of a media URL, which
 * is what keeps a player page, a poster image or an HLS playlist from being
 * emitted as a file. Analytics parameters are stripped before judging, then the
 * bare URL must still satisfy DurableMediaUrl — so a real signature, which does
 * not survive stripping, is refused.
 */
final readonly class MediaUrlKind
{
    private const array AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'oga', 'ogg', 'opus', 'wav', 'flac'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov'];

    public function __construct(
        private DurableMediaUrl $durable,
        private EmbedProviders $providers,
    ) {
    }

    /** The cache has no TTL, so a query-bearing url is never written to it. */
    public function durableUrl(string $url): ?string
    {
        $kind = $this->of($url);
        if ($kind !== MediaKind::Audio && $kind !== MediaKind::Video) {
            return null;
        }

        return $this->withoutQuery($url);
    }

    public function of(string $url): ?MediaKind
    {
        if ($this->providers->resolve($url) !== null) {
            return MediaKind::Embed;
        }

        $bare = $this->withoutQuery($url);
        if ($bare === null || !$this->durable->accepts($bare)) {
            return null;
        }

        return $this->byExtension($bare);
    }

    private function withoutQuery(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
    }

    private function byExtension(string $bareUrl): ?MediaKind
    {
        $path = parse_url($bareUrl, \PHP_URL_PATH);
        $extension = strtolower(pathinfo(\is_string($path) ? $path : '', \PATHINFO_EXTENSION));

        return match (true) {
            \in_array($extension, self::AUDIO_EXTENSIONS, true) => MediaKind::Audio,
            \in_array($extension, self::VIDEO_EXTENSIONS, true) => MediaKind::Video,
            default => null,
        };
    }
}
