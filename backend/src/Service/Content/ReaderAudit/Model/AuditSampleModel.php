<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/**
 * What one sweep draws: whose subscriptions, how many articles in all and per feed, the seed, and the cutoff that
 * freezes the candidate set. Every shard of a run must hold all five identically.
 */
final readonly class AuditSampleModel
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
