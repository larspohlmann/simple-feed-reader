<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Pass;

use App\Service\LinkCard\Exception\FetchBudgetSpentException;

/** The page fetches one pass may still spend. */
final class FetchBudget
{
    public function __construct(private int $remaining)
    {
    }

    /** @throws FetchBudgetSpentException */
    public function spendOne(): void
    {
        if ($this->remaining === 0) {
            throw new FetchBudgetSpentException('No page fetch left in this pass.');
        }
        $this->remaining--;
    }
}
