<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Service\Curation\Trending\Model\StoryLinkModel;

final readonly class LinkedPairs
{
    /** @param array<int, array<int, true>> $linked entry id => the entry ids it links to */
    private function __construct(private array $linked)
    {
    }

    /** @param list<StoryLinkModel> $links */
    public static function fromLinks(array $links): self
    {
        $linked = [];
        foreach ($links as $link) {
            $linked[$link->firstEntryId][$link->secondEntryId] = true;
            $linked[$link->secondEntryId][$link->firstEntryId] = true;
        }

        return new self($linked);
    }

    /**
     * @param list<int> $entryIds
     * @param list<int> $otherEntryIds
     */
    public function countBetween(array $entryIds, array $otherEntryIds): int
    {
        $others = array_flip($otherEntryIds);
        $count = 0;
        foreach ($entryIds as $entryId) {
            $count += \count(array_intersect_key($this->linked[$entryId] ?? [], $others));
        }

        return $count;
    }
}
