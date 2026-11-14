<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** A pair that fails exactly one link rule, with the rule and its numbers ("shared weight 12.8 < 14"). */
final readonly class NearMissModel
{
    public function __construct(public HeadlineOverlapModel $overlap, public string $failedRule)
    {
    }
}
