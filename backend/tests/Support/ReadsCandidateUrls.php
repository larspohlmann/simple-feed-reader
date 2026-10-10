<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\Media\Model\MediaCandidateModel;

trait ReadsCandidateUrls
{
    /**
     * @param list<MediaCandidateModel> $candidates
     *
     * @return list<string>
     */
    private function urlsOf(array $candidates): array
    {
        return array_map(static fn (MediaCandidateModel $candidate): string => $candidate->url, $candidates);
    }
}
