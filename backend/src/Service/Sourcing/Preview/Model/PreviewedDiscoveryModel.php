<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Preview\Model;

use App\Service\Sourcing\Discovery\Model\ScrapeFailureReason;

final readonly class PreviewedDiscoveryModel
{
    /** @param list<PreviewedCandidateModel> $candidates an empty list is a legitimate outcome; the reason says why */
    public function __construct(public array $candidates, public ?ScrapeFailureReason $scrapeFailureReason = null)
    {
    }
}
