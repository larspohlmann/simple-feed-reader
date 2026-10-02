<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Pool\Model;

/** The whole snapshot pool's size and date span (`Y-m-d`), scoped by the same subscription gate as its lines. */
final readonly class CandidatePoolSummaryModel
{
    public function __construct(
        public int $total,
        public string $oldest,
        public string $newest,
    ) {
    }
}
