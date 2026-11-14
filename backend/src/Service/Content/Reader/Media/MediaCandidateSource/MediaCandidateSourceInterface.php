<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\RawPageModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One host-agnostic way to find a page's media, run in AsTaggedItem priority order, highest first (PageMediaScanner
 * merges). Reads the raw page, not FetchedPageNormalizer's document, which removes elements for readability.
 */
#[AutoconfigureTag('app.media_candidate_source')]
interface MediaCandidateSourceInterface
{
    /** @return list<MediaCandidateModel> */
    public function find(RawPageModel $page): array;
}
