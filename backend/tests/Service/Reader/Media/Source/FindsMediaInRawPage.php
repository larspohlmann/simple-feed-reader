<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\RawPage;

trait FindsMediaInRawPage
{
    abstract private function source(): MediaCandidateSourceInterface;

    /** @return list<MediaCandidate> */
    private function find(string $html, string $url): array
    {
        return $this->source()->find(RawPage::parse($html, $url));
    }
}
