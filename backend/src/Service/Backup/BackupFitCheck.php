<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Subscription\SubscriptionLimitResolver;

/**
 * Whether a counted backup fits this account, checked before anything is
 * deleted. Two refusals: more subscriptions than the account's own limit
 * allows, or more entries than the file format can plausibly hold.
 */
final readonly class BackupFitCheck
{
    /**
     * Not a tuned limit: the 240 s budget would allow ~2 million entries.
     * A file above this is corrupt or hostile, not a large account.
     */
    private const int MAX_ENTRIES = 500_000;

    public function __construct(private SubscriptionLimitResolver $subscriptionLimits)
    {
    }

    public function assertFits(BackupInventory $inventory, User $user): void
    {
        $limit = $this->subscriptionLimits->resolve($user);
        if ($inventory->subscriptions > $limit) {
            throw new BackupDoesNotFitException(sprintf(
                'The backup holds %d subscriptions; this account allows %d.',
                $inventory->subscriptions,
                $limit,
            ));
        }
        if ($inventory->entries > self::MAX_ENTRIES) {
            throw new BackupDoesNotFitException(sprintf(
                'The backup holds %d entries; the ceiling is %d.',
                $inventory->entries,
                self::MAX_ENTRIES,
            ));
        }
    }
}
