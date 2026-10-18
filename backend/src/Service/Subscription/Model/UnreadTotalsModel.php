<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

final readonly class UnreadTotalsModel
{
    /**
     * @param array<int, int>    $tags            tag id => unread entries, 0 absent
     * @param array<string, int> $networks        network => unread entries, 0 absent
     * @param array<string, int> $networkProfiles network => unread entries of its profile feeds, 0 absent
     */
    public function __construct(
        public int $allItems,
        public array $tags,
        public array $networks,
        public array $networkProfiles,
    ) {
    }
}
