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
            foreach (self::linksAmong($run, $entryIds) as $link) {
                $links[$link->pairKey()] = $link;
            }
        }

        return array_values($links);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<StoryLinkModel>
     */
    private static function linksAmong(TrendingRun $run, array $entryIds): array
    {
        $links = [];
        foreach ($entryIds as $index => $oneEntryId) {
            foreach (\array_slice($entryIds, $index + 1) as $otherEntryId) {
                if ($run->mayLink($oneEntryId, $otherEntryId)) {
                    $links[] = StoryLinkModel::sameUrl($oneEntryId, $otherEntryId);
                }
            }
        }

        return $links;
    }
}
