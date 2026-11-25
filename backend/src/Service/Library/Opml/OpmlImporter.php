<?php

declare(strict_types=1);

namespace App\Service\Library\Opml;

use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\TagRepository;
use App\Service\Library\Opml\Model\OpmlImportResultModel;
use App\Service\Library\Opml\Model\OpmlImportSelectionModel;
use App\Service\Library\Opml\Model\OpmlPlacementChoiceModel;
use App\Service\Library\Opml\Pass\OpmlImportItems;
use App\Service\Library\Subscription\BulkSubscriber;
use App\Service\Library\Subscription\Model\BulkSubscribeItemModel;

/** Subscribes to the selected feeds of an OPML file WITHOUT fetching anything, under their planned or chosen tags. */
final readonly class OpmlImporter
{
    public function __construct(
        private OpmlDocumentReader $reader,
        private OpmlPlacementPlanner $planner,
        private TagRepository $tags,
        private BulkSubscriber $subscriber,
    ) {
    }

    public function import(User $user, OpmlImportSelectionModel $selection): OpmlImportResultModel
    {
        $choice = new OpmlPlacementChoiceModel($selection->parentTagName);
        $root = $this->planner->plan($user, $this->reader->read($selection->opml), $choice);
        $chosenTags = $this->findChosenTags($user, $selection->tagIdsByFeedUrl);
        $items = new OpmlImportItems($selection->feedUrls, $chosenTags)->collect($root);
        $permitted = $this->withoutScraped($items);
        $result = $this->subscriber->subscribeAllInPlace($user, $permitted);

        return new OpmlImportResultModel(
            imported: $result->imported,
            alreadySubscribed: $result->alreadySubscribed,
            invalid: $result->invalid + \count($items) - \count($permitted),
            skippedOverLimit: $result->skippedOverLimit,
        );
    }

    /**
     * @param array<string, list<int>> $tagIdsByFeedUrl
     *
     * @return array<string, list<Tag>> only the account's own tags
     */
    private function findChosenTags(User $user, array $tagIdsByFeedUrl): array
    {
        $tagIds = array_values(array_unique(array_merge(...array_values($tagIdsByFeedUrl))));
        $tagsById = [];
        foreach ($this->tags->findAllByIdsForUser($user->requireId(), $tagIds) as $tag) {
            $tagsById[$tag->requireId()] = $tag;
        }

        return array_map(
            static fn (array $feedTagIds): array => array_values(
                array_intersect_key($tagsById, array_flip($feedTagIds)),
            ),
            $tagIdsByFeedUrl,
        );
    }

    /**
     * @param list<BulkSubscribeItemModel> $items
     *
     * @return list<BulkSubscribeItemModel>
     */
    private function withoutScraped(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (BulkSubscribeItemModel $item): bool => $item->sourceFormat !== SourceFormat::SCRAPED,
        ));
    }
}
