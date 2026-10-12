<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

/** A named set of the user's feeds refreshed together, such as a network's; the key keeps its run apart. */
final readonly class RefreshFeedSetModel
{
    /** @param list<int> $feedIds */
    public function __construct(
        public string $key,
        public array $feedIds,
    ) {
    }
}
