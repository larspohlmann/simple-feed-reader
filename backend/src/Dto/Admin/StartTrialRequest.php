<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A trial length in whole days, counted from now. Bounded so a typo cannot set
 * a decade-long trial; the lower bound rejects zero and negatives, which would
 * otherwise create an already-expired trial.
 */
final readonly class StartTrialRequest
{
    public function __construct(
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(3650)]
        public int $days = 0,
    ) {
    }
}
