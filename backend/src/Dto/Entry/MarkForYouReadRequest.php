<?php

declare(strict_types=1);

namespace App\Dto\Entry;

/**
 * "Mark the for-you list read." It names no scope: the list is the caller's
 * own ranked feed, so `until` — the moment the reader last had it on screen —
 * is the whole request.
 */
final readonly class MarkForYouReadRequest
{
    public function __construct(
        public \DateTimeImmutable $until,
    ) {
    }
}
