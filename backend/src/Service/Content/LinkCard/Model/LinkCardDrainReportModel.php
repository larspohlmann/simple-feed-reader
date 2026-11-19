<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Model;

final readonly class LinkCardDrainReportModel
{
    public function __construct(
        public int $completed,
        public bool $caughtUp,
    ) {
    }
}
