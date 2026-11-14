<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Media\Sibling;

use App\Service\Content\Reader\Media\Model\ArticleMediaModel;
use App\Service\Content\Reader\Media\Model\MediaCandidateModel;
use App\Service\Content\Reader\Media\Sibling\Pass\SiblingSearch;

/**
 * The page's other media, named beside a found one by a bare id: the found URL
 * is the template, the id's keyed context tells sibling ids from a navigation
 * list. Derived candidates are guesses until SiblingMediaExtender verifies them.
 */
final readonly class SiblingIdRule
{
    public function __construct(private NearbyPoster $nearbyPoster)
    {
    }

    /** @return list<MediaCandidateModel> */
    public function derive(ArticleMediaModel $found, string $pageHtml): array
    {
        $search = new SiblingSearch($pageHtml, $this->nearbyPoster);
        $derived = [];
        foreach ($found->candidates as $seed) {
            foreach ($search->siblingsOf($seed) as $candidate) {
                $derived[$candidate->url] ??= $candidate;
            }
        }

        return array_values($derived);
    }
}
