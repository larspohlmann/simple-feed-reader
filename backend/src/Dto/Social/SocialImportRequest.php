<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportRequest
{
    private const int MAX_COLLECTIONS = 100;

    /**
     * @param list<SocialImportFeedItem>       $subscribe
     * @param list<int>                        $unsubscribe
     * @param list<SocialImportCollectionItem> $collections
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(max: CollectedItemsModel::MAX_ITEMS)]
        public array $subscribe = [],
        #[Assert\Count(max: SubscriptionService::MAX_BULK_REQUEST_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $unsubscribe = [],
        #[Assert\Valid]
        #[Assert\Count(max: self::MAX_COLLECTIONS)]
        public array $collections = [],
    ) {
    }

    #[Assert\IsTrue(message: 'Choose at least one feed, list or custom feed to add, or one to unsubscribe from.')]
    public function hasSelection(): bool
    {
        return [] !== $this->subscribe || [] !== $this->unsubscribe || [] !== $this->collections;
    }

    public function toImport(): SocialImportModel
    {
        return new SocialImportModel(
            array_map(
                static fn (SocialImportFeedItem $item): SocialImportFeedModel => $item->toFeed(),
                $this->subscribe,
            ),
            $this->unsubscribe,
            array_map(
                static fn (SocialImportCollectionItem $item): PrivateFeedModel => $item->toPrivateFeed(),
                $this->collections,
            ),
        );
    }
}
