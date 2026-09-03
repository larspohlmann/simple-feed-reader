<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Subscription\SubscriptionLimitResolver;

/**
 * Whether a counted backup fits this account, checked before deletion: too
 * many subscriptions for the account's limit, or too much of any other
 * counted line kind for the file format to plausibly hold.
 *
 * Every dimension is bounded, not just the costliest two. The load runs after
 * the wipe, and tag/feed/subscription rows stay managed entities until the
 * entry phase flushes them — millions of such lines would exhaust memory on
 * an already-emptied account, a fatal that BackupLoadFailedException cannot
 * catch or report.
 */
final readonly class BackupFitCheck
{
    /**
     * Not a tuned limit: the 240 s budget would allow ~2 million entries.
     * A file above this is corrupt or hostile, not a large account.
     */
    private const int MAX_ENTRIES = 500_000;

    /**
     * A state belongs to at most one entry, so a file carrying more states
     * than the entry ceiling is corrupt by its own arithmetic.
     */
    private const int MAX_ENTRY_STATES = 500_000;

    /**
     * Not a tuned limit either: tags are a hand-curated sidebar the UI renders
     * in full, so five thousand is already far past anything a person builds.
     */
    private const int MAX_TAGS = 5_000;

    /**
     * A genuine backup carries exactly one feed line per subscription, and the
     * subscription count is bounded by the account's own limit just above.
     * This ceiling therefore only catches a file whose feed lines are
     * unrelated to its subscriptions — while keeping the pre-flush set of
     * managed Feed entities small enough to hold.
     */
    private const int MAX_FEEDS = 20_000;

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

        $this->assertBelowCeiling($inventory->tags, self::MAX_TAGS, 'tags');
        $this->assertBelowCeiling($inventory->feeds, self::MAX_FEEDS, 'feeds');
        $this->assertBelowCeiling($inventory->entries, self::MAX_ENTRIES, 'entries');
        $this->assertBelowCeiling($inventory->entryStates, self::MAX_ENTRY_STATES, 'entry states');
    }

    private function assertBelowCeiling(int $counted, int $ceiling, string $kind): void
    {
        if ($counted <= $ceiling) {
            return;
        }

        throw new BackupDoesNotFitException(sprintf(
            'The backup holds %d %s; the ceiling is %d.',
            $counted,
            $kind,
            $ceiling,
        ));
    }
}
