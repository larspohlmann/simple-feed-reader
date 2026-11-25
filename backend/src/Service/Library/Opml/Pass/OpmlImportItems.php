<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Pass;

use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Subscription\Model\BulkSubscribeItemModel;

final readonly class OpmlImportItems
{
    /** @var array<string, true> */
    private array $selectedUrls;

    /**
     * @param list<string> $feedUrls
     */
    public function __construct(array $feedUrls, private OpmlChosenTags $chosen)
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
        $replacing = $this->chosen->findReplacing($feed->url);

        return new BulkSubscribeItemModel(
            feedUrl: $feed->url,
            feedTitle: $feed->title,
            tagPath: null === $replacing ? $destination : [],
            sourceFormat: $feed->sourceFormat,
            chosenTags: $replacing ?? $this->chosen->findAdding($feed->url),
        );
    }
}
