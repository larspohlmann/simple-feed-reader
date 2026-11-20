<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryLinkSource;

use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'shared_url')]
final readonly class SharedUrlLinks implements StoryLinkSourceInterface
{
    public function links(TrendingRun $run): array
    {
        $links = [];
        foreach ($run->entriesSharingAUrlOrItem() as $entryIds) {
            foreach (self::buildLinks($run, $entryIds) as $link) {
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
    private static function buildLinks(TrendingRun $run, array $entryIds): array
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
