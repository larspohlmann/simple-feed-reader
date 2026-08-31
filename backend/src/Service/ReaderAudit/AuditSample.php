<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * What one sweep draws: whose subscriptions, how many articles, how many per
 * feed, which seed, and the cutoff that freezes the candidate set. Five values
 * that only mean anything together, and that every shard of a run must hold
 * identically — a signature of five scalars invites a shard to be started with
 * four of them right.
 */
final readonly class AuditSample
{
    public function __construct(
        public int $userId,
        public int $limit,
        public int $perFeed,
        public int $seed,
        public \DateTimeImmutable $before,
    ) {
    }
}
