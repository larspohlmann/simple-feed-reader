<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Pass;

use App\Entity\Tag;
use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Subscription\Model\BulkSubscribeItemModel;

final readonly class OpmlImportItems
{
    /** @var array<string, true> */
    private array $selectedUrls;

    /**
     * @param list<string>              $feedUrls
     * @param array<string, list<Tag>> $chosenTagsByFeedUrl
     */
    public function __construct(array $feedUrls, private array $chosenTagsByFeedUrl)
    {
        $this->selectedUrls = array_fill_keys($feedUrls, true);
    }

    /** @return list<BulkSubscribeItemModel> */
    public function collect(OpmlPlacedFolderModel $folder): array
    {
        $items = [];
        foreach ($folder->feeds as $feed) {
            if (isset($this->selectedUrls[$feed->url])) {
                $items[] = $this->buildItem($feed, $folder->destination);
            }
        }
        foreach ($folder->folders as $child) {
            array_push($items, ...$this->collect($child));
        }

        return $items;
    }

    /** @param list<string> $destination */
    private function buildItem(OpmlFeedModel $feed, array $destination): BulkSubscribeItemModel
    {
        $isChosen = array_key_exists($feed->url, $this->chosenTagsByFeedUrl);

        return new BulkSubscribeItemModel(
            feedUrl: $feed->url,
            feedTitle: $feed->title,
            tagPath: $isChosen ? [] : $destination,
            sourceFormat: $feed->sourceFormat,
            chosenTags: $this->chosenTagsByFeedUrl[$feed->url] ?? [],
        );
    }
}
