<?php

declare(strict_types=1);

namespace App\Service\Trending\StoryLinkSource;

use App\Service\Trending\Model\StoryLinkModel;
use App\Service\Trending\Pass\TrendingRun;

final readonly class SharedUrlLinks implements StoryLinkSourceInterface
{
    public static function key(): string
    {
        return 'shared_url';
    }

    public function links(TrendingRun $run): array
    {
        $links = [];
        foreach ($run->entriesSharingAUrl() as $entryIds) {
            foreach (self::pairsOf($entryIds) as [$oneEntryId, $otherEntryId]) {
                if ($run->mayLink($oneEntryId, $otherEntryId)) {
                    $link = StoryLinkModel::sameUrl($oneEntryId, $otherEntryId);
                    $links[$link->pairKey()] = $link;
                }
            }
        }

        return array_values($links);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<array{int, int}>
     */
    private static function pairsOf(array $entryIds): array
    {
        $pairs = [];
        foreach ($entryIds as $index => $oneEntryId) {
            foreach (\array_slice($entryIds, $index + 1) as $otherEntryId) {
                $pairs[] = [$oneEntryId, $otherEntryId];
            }
        }

        return $pairs;
    }
}
