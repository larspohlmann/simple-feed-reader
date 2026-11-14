<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;

/**
 * Moves a stream to the URL that finally serves it: a script fetches it, and a cross-origin fetch dies on a redirect
 * without CORS. A chain that fails or lands off a durable playlist keeps the declared URL (native follows redirects).
 */
final readonly class StreamLocationResolver
{
    public function __construct(
        private MediaLanding $landings,
        private MediaUrlKind $mediaUrlKind,
    ) {
    }

    public function resolve(ArticleMediaModel $media): ArticleMediaModel
    {
        return new ArticleMediaModel(array_map($this->located(...), $media->candidates));
    }

    private function located(MediaCandidateModel $candidate): MediaCandidateModel
    {
        if ($candidate->kind !== MediaKind::Stream) {
            return $candidate;
        }
        $landing = $this->mediaUrlKind->resolve($this->landings->urlOf($candidate->url) ?? $candidate->url);

        return $landing?->kind === MediaKind::Stream ? $candidate->at($landing->url) : $candidate;
    }
}
