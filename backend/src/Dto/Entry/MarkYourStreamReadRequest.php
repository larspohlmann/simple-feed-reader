<?php

declare(strict_types=1);

namespace App\Dto\Entry;

/** "Mark the For you stream read." It names no scope: `until` is the moment the reader last had the stream on screen. */
final readonly class MarkYourStreamReadRequest
{
    public function __construct(
        public \DateTimeImmutable $until,
    ) {
    }
}
