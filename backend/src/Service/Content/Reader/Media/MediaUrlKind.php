<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Media;

use App\Entity\PlayableMediaFile;
use App\Service\Content\Reader\Media\Model\MediaKind;
use App\Service\Content\Reader\Media\Model\ResolvedMediaUrlModel;

/**
 * What a URL is and the durable form to emit for it; every source asks this rather than judging media URLs itself.
 * resolve() returns the form it judged, so no signed or tracking query reaches the TTL-less cache.
 */
final readonly class MediaUrlKind
{
    private const array STREAM_EXTENSIONS = ['m3u8'];

    public function __construct(
        private DurableMediaUrl $durable,
        private EmbedProviders $providers,
    ) {
    }

    public function resolve(string $url): ?ResolvedMediaUrlModel
    {
        $embed = $this->providers->resolve($url);
        if ($embed !== null) {
            return new ResolvedMediaUrlModel(MediaKind::Embed, $embed->url);
        }

        return $this->resolveFile($url);
    }

    private function resolveFile(string $url): ?ResolvedMediaUrlModel
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

        return $kind === null ? null : new ResolvedMediaUrlModel($kind, $bare);
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
        $extension = PlayableMediaFile::extractExtension($bareUrl);

        return match (true) {
            PlayableMediaFile::isAudioExtension($extension) => MediaKind::Audio,
            PlayableMediaFile::isVideoExtension($extension) => MediaKind::Video,
            \in_array($extension, self::STREAM_EXTENSIONS, true) => MediaKind::Stream,
            default => null,
        };
    }
}
