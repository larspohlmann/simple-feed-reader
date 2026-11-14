<?php

declare(strict_types=1);

namespace App\Service\Preview\Model;

use App\Service\Discovery\Model\ScrapeFailureReason;

final readonly class PreviewedDiscoveryModel
{
    /** @param list<PreviewedCandidateModel> $candidates an empty list is a legitimate outcome; the reason says why */
    public function __construct(public array $candidates, public ?ScrapeFailureReason $scrapeFailureReason = null)
    {
    }
}
