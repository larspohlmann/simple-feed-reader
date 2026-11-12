<?php

declare(strict_types=1);

namespace App\Service\Preview\Model;

use App\Service\Discovery\Model\FeedCandidateModel;

final readonly class PreviewedCandidateModel
{
    public function __construct(public FeedCandidateModel $candidate, public ?FeedPreviewModel $preview = null)
    {
    }
}
