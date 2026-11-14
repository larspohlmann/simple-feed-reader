<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;

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
     * Siblings are derived from $declared, whose URLs still name the sibling id, and appended onto $resolved (after
     * StreamLocationResolver): a seed already moved to its landing no longer names that id.
     */
    public function extend(
        ArticleMediaModel $declared,
        ArticleMediaModel $resolved,
        string $pageHtml,
    ): ArticleMediaModel {
        // A page can name one clip twice (its VideoObject and its player config), so the rule re-derives a declared
        // seed byte for byte. Skip it here, before the network round-trip.
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

    private function landed(MediaCandidateModel $candidate): ?MediaCandidateModel
    {
        $landing = $this->landings->urlOf($candidate->url);
        $resolved = $landing === null ? null : $this->mediaUrlKind->resolve($landing);

        return $resolved?->kind === $candidate->kind ? $candidate->at($resolved->url) : null;
    }
}
