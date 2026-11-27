<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership;

use App\Repository\EntryMembershipSweepRepository;
use Psr\Clock\ClockInterface;

final readonly class SettledCeiling
{
    /** How old an entry must be before it is matched, so an engine's async indexing has landed. */
    public const int SETTLE_SECONDS = 60;

    public function __construct(private EntryMembershipSweepRepository $entries, private ClockInterface $clock)
    {
    }

    public function readSettledCeiling(): int
    {
        return $this->entries->settledCeilingId(
            $this->clock->now()->modify(\sprintf('-%d seconds', self::SETTLE_SECONDS)),
        );
    }
}
