<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Preview\Model;

use App\Service\Sourcing\Discovery\Model\FeedCandidateModel;

final readonly class PreviewedCandidateModel
{
    public function __construct(public FeedCandidateModel $candidate, public ?FeedPreviewModel $preview = null)
    {
    }
}
