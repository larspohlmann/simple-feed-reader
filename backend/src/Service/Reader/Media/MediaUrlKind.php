<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * What a URL is, and the durable form a layer must emit for it.
 *
 * Every layer asks this instead of carrying its own idea of a media URL, which
 * is what keeps a player page or a poster image from being emitted as a file,
 * and an HLS playlist from being emitted as anything but a Stream (#782). The
 * cache has no TTL, so a signed or analytics-bearing query string can never
 * survive into an emitted candidate: resolve() judges the bare form and hands
 * that same bare form back, so no caller can emit the raw, query-bearing url
 * by mistake.
 */
final readonly class MediaUrlKind
{
    private const array AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'oga', 'ogg', 'opus', 'wav', 'flac'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov'];
    private const array STREAM_EXTENSIONS = ['m3u8'];

    public function __construct(
        private DurableMediaUrl $durable,
        private EmbedProviders $providers,
    ) {
    }

    public function resolve(string $url): ?ResolvedMediaUrl
    {
        $embed = $this->providers->resolve($url);
        if ($embed !== null) {
            return new ResolvedMediaUrl(MediaKind::Embed, $embed->url);
        }

        return $this->resolveFile($url);
    }

    private function resolveFile(string $url): ?ResolvedMediaUrl
    {
        $bare = $this->withoutQuery($url);
        if ($bare === null || !$this->durable->accepts($bare)) {
            return null;
        }

        $kind = $this->byExtension($bare);
        // A file's query is disposable tracking; a stream's is often an access
        // token the bare playlist 403s without, so a tokenised one is refused.
        if ($kind === MediaKind::Stream && $bare !== $url) {
            return null;
        }

        return $kind === null ? null : new ResolvedMediaUrl($kind, $bare);
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
            \in_array($extension, self::STREAM_EXTENSIONS, true) => MediaKind::Stream,
            default => null,
        };
    }
}
