<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

/** An id standing as a keyed value on the page, with the keys on either side — the context that tells one list from another. */
final readonly class KeyedOccurrence
{
    public function __construct(
        public string $key,
        public string $previousKey,
        public string $nextKey,
        public int $position,
    ) {
    }

    public function sharesContextWith(self $other): bool
    {
        return $this->key === $other->key
            && $this->previousKey === $other->previousKey
            && $this->nextKey === $other->nextKey;
    }
}
