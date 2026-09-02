<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaUrlKind;

/** Adds the media SiblingIdRule derives, once the network has confirmed each URL: it must land 2xx on a URL of the seed's kind. */
final readonly class SiblingMediaExtender
{
    public function __construct(
        private SiblingIdRule $rule,
        private MediaLanding $landings,
        private MediaUrlKind $mediaUrlKind,
    ) {
    }

    public function extend(ArticleMedia $media, string $pageHtml): ArticleMedia
    {
        $verified = [];
        foreach ($this->rule->derive($media, $pageHtml) as $candidate) {
            $landed = $this->landed($candidate);
            if ($landed !== null) {
                $verified[] = $landed;
            }
        }

        return $media->with($verified);
    }

    private function landed(MediaCandidate $candidate): ?MediaCandidate
    {
        $landing = $this->landings->urlOf($candidate->url);
        $resolved = $landing === null ? null : $this->mediaUrlKind->resolve($landing);

        return $resolved?->kind === $candidate->kind ? $candidate->at($resolved->url) : null;
    }
}
