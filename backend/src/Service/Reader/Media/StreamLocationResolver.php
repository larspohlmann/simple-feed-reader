<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * A stream is fetched by script, not by the media element, so it plays only from
 * the URL that finally serves it: a cross-origin fetch dies on a redirect hop
 * without a CORS header. A chain that fails, or lands anywhere but on a durable
 * playlist, keeps the declared URL — the native client follows redirects itself.
 */
final readonly class StreamLocationResolver
{
    public function __construct(
        private MediaLanding $landings,
        private MediaUrlKind $mediaUrlKind,
    ) {
    }

    public function resolve(ArticleMedia $media): ArticleMedia
    {
        return new ArticleMedia(array_map($this->located(...), $media->candidates));
    }

    private function located(MediaCandidate $candidate): MediaCandidate
    {
        if ($candidate->kind !== MediaKind::Stream) {
            return $candidate;
        }
        $landing = $this->mediaUrlKind->resolve($this->landings->urlOf($candidate->url) ?? $candidate->url);

        return $landing?->kind === MediaKind::Stream ? $candidate->at($landing->url) : $candidate;
    }
}
