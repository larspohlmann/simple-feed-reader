<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\PrivateFeedSyncSkip;

final readonly class PrivateFeedSyncOutcomeModel
{
    public function __construct(
        public int $added = 0,
        public int $removed = 0,
        public ?PrivateFeedSyncSkip $skip = null,
    ) {
    }
}
