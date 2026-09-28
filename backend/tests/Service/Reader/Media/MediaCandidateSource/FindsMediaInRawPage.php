<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\MediaCandidateSource\MediaCandidateSourceInterface;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\RawPageModel;

trait FindsMediaInRawPage
{
    abstract private function source(): MediaCandidateSourceInterface;

    /** @return list<MediaCandidateModel> */
    private function find(string $html, string $url): array
    {
        return $this->source()->find(RawPageModel::parse($html, $url));
    }
}
