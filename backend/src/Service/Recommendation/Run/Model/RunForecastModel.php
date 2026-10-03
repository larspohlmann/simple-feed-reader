<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/** What the account's history predicts for a live run: the seconds still ahead and the finished batches' share. */
final readonly class RunForecastModel
{
    public function __construct(public int $etaSeconds, public float $finishedShare)
    {
    }
}
