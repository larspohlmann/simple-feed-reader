<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportRequest
{
    /**
     * @param list<SocialImportFeedItem> $subscribe
     * @param list<int>                  $unsubscribe
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(max: CollectedItemsModel::MAX_ITEMS)]
        public array $subscribe = [],
        #[Assert\Count(max: SubscriptionService::MAX_BULK_REQUEST_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $unsubscribe = [],
    ) {
    }

    #[Assert\IsTrue(message: 'Choose at least one feed to subscribe to or unsubscribe from.')]
    public function hasSelection(): bool
    {
        return [] !== $this->subscribe || [] !== $this->unsubscribe;
    }

    public function toImport(): SocialImportModel
    {
        return new SocialImportModel(
            array_map(
                static fn (SocialImportFeedItem $item): SocialImportFeedModel => $item->toFeed(),
                $this->subscribe,
            ),
            $this->unsubscribe,
        );
    }
}
