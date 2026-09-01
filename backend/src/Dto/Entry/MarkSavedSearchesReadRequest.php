<?php

declare(strict_types=1);

namespace App\Dto\Entry;

/**
 * "Mark the combined saved-search list read." It names no scope: the list is
 * every saved search the caller keeps, so `until` — the moment the reader last
 * had it on screen — is the whole request.
 */
final readonly class MarkSavedSearchesReadRequest
{
    public function __construct(
        public \DateTimeImmutable $until,
    ) {
    }
}
