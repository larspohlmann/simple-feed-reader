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

    /**
     * $declared carries the page's own URLs, the shape SiblingIdRule needs to spot
     * a sibling id; $resolved is the same media after StreamLocationResolver, the
     * base the verified siblings are appended onto (#800 — a seed already moved to
     * its landing no longer names its sibling's id).
     */
    public function extend(ArticleMedia $declared, ArticleMedia $resolved, string $pageHtml): ArticleMedia
    {
        // A page can name one clip twice — through its VideoObject and its player
        // config (#1055) — so the search re-derives a seed already declared. Skip
        // it before the network round-trip, not after: the derived URL is the
        // declared template with the id swapped, so it matches a seed byte for byte.
        $seen = [];
        foreach ($declared->candidates as $candidate) {
            $seen[$candidate->url] = true;
        }

        $verified = [];
        foreach ($this->rule->derive($declared, $pageHtml) as $candidate) {
            if (isset($seen[$candidate->url])) {
                continue;
            }
            $seen[$candidate->url] = true;
            $sibling = $this->landed($candidate);
            if ($sibling !== null) {
                $verified[] = $sibling;
            }
        }

        return $resolved->with($verified);
    }

    private function landed(MediaCandidate $candidate): ?MediaCandidate
    {
        $landing = $this->landings->urlOf($candidate->url);
        $resolved = $landing === null ? null : $this->mediaUrlKind->resolve($landing);

        return $resolved?->kind === $candidate->kind ? $candidate->at($resolved->url) : null;
    }
}
